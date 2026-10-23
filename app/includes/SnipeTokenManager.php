<?php

declare(strict_types=1);

require_once __DIR__ . '/SnipeHttp.php';
require_once __DIR__ . '/SnipeItClient.php';
require_once __DIR__ . '/snipe_response.php';

/**
 * SnipeTokenManager
 *
 * Manages per-user Snipe-IT OAuth tokens:
 *  - AES-256-GCM encryption at rest, keyed from APP_SECRET via HKDF and bound to
 *    the user row (a ciphertext copied onto another user's row will not decrypt)
 *  - Persistent storage in users.snipe_access_token / snipe_refresh_token. The
 *    token is never copied into the PHP session; the database is the only copy.
 *  - Silent refresh using the stored refresh token, serialised per user so two
 *    concurrent requests cannot both spend a single-use refresh token
 *  - A local ceiling on access-token lifetime (SNIPE_TOKEN_MAX_AGE), so a
 *    token is rotated regularly instead of living as long as Snipe-IT allows
 *  - Permission caching from Snipe-IT /users/me
 *
 * Usage:
 *   $token = SnipeTokenManager::getValid($userId);   // null = not connected / refresh failed
 *   SnipeTokenManager::store($userId, $tokenData, $permissions);
 *   SnipeTokenManager::revoke($userId);
 */
class SnipeTokenManager
{
    // Buffer before expiry at which we proactively refresh (seconds)
    private const REFRESH_BUFFER = 300; // 5 minutes

    // How long cached Snipe-IT permissions are trusted before being re-read (seconds)
    private const PERMISSIONS_TTL = 600;
    private const PERMISSIONS_RETRY = 60; // after a failed re-read, wait this long before asking Snipe-IT again

    // Default ceiling on how long we keep using one access token (seconds)
    private const DEFAULT_MAX_AGE = 86400;

    // Seconds to wait for another request's in-flight refresh of the same user
    private const REFRESH_LOCK_WAIT = 10;

    // Prefix of the current ciphertext format; anything without it is the legacy AES-CBC format
    private const FORMAT_GCM = 'v2:';
    private const HKDF_INFO = 'quacktrack/snipe-token/v2';
    private const MIN_SECRET_LENGTH = 32;
    private const PLACEHOLDER_SECRETS = ['changeme', 'change_this_to_a_random_long_string', 'fallback-insecure-key'];

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Return a valid access token for the user, silently refreshing if needed.
     * Returns null when the user has no linked account or refresh fails.
     */
    public static function getValid(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        // Older sessions kept a plaintext copy of the token; make sure none survives.
        unset($_SESSION['snipe_access_token'], $_SESSION['snipe_token_expires']);

        try {
            $row = self::loadRow($userId);
            if ($row === null) {
                return null; // No linked account
            }
            $fresh = self::usableAccessToken($userId, $row);
            if ($fresh !== null) {
                return $fresh;
            }
            if ((string)$row['snipe_refresh_token'] === '') {
                return null; // No refresh token, must re-auth
            }
            return self::refreshSerialised($userId);
        } catch (Throwable $e) {
            Logger::error('snipe_token_manager_get', $e, ['user_id' => $userId]);
            return null;
        }
    }

    /**
     * Persist a token set (and optionally permission data) for a user.
     * Encrypts access and refresh tokens before writing to the database.
     *
     * @throws RuntimeException when the token cannot be encrypted or saved
     */
    public static function store(int $userId, array $tokenData, ?array $permissions = null): void
    {
        $accessToken  = (string)($tokenData['access_token'] ?? '');
        $refreshToken = (string)($tokenData['refresh_token'] ?? '');
        if ($userId <= 0 || $accessToken === '') {
            throw new InvalidArgumentException('A user and an access token are required to store a Snipe-IT token.');
        }
        $expiresAt = time() + self::boundedLifetime((int)($tokenData['expires_in'] ?? 3600));

        try {
            $stmt = db()->prepare(
                'UPDATE users
                    SET snipe_access_token  = ?,
                        snipe_refresh_token = COALESCE(?, snipe_refresh_token),
                        snipe_token_expires = ?,
                        snipe_permissions   = COALESCE(?, snipe_permissions)
                  WHERE id = ?'
            );
            $stmt->execute([
                self::encrypt($accessToken, self::aad($userId, 'access')),
                $refreshToken !== '' ? self::encrypt($refreshToken, self::aad($userId, 'refresh')) : null,
                $expiresAt,
                $permissions !== null ? json_encode($permissions) : null,
                $userId,
            ]);
        } catch (Throwable $e) {
            Logger::error('snipe_token_manager_store', $e, ['user_id' => $userId]);
            throw new RuntimeException('Could not store the Snipe-IT token.', 0, $e);
        }

        if ($permissions !== null) {
            self::cachePermissionsInSession($permissions);
        }
    }

    /**
     * Store only the permission set (called after /users/me fetch).
     */
    public static function storePermissions(int $userId, array $permissions): void
    {
        try {
            $stmt = db()->prepare('UPDATE users SET snipe_permissions = ? WHERE id = ?');
            $stmt->execute([json_encode($permissions), $userId]);
        } catch (Throwable $e) {
            Logger::error('snipe_token_manager_perms', $e, ['user_id' => $userId]);
        }
        self::cachePermissionsInSession($permissions);
    }

    /**
     * Return cached Snipe-IT permissions for the user.
     * Falls back to the database if the session has none.
     */
    public static function getPermissions(int $userId): array
    {
        if (!empty($_SESSION['snipe_permissions']) && is_array($_SESSION['snipe_permissions'])) {
            return $_SESSION['snipe_permissions'];
        }

        try {
            $stmt = db()->prepare('SELECT snipe_permissions FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $json = $stmt->fetchColumn();
            $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                self::cachePermissionsInSession($decoded);
                return $decoded;
            }
        } catch (Throwable $e) {
            Logger::error('snipe_token_manager_perms_get', $e, ['user_id' => $userId]);
        }

        return [];
    }

    /**
     * Permissions as Snipe-IT reports them now. Re-read at most every PERMISSIONS_TTL
     * seconds, so a right taken away in Snipe-IT stops showing in Quack Track's UI
     * soon after. Falls back to the last known set if Snipe-IT cannot be reached.
     */
    public static function getFreshPermissions(int $userId, string $accessToken): array
    {
        $checkedAt = (int)($_SESSION['snipe_permissions_checked_at'] ?? 0);
        if (!empty($_SESSION['snipe_permissions']) && (time() - $checkedAt) < self::PERMISSIONS_TTL) {
            return $_SESSION['snipe_permissions'];
        }

        $me = SnipeItClient::forToken($accessToken, $userId)->getCurrentUser();
        $parsed = $me !== null ? snipe_parse_current_user($me) : null;
        if ($parsed === null) {
            // Snipe-IT is slow or down. Keep the last known set and back off, so each page
            // view does not wait out the request timeout again.
            $known = self::getPermissions($userId);
            $_SESSION['snipe_permissions'] = $known;
            $_SESSION['snipe_permissions_checked_at'] = time() - self::PERMISSIONS_TTL + self::PERMISSIONS_RETRY;
            return $known;
        }
        self::storePermissions($userId, $parsed['permissions']);
        return $parsed['permissions'];
    }

    /**
     * Remove all stored Snipe-IT token data for a user (forces re-auth).
     *
     * This only forgets the token on our side; Snipe-IT offers no API for a client
     * to revoke its own token. The token's remaining life is bounded by
     * SNIPE_TOKEN_MAX_AGE and by Snipe-IT's own expiry settings.
     *
     * @return bool false when clearing the stored token could not be confirmed; the copies in the
     *              session are removed either way
     */
    public static function revoke(int $userId): bool
    {
        $cleared = self::clearTokensIf($userId, static fn(): bool => true);
        self::forgetSessionCopies();
        return $cleared;
    }

    /**
     * Forget the stored token only while it is still $rejectedToken, the one Snipe-IT just refused. A request that
     * used an older token cannot wipe a token another request has meanwhile refreshed or relinked.
     *
     * @return bool true when the rejected token was the stored one and it is now cleared
     */
    public static function revokeIfCurrent(int $userId, string $rejectedToken): bool
    {
        $matched = false;
        $cleared = self::clearTokensIf($userId, static function () use ($userId, $rejectedToken, &$matched): bool {
            $row = self::loadRow($userId);
            $current = $row === null ? '' : self::open((string)$row['snipe_access_token'], self::aad($userId, 'access'))[0];
            $matched = $current !== '' && hash_equals($current, $rejectedToken);
            return $matched;
        });
        if ($matched) {
            self::forgetSessionCopies();
        }
        return $matched && $cleared;
    }

    /**
     * Under the user's token lock, clear every stored token column when $shouldClear() says so.
     *
     * @param callable(): bool $shouldClear
     * @return bool false when clearing could not be confirmed
     */
    private static function clearTokensIf(int $userId, callable $shouldClear): bool
    {
        try {
            $pdo = db();
            $lockName = 'quacktrack_snipe_refresh_' . $userId;
            $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
            $lock->execute([$lockName, self::REFRESH_LOCK_WAIT]);
            if ((int)$lock->fetchColumn() !== 1) {
                throw new RuntimeException('Could not acquire the Snipe token lock.');
            }
            try {
                if ($shouldClear()) {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                            SET snipe_access_token  = NULL,
                                snipe_refresh_token = NULL,
                                snipe_token_expires = NULL,
                                snipe_permissions   = NULL
                          WHERE id = ?'
                    );
                    $stmt->execute([$userId]);
                }
            } finally {
                try {
                    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
                } catch (Throwable $releaseError) {
                    Logger::error('snipe_token_manager_revoke_unlock', $releaseError, ['user_id' => $userId]);
                }
            }
            return true;
        } catch (Throwable $e) {
            Logger::error('snipe_token_manager_revoke', $e, ['user_id' => $userId]);
            return false;
        }
    }

    /** Remove the token copies older sessions kept. */
    private static function forgetSessionCopies(): void
    {
        unset(
            $_SESSION['snipe_access_token'],
            $_SESSION['snipe_token_expires'],
            $_SESSION['snipe_permissions'],
            $_SESSION['snipe_permissions_checked_at']
        );
    }

    // -------------------------------------------------------------------------
    // Token refresh
    // -------------------------------------------------------------------------

    /**
     * Exchange a refresh token for a new access token via Snipe-IT OAuth.
     *
     * @return array{token: ?array, rejected: bool} `rejected` is true only when Snipe-IT
     *         definitively refused the refresh token (so it is useless and should be dropped);
     *         a network error or 5xx is not a rejection and must not log the user out.
     */
    public static function refresh(string $refreshToken): array
    {
        $base = rtrim(env('SNIPE_IT_OAUTH_BASE_URL', ''), '/');
        if ($base === '') {
            return ['token' => null, 'rejected' => false]; // OAuth not configured
        }

        $response = SnipeHttp::send(
            'POST',
            $base . '/oauth/token',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'User-Agent: Quacktrack/2.0',
            ],
            http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => env('SNIPE_IT_OAUTH_CLIENT_ID', ''),
                'client_secret' => env('SNIPE_IT_OAUTH_CLIENT_SECRET', ''),
            ]),
            (int)env('SNIPE_IT_API_TIMEOUT', '15')
        );

        $data = json_decode($response['body'], true);
        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($data) && !empty($data['access_token'])) {
            return ['token' => $data, 'rejected' => false];
        }

        Logger::info('snipe_token_refresh_failed', 'Refresh token exchange failed', [
            'http_status' => $response['status'],
            'oauth_error' => is_array($data) ? (string)($data['error'] ?? '') : '',
            'transport'   => $response['error'],
        ]);
        return ['token' => null, 'rejected' => in_array($response['status'], [400, 401], true)];
    }

    // -------------------------------------------------------------------------
    // Encryption
    // -------------------------------------------------------------------------

    /**
     * Encrypt with AES-256-GCM. $aad is authenticated but not stored, so a ciphertext
     * only decrypts in the context (user and field) it was made for.
     */
    public static function encrypt(string $plaintext, string $aad = ''): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt($plaintext, 'aes-256-gcm', self::currentKey(), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($enc === false) {
            throw new RuntimeException('Token encryption failed.');
        }
        return self::FORMAT_GCM . base64_encode($iv . $tag . $enc);
    }

    /**
     * Decrypt a stored token. Returns '' when it cannot be decrypted or authenticated
     * (tampered, wrong key, wrong user) so callers treat it as "not connected".
     *
     * Also reads the legacy unauthenticated AES-CBC format and values written under
     * APP_SECRET_PREVIOUS, so existing links survive an upgrade or a secret rotation.
     */
    public static function decrypt(string $stored, string $aad = ''): string
    {
        return self::open($stored, $aad)[0];
    }

    /**
     * True when a value is not yet in the current format under the current secret and
     * should be rewritten (legacy cipher, or encrypted with the previous secret).
     */
    public static function needsUpgrade(string $stored, string $aad = ''): bool
    {
        [$plain, $current] = self::open($stored, $aad);
        return $plain !== '' && !$current;
    }

    /**
     * @return array{0: string, 1: bool} the plaintext ('' on failure) and whether it was
     *         in the current format under the current secret
     */
    private static function open(string $stored, string $aad): array
    {
        $current = self::secret(); // a missing or weak current secret is a deployment fault: let it throw
        $previous = env('APP_SECRET_PREVIOUS', '');

        foreach ([[$current, true], [$previous, false]] as [$secret, $isCurrent]) {
            if ($secret === '') {
                continue;
            }
            try {
                if (str_starts_with($stored, self::FORMAT_GCM)) {
                    $plain = self::openGcm($stored, $aad, hash_hkdf('sha256', $secret, 32, self::HKDF_INFO));
                    $upToDate = $isCurrent;
                } else {
                    $plain = self::openLegacyCbc($stored, hash('sha256', $secret, true));
                    $upToDate = false;
                }
            } catch (Throwable) {
                continue;
            }
            if ($plain !== '') {
                return [$plain, $upToDate];
            }
        }
        return ['', false];
    }

    private static function openGcm(string $stored, string $aad, string $key): string
    {
        $raw = base64_decode(substr($stored, strlen(self::FORMAT_GCM)), true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        return $plain !== false ? $plain : '';
    }

    private static function openLegacyCbc(string $stored, string $key): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain !== false ? $plain : '';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * The application secret, refusing to run on a missing, placeholder or short one:
     * a guessable key makes the encryption decorative.
     */
    private static function secret(): string
    {
        $secret = env('APP_SECRET', '');
        if (strlen($secret) < self::MIN_SECRET_LENGTH || in_array(strtolower($secret), self::PLACEHOLDER_SECRETS, true)) {
            throw new RuntimeException(
                'APP_SECRET is missing or too weak to protect Snipe-IT tokens. Set it to at least ' . self::MIN_SECRET_LENGTH
                . ' random characters (for example: openssl rand -hex 32).'
            );
        }
        return $secret;
    }

    private static function currentKey(): string
    {
        return hash_hkdf('sha256', self::secret(), 32, self::HKDF_INFO);
    }

    private static function aad(int $userId, string $field): string
    {
        return 'snipe-token|' . $field . '|' . $userId;
    }

    private static function boundedLifetime(int $expiresIn): int
    {
        $expiresIn = max(60, $expiresIn);
        $maxAge = (int)env('SNIPE_TOKEN_MAX_AGE', (string)self::DEFAULT_MAX_AGE);
        if ($maxAge <= 0) {
            $maxAge = self::DEFAULT_MAX_AGE; // the cap cannot be switched off by a bad value
        }
        // A ceiling at or below the refresh buffer would make every token stale on arrival
        // and force a refresh on every request, so never go lower than that.
        $maxAge = max($maxAge, self::REFRESH_BUFFER + 60);
        return min($expiresIn, $maxAge);
    }

    /** @return array<string, mixed>|null the user's token columns, or null when no account is linked */
    private static function loadRow(int $userId): ?array
    {
        $stmt = db()->prepare(
            'SELECT snipe_access_token, snipe_refresh_token, snipe_token_expires FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['snipe_access_token'])) {
            return null;
        }
        $storedRefresh = (string)($row['snipe_refresh_token'] ?? '');
        $row['snipe_refresh_token'] = $storedRefresh !== ''
            ? self::decrypt($storedRefresh, self::aad($userId, 'refresh'))
            : '';
        return $row;
    }

    /**
     * The access token from a loaded row if it is still comfortably within its life.
     * A token in an old format, or under the previous secret, is rewritten as it is read.
     */
    private static function usableAccessToken(int $userId, array $row): ?string
    {
        $expires = (int)($row['snipe_token_expires'] ?? 0);
        if (time() >= $expires - self::REFRESH_BUFFER) {
            return null;
        }
        $stored = (string)$row['snipe_access_token'];
        [$token, $isCurrent] = self::open($stored, self::aad($userId, 'access'));
        if ($token === '') {
            return null;
        }
        if (!$isCurrent) {
            self::upgradeFormat($userId, $token, (string)$row['snipe_refresh_token'], $stored);
        }
        return $token;
    }

    private static function upgradeFormat(int $userId, string $accessToken, string $refreshToken, string $expectedStored): void
    {
        try {
            $stmt = db()->prepare('UPDATE users SET snipe_access_token = ?, snipe_refresh_token = ? WHERE id = ? AND snipe_access_token = ?');
            $stmt->execute([
                self::encrypt($accessToken, self::aad($userId, 'access')),
                $refreshToken !== '' ? self::encrypt($refreshToken, self::aad($userId, 'refresh')) : null,
                $userId,
                $expectedStored,
            ]);
        } catch (Throwable $e) {
            Logger::error('snipe_token_manager_upgrade', $e, ['user_id' => $userId]);
        }
    }

    /**
     * Refresh the user's token while holding a per-user lock. A refresh token can be
     * spent once, so when two requests find the token expired the second waits, then
     * re-reads the row and uses the token the first one saved instead of refreshing again.
     */
    private static function refreshSerialised(int $userId): ?string
    {
        $pdo = db();
        $lockName = 'quacktrack_snipe_refresh_' . $userId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([$lockName, self::REFRESH_LOCK_WAIT]);
        if ((int)$lock->fetchColumn() !== 1) {
            return null;
        }

        try {
            $row = self::loadRow($userId);
            if ($row === null) {
                return null;
            }
            $fresh = self::usableAccessToken($userId, $row);
            if ($fresh !== null) {
                return $fresh; // another request refreshed while we waited
            }
            if ((string)$row['snipe_refresh_token'] === '') {
                return null;
            }

            $result = self::refresh((string)$row['snipe_refresh_token']);
            if ($result['token'] === null) {
                if ($result['rejected']) {
                    self::revoke($userId); // the refresh token is dead; prompt re-auth
                }
                return null;
            }
            self::store($userId, $result['token']);
            return (string)$result['token']['access_token'];
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    private static function cachePermissionsInSession(array $permissions): void
    {
        $_SESSION['snipe_permissions'] = $permissions;
        $_SESSION['snipe_permissions_checked_at'] = time();
    }
}
