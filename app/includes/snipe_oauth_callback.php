<?php

/**
 * The Snipe-IT OAuth2 callback (api/snipe-oauth-callback.php): the second half of the flow started by
 * includes/snipe_oauth.php. Two intents were stored in the session at the start:
 *
 *  'login' (default) - full SSO: find or create the Quack Track user and start a session.
 *  'link'            - the user is already signed in; store their personal Snipe-IT token so write actions on the
 *                      Assets page are attributed to them in Snipe-IT's audit log.
 *
 * Every answer is a redirect with a flash message, never JSON. A failed link must not sign the person out.
 */

declare(strict_types=1);

require_once __DIR__ . '/SnipeHttp.php';
require_once __DIR__ . '/SnipeItClient.php';
require_once __DIR__ . '/LocationMapper.php';
require_once __DIR__ . '/SnipeTokenManager.php';
require_once __DIR__ . '/snipe_oauth.php';
require_once __DIR__ . '/snipe_response.php';

/** A failure whose message is safe to show the user as-is. */
final class SnipeOAuthUserError extends RuntimeException
{
}

/**
 * End the request with a redirect.
 */
function snipe_oauth_redirect(string $to): never
{
    http_response_code(302);
    header('Location: ' . $to);
    // arch-ok: exit-in-library - this is the response boundary: the redirect has been sent
    exit;
}

/**
 * Read and clear everything the start of the flow stored, whatever happens next.
 *
 * @return array{state: mixed, intent: string, return_url: string, verifier: string}
 */
function snipe_oauth_take_flow(): array
{
    $flow = [
        'state' => $_SESSION['oauth_state'] ?? null,
        'intent' => ($_SESSION['oauth_intent'] ?? 'login') === 'link' ? 'link' : 'login',
        'return_url' => snipe_oauth_safe_return_path((string)($_SESSION['oauth_return_url'] ?? '/')),
        'verifier' => (string)($_SESSION['oauth_pkce_verifier'] ?? ''),
    ];
    unset($_SESSION['oauth_state'], $_SESSION['oauth_intent'], $_SESSION['oauth_return_url'], $_SESSION['oauth_pkce_verifier']);
    return $flow;
}

/**
 * The error, code and state the provider sent back; each must be text when present.
 *
 * @return array{error: ?string, code: ?string, state: ?string}
 * @throws SnipeOAuthUserError a value that is not text
 */
function snipe_oauth_query(): array
{
    $query = ['error' => null, 'code' => null, 'state' => null];
    foreach (array_keys($query) as $key) {
        $value = $_GET[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new SnipeOAuthUserError('Snipe-IT could not complete the sign-in.');
        }
        $query[$key] = $value;
    }
    return $query;
}

/**
 * The answer to a refused or malformed return from Snipe-IT.
 *
 * @param array{error: ?string, code: ?string, state: ?string} $query
 * @param array{state: mixed, intent: string, return_url: string, verifier: string} $flow
 * @throws SnipeOAuthUserError|RuntimeException
 */
function snipe_oauth_check_query(array $query, array $flow): void
{
    // Snipe-IT sends the user back with ?error=access_denied when they decline.
    if ($query['error'] !== null) {
        $declined = $query['error'] === 'access_denied';
        Logger::info('snipe_oauth', 'Authorization was not granted', ['oauth_error' => substr((string)preg_replace('/[^A-Za-z0-9_.-]/', '', $query['error']), 0, 64)]);
        throw new SnipeOAuthUserError($declined ? 'Snipe-IT sign-in was cancelled.' : 'Snipe-IT could not complete the sign-in.');
    }
    if ($query['code'] === null || $query['code'] === '' || strlen($query['code']) > 4096) {
        throw new RuntimeException('Missing authorization code');
    }
    $sessionState = $flow['state'];
    if ($query['state'] === null || !is_string($sessionState) || $sessionState === '' || !hash_equals($sessionState, $query['state'])) {
        Logger::security('oauth_state_invalid', ['intent' => $flow['intent']]);
        throw new RuntimeException('Invalid OAuth state — possible CSRF attack');
    }
}

/**
 * Handle the callback; every outcome ends the request with a redirect.
 */
function snipe_oauth_callback(): never
{
    $intent = 'login';
    try {
        if (env('SNIPE_IT_OAUTH_ENABLED', '0') !== '1') {
            throw new RuntimeException('Snipe-IT OAuth is not enabled');
        }
        $flow = snipe_oauth_take_flow();
        $intent = $flow['intent'];
        if ($intent === 'link' && !validate_session()) {
            destroy_session();
            throw new SnipeOAuthUserError('Session expired during linking. Please log in and try again.');
        }
        $query = snipe_oauth_query();
        snipe_oauth_check_query($query, $flow);
        $snipe = snipe_oauth_fetch_identity((string)$query['code'], $flow);
        if ($intent === 'link') {
            snipe_oauth_link((int)($_SESSION['uid'] ?? 0), $snipe['user'], $snipe['token']);
            flash('Snipe-IT account connected successfully. Your actions will now be tracked as you.', 'success');
            snipe_oauth_redirect($flow['return_url']);
        }
        snipe_oauth_login($snipe['user'], $snipe['token']);
        snipe_oauth_redirect('/');
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::error('snipe_oauth', $e, ['intent' => $intent]);
        snipe_oauth_fail($e, $intent);
    }
}

/**
 * Report a failed flow: a failed link goes back to the assets page and keeps the Quack Track login.
 */
function snipe_oauth_fail(Throwable $e, string $intent): never
{
    $safe = $e instanceof SnipeOAuthUserError ? $e->getMessage() : null;
    if ($intent === 'link' && (int)($_SESSION['uid'] ?? 0) > 0) {
        flash($safe ?? 'Could not connect your Snipe-IT account. Please try again.', 'error');
        snipe_oauth_redirect('/assets');
    }
    flash($safe ?? 'Snipe-IT login failed. Please try again.', 'error');
    snipe_oauth_redirect('/login');
}

/**
 * Exchange the code for a token and read the profile and permissions it belongs to (one /users/me call).
 *
 * @param array{state: mixed, intent: string, return_url: string, verifier: string} $flow
 * @return array{token: array<string, mixed>, user: array<string, mixed>}
 * @throws RuntimeException
 */
function snipe_oauth_fetch_identity(string $code, array $flow): array
{
    $tokenData = snipe_oauth_exchange_code($code, $flow['verifier']);
    if (!snipe_oauth_valid_tokens($tokenData)) {
        throw new RuntimeException('Failed to obtain access token from Snipe-IT');
    }
    // When linking, the Quack Track user is already known, so the call can be attributed in the sync log.
    $forUser = $flow['intent'] === 'link' ? (int)($_SESSION['uid'] ?? 0) : 0;
    $me = SnipeItClient::forToken((string)$tokenData['access_token'], $forUser)->getCurrentUser();
    $snipeUser = $me !== null ? snipe_parse_current_user($me) : null;
    if ($snipeUser === null) {
        throw new RuntimeException('Failed to fetch user profile from Snipe-IT');
    }
    return ['token' => $tokenData, 'user' => $snipeUser];
}

/**
 * @param array<string, mixed>|null $tokens
 * @phpstan-assert-if-true array<string, mixed> $tokens
 */
function snipe_oauth_valid_tokens(?array $tokens): bool
{
    return is_string($tokens['access_token'] ?? null)
        && $tokens['access_token'] !== ''
        && (!isset($tokens['refresh_token']) || is_string($tokens['refresh_token']));
}

/**
 * Exchange the authorization code for a token set.
 *
 * @return array<string, mixed>|null null when Snipe-IT did not hand one out
 */
function snipe_oauth_exchange_code(string $code, string $pkceVerifier): ?array
{
    $post = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => env('SNIPE_IT_OAUTH_CLIENT_ID'),
        'client_secret' => env('SNIPE_IT_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('SNIPE_IT_OAUTH_REDIRECT_URI'),
    ];
    if ($pkceVerifier !== '') {
        $post['code_verifier'] = $pkceVerifier;
    }
    $response = SnipeHttp::send(
        'POST',
        rtrim(env('SNIPE_IT_OAUTH_BASE_URL'), '/') . '/oauth/token',
        ['User-Agent: Quacktrack/2.0', 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query($post),
        (int)env('SNIPE_IT_API_TIMEOUT', '15')
    );
    $data = json_decode($response['body'], true);
    if ($response['error'] !== null || $response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
        // Log the protocol-level reason only, never the body: a token response is a credential.
        Logger::info('snipe_oauth_token', 'Token exchange failed', [
            'http_status' => $response['status'],
            'oauth_error' => is_array($data) && is_string($data['error'] ?? null) ? substr((string)preg_replace('/[^A-Za-z0-9_.-]/', '', $data['error']), 0, 64) : '',
            'transport' => $response['error'],
        ]);
        return null;
    }
    return $data;
}

/**
 * Run $work in a transaction; any failure rolls it back.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function snipe_oauth_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Link the Snipe-IT account to the signed-in user: claim the Snipe-IT identity and store the token together, so a
 * refused or failed link leaves nothing behind.
 *
 * @param array<string, mixed> $snipeUser
 * @param array<string, mixed> $tokenData
 * @throws SnipeOAuthUserError
 */
function snipe_oauth_link(int $qtUserId, array $snipeUser, array $tokenData): void
{
    if ($qtUserId <= 0) {
        throw new SnipeOAuthUserError('Session expired during linking. Please log in and try again.');
    }
    snipe_oauth_transaction(db(), static function () use ($qtUserId, $snipeUser, $tokenData): void {
        snipe_oauth_claim_identity($qtUserId, $snipeUser);
        SnipeTokenManager::store($qtUserId, $tokenData, $snipeUser['permissions']);
    });
    // The session carries the identity pages filter by (the reports read it), as a Snipe-IT sign-in sets it.
    $_SESSION = array_merge($_SESSION, [
        'snipe_user_id' => $snipeUser['id'], 'snipe_department' => $snipeUser['department'], 'snipe_company' => $snipeUser['company'],
    ]);
    Logger::info('snipe_link', 'User linked Snipe-IT account', ['qt_user_id' => $qtUserId, 'snipe_user_id' => $snipeUser['id']]);
}

/**
 * Record the Snipe-IT identity on the user. One Snipe-IT account can belong to one Quack Track user.
 *
 * @param array<string, mixed> $snipeUser
 * @throws SnipeOAuthUserError
 */
function snipe_oauth_claim_identity(int $qtUserId, array $snipeUser): void
{
    try {
        db()->prepare(
            'UPDATE users SET snipe_user_id = ?, snipe_department = ?, snipe_company = ?, snipe_location_id = ? WHERE id = ?'
        )->execute([$snipeUser['id'], $snipeUser['department'] ?: null, $snipeUser['company'] ?: null, $snipeUser['location_id'] ?: null, $qtUserId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            Logger::security('snipe_link_conflict', ['qt_user_id' => $qtUserId, 'snipe_user_id' => $snipeUser['id']]);
            throw new SnipeOAuthUserError('This Snipe-IT account is already linked to another Quack Track user. Please contact your administrator.');
        }
        throw $e;
    }
}

/**
 * Sign in (creating the account if needed) with the Snipe-IT identity. The account, its Snipe-IT data and the stored
 * token are written together; the session starts only once they are saved.
 *
 * @param array<string, mixed> $snipeUser
 * @param array<string, mixed> $tokenData
 */
function snipe_oauth_login(array $snipeUser, array $tokenData): void
{
    if (!snipe_sso_login_enabled()) {
        throw new SnipeOAuthUserError('Please use company sign-in.');
    }
    $pdo = db();
    [$userId, $created] = snipe_oauth_transaction($pdo, static function () use ($pdo, $snipeUser, $tokenData): array {
        [$qtUserId, $created] = snipe_oauth_resolve_user($snipeUser, $pdo);
        $method = $pdo->prepare('SELECT login_method FROM users WHERE id = ? FOR UPDATE');
        $method->execute([$qtUserId]);
        if ($method->fetchColumn() === 'oidc') {
            throw new SnipeOAuthUserError('This account requires company sign-in.');
        }
        snipe_oauth_update_user_data($qtUserId, $snipeUser, $pdo);
        // Persist the token to the database (it survives logout).
        SnipeTokenManager::store($qtUserId, $tokenData, $snipeUser['permissions']);
        return [$qtUserId, $created];
    });
    // Only now is the account certain to exist: a rolled-back sign-in must not leave a "created" audit record.
    if ($created) {
        Logger::security('snipe_sso_user_created', ['qt_user_id' => $userId, 'role' => 'guest']);
    }
    snipe_oauth_create_session($userId, $snipeUser, $pdo);
}

/**
 * The id of the Quack Track user for a Snipe-IT identity, creating the account if needed, and whether it was created.
 *
 * Identity is the Snipe-IT user id. An email collision never links an existing local account; that requires
 * an authenticated account-link flow. This prevents an unverified registrant from pre-claiming an SSO identity.
 *
 * @param array<string, mixed> $snipeUser
 * @return array{0: int, 1: bool}
 * @throws SnipeOAuthUserError when the identity cannot be matched safely
 */
function snipe_oauth_resolve_user(array $snipeUser, PDO $pdo): array
{
    if ($snipeUser['email'] === '' || !filter_var($snipeUser['email'], FILTER_VALIDATE_EMAIL)) {
        Logger::info('snipe_oauth', 'Snipe-IT user has no usable email address', ['snipe_user_id' => $snipeUser['id']]);
        throw new SnipeOAuthUserError('Your Snipe-IT account needs a valid email address before you can sign in here.');
    }
    $byId = snipe_oauth_find_user($pdo, 'snipe_user_id', (string)$snipeUser['id']);
    if ($byId !== null) {
        snipe_oauth_require_same_identity($byId, $snipeUser);
        return [(int)$byId['id'], false];
    }
    $byEmail = snipe_oauth_find_user($pdo, 'email', $snipeUser['email']);
    if ($byEmail !== null) {
        snipe_oauth_require_same_identity($byEmail, $snipeUser);
        throw new SnipeOAuthUserError('Sign in to your existing account and connect Snipe-IT from Assets, or contact your administrator.');
    }
    return snipe_oauth_insert_or_find($snipeUser, $pdo);
}

/**
 * Insert a new identity, or use the same identity committed by a concurrent first sign-in.
 *
 * @param array<string, mixed> $snipeUser
 * @return array{0: int, 1: bool}
 */
function snipe_oauth_insert_or_find(array $snipeUser, PDO $pdo): array
{
    try {
        return [snipe_oauth_create_user($snipeUser, $pdo), true];
    } catch (PDOException $e) {
        // Another sign-in with the same email created the account a moment ago: use it. A plain read would use this
        // transaction's older snapshot and miss that row, so read the committed one.
        $created = $e->getCode() === '23000' ? snipe_oauth_find_committed_email($pdo, $snipeUser['email']) : null;
        if ($created === null) {
            throw $e;
        }
        snipe_oauth_require_same_identity($created, $snipeUser);
        if (empty($created['snipe_user_id'])) {
            throw new SnipeOAuthUserError('This email already belongs to an account. Contact your administrator to link it.');
        }
        return [(int)$created['id'], false];
    }
}

/**
 * The committed user with this email, read with an exclusive lock so it is seen even after this transaction's snapshot.
 *
 * @return array<string, mixed>|null
 */
function snipe_oauth_find_committed_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, role, snipe_user_id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user === false ? null : $user;
}

/**
 * The user whose $column equals $value, or null.
 *
 * @param 'snipe_user_id'|'email' $column
 * @return array<string, mixed>|null
 */
function snipe_oauth_find_user(PDO $pdo, string $column, string $value): ?array
{
    $sql = $column === 'email'
        ? 'SELECT id, email, first_name, last_name, role, snipe_user_id FROM users WHERE email = ? LIMIT 1'
        : 'SELECT id, email, first_name, last_name, role, snipe_user_id FROM users WHERE snipe_user_id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$value]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        return null;
    }
    // Lock only an existing row (not a missing identity's gap), and re-read its committed identity before claiming it.
    $locked = $pdo->prepare('SELECT id, email, first_name, last_name, role, snipe_user_id FROM users WHERE id = ? FOR UPDATE');
    $locked->execute([$user['id']]);
    $current = $locked->fetch(PDO::FETCH_ASSOC);
    return $current === false ? null : $current;
}

/**
 * An account already tied to another Snipe-IT user is never taken over because the emails match.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $snipeUser
 * @throws SnipeOAuthUserError
 */
function snipe_oauth_require_same_identity(array $user, array $snipeUser): void
{
    if (!empty($user['snipe_user_id']) && (int)$user['snipe_user_id'] !== $snipeUser['id']) {
        Logger::security('snipe_sso_identity_mismatch', [
            'qt_user_id' => $user['id'], 'linked_snipe_id' => (int)$user['snipe_user_id'], 'incoming_snipe_id' => $snipeUser['id'],
        ]);
        throw new SnipeOAuthUserError('This email address belongs to a different Snipe-IT account. Please contact your administrator.');
    }
}

/**
 * Create the account of a first-time Snipe-IT sign-in; returns its id.
 *
 * @param array<string, mixed> $snipeUser
 */
function snipe_oauth_create_user(array $snipeUser, PDO $pdo): int
{
    if (env('SNIPE_IT_SSO_AUTO_PROVISION', env('APP_ENV') === 'dev' ? '1' : '0') !== '1') {
        throw new SnipeOAuthUserError('Your account must be provisioned by an administrator before you can sign in.');
    }
    $role = 'guest';
    $pdo->prepare('INSERT INTO users (email, first_name, last_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([
        $snipeUser['email'], $snipeUser['first_name'], $snipeUser['last_name'],
        // SSO accounts get an unguessable password nobody knows, so password login stays closed to them.
        password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID),
        $role,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Record the person's Snipe-IT identity, department and home region at sign-in.
 *
 * New SSO accounts start as guests. Department data never grants privileges; roles belong to Quack Track.
 *
 * @param array<string, mixed> $snipeUser
 */
function snipe_oauth_update_user_data(int $userId, array $snipeUser, PDO $pdo): void
{
    LocationMapper::init($pdo);
    $regionId = $snipeUser['location_id'] > 0 ? LocationMapper::mapLocationToRegion($snipeUser['location_id'], 1) : 1;
    $pdo->prepare(
        'UPDATE users SET snipe_user_id = ?, snipe_department = ?, snipe_company = ?, snipe_location_id = ?, region_id = ?,
            session_version = session_version + (login_method <> ?), login_method = ? WHERE id = ?'
    )->execute([$snipeUser['id'], $snipeUser['department'] ?: null, $snipeUser['company'] ?: null, $snipeUser['location_id'] ?: null, $regionId, 'snipe_oauth', 'snipe_oauth', $userId]);
}

/**
 * Start the Quack Track session for an SSO user. Built from the database row (not from what Snipe-IT sent), with the
 * same fields secure_login() sets so both sign-in paths give identical sessions. The Snipe-IT token is deliberately
 * not put in the session.
 *
 * @param array<string, mixed> $snipeUser
 */
function snipe_oauth_create_session(int $userId, array $snipeUser, PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.first_name, u.last_name, u.username, u.role, u.region_id, u.session_version, r.name AS region_name
           FROM users u LEFT JOIN regions r ON u.region_id = r.id WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('User vanished during sign-in');
    }
    // New session id first, so nothing set below is ever attached to a pre-login session id.
    regenerate_session_secure();
    $_SESSION['session_version'] = (int)$user['session_version'];
    $_SESSION = array_merge($_SESSION, [
        'uid' => (int)$user['id'], 'email' => $user['email'], 'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
        'username' => $user['username'] ?? null,
        'display_name' => build_user_display_name($user['first_name'], $user['last_name'], $user['username'] ?? null, $user['email']),
        'region_id' => $user['region_id'], 'region' => $user['region_name'], 'role' => $user['role'], 'login_method' => 'snipe_oauth',
        'snipe_user_id' => $snipeUser['id'], 'snipe_department' => $snipeUser['department'], 'snipe_company' => $snipeUser['company'],
        'last_activity' => time(), 'last_regeneration' => time(), 'login_time' => time(), 'fingerprint' => generate_session_fingerprint(),
    ]);
    sync_user_role_assignment((int)$user['id'], $user['role']);
    reset_user_permission_cache();
    Logger::security('successful_login', ['user_id' => $user['id'], 'method' => 'snipe_oauth', 'role' => $user['role'], 'snipe_user_id' => $snipeUser['id']]);
}
