<?php

/**
 * State that makes api/checkout.php safe to retry and to double-click.
 *
 * The per-user lock is a database lock (GET_LOCK), so every app container that talks to the same
 * database shares it, including the old and the new colour of a blue/green release while both run.
 * The idempotency records and the attempt counters are small files under checkout_state_dir():
 * CHECKOUT_STATE_DIR when set, else a "checkout" folder inside the session save path (the volume
 * both release colours already share), else the system temp directory.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/checkout.php';

const CHECKOUT_RATE_LIMIT_DEFAULT = 5;
const CHECKOUT_RATE_LIMIT_WINDOW = 60;
const CHECKOUT_IDEMPOTENCY_TTL = 86400;
const CHECKOUT_LOCK_WAIT_DEFAULT = 10;
const CHECKOUT_STATE_DIR_MODE = 0700;
const CHECKOUT_LOCK_NAME_PREFIX = 'quacktrack_checkout_user_';

/** Letters, digits and . _ : ~ - : what a UUID or the browser's fallback key uses, and safe to log. */
const CHECKOUT_KEY_PATTERN = '/^[A-Za-z0-9._:~-]+$/D';

/** 429: too many checkout attempts in the window. */
class CheckoutRateLimitException extends ClientErrorException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 429);
    }
}

/**
 * Where the state files go. $sessionPath is PHP's session.save_path, which may carry a depth or mode
 * prefix ("2;/path", "2;0600;/path").
 */
function checkout_state_dir_for(string $configured, string $sessionPath, string $tempDir): string
{
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $parts = explode(';', $sessionPath);
    $sessions = trim((string)end($parts));
    return $sessions !== '' ? rtrim($sessions, '/') . '/checkout' : rtrim($tempDir, '/');
}

function checkout_state_dir(): string
{
    $dir = checkout_state_dir_for((string)env('CHECKOUT_STATE_DIR', ''), (string)session_save_path(), sys_get_temp_dir());
    if (!is_dir($dir) && !mkdir($dir, CHECKOUT_STATE_DIR_MODE, true) && !is_dir($dir)) {
        throw new RuntimeException('The checkout state directory cannot be created.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('The checkout state directory is not writable.');
    }
    return $dir;
}

function checkout_state_path(string $kind, int $userId, string $key = ''): string
{
    return checkout_state_dir() . '/checkout_' . $kind . '_' . sha1($userId . '_' . $key);
}

function checkout_rate_limit_max(): int
{
    return max(1, (int)env('CHECKOUT_RATE_LIMIT_MAX', (string)CHECKOUT_RATE_LIMIT_DEFAULT));
}

/**
 * The key of this request: the Idempotency-Key header, else the idempotency_key field. It is
 * required, at most CHECKOUT_KEY_MAX characters and limited to a safe alphabet.
 */
function checkout_idempotency_key(string $header, string $param): string
{
    $key = trim($header) !== '' ? trim($header) : trim($param);
    if ($key === '') {
        throw new BadRequestException('Missing Idempotency-Key header.');
    }
    if (strlen($key) > CHECKOUT_KEY_MAX) {
        throw new UnprocessableException('Idempotency key is too long (at most ' . CHECKOUT_KEY_MAX . ' characters).');
    }
    if (preg_match(CHECKOUT_KEY_PATTERN, $key) !== 1) {
        throw new UnprocessableException('Idempotency key may only contain letters, digits and . _ : ~ -');
    }
    return $key;
}

/**
 * What the key was first used for: a replay with the same key but other input is refused rather
 * than answered with an order the caller did not ask for.
 *
 * @param array<string, mixed> $input
 */
function checkout_request_fingerprint(array $input): string
{
    return hash('sha256', json_encode([
        (int)($input['store_id'] ?? 0),
        (int)($input['purchasing_email_id'] ?? 0),
        (string)($input['notes'] ?? ''),
    ], JSON_THROW_ON_ERROR));
}

function checkout_lock_name(int $userId): string
{
    return CHECKOUT_LOCK_NAME_PREFIX . $userId;
}

/** Seconds a second checkout waits for the first (CHECKOUT_LOCK_WAIT, default 10). */
function checkout_lock_wait(): int
{
    return max(0, (int)env('CHECKOUT_LOCK_WAIT', (string)CHECKOUT_LOCK_WAIT_DEFAULT));
}

/**
 * Serialise one user's checkouts across every app container that shares the database: a second
 * request waits for the first, then finds the cart empty or the finished order. Two different keys
 * sent together (a double click) can no longer both read the cart and create two orders.
 */
function checkout_acquire_user_lock(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([checkout_lock_name($userId), checkout_lock_wait()]);
    $got = $stmt->fetchColumn();
    if ($got === null || $got === false) {
        throw new RuntimeException('The checkout lock could not be requested.');
    }
    if ((int)$got !== 1) {
        throw new ConflictException('Another checkout is in progress. Please try again in a moment.');
    }
}

function checkout_release_user_lock(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([checkout_lock_name($userId)]);
    $stmt->fetchColumn();
}

/**
 * The record stored at $path while it is valid; an unreadable or expired record is removed.
 *
 * @return array<string, mixed>|null
 */
function checkout_idempotency_read(string $path): ?array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (is_array($data) && (int)($data['expires_at'] ?? 0) >= time() && is_array($data['response'] ?? null)) {
        return $data;
    }
    unlink($path);
    return null;
}

/**
 * The stored response for $key, or null when the key is new or expired.
 *
 * @return array<string, mixed>|null
 */
function checkout_idempotency_lookup(int $userId, string $key, string $fingerprint): ?array
{
    $data = checkout_idempotency_read(checkout_state_path('idem', $userId, $key));
    if ($data === null) {
        return null;
    }
    if (!hash_equals((string)($data['fingerprint'] ?? ''), $fingerprint)) {
        throw new ConflictException('This Idempotency-Key was already used for a different order.');
    }
    return $data['response'];
}

/** @param array<string, mixed> $response */
function checkout_idempotency_store(int $userId, string $key, string $fingerprint, array $response): void
{
    $payload = json_encode([
        'response' => $response,
        'fingerprint' => $fingerprint,
        'expires_at' => time() + CHECKOUT_IDEMPOTENCY_TTL,
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents(checkout_state_path('idem', $userId, $key), $payload, LOCK_EX) === false) {
        // The order exists; only the replay protection for this key is lost.
        error_log('checkout: could not store the idempotency record');
    }
}

function checkout_rate_limit_bucket(int $userId): string
{
    return $userId . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** Count one attempt for $bucket and refuse it once the window's budget is spent. */
function checkout_enforce_rate_limit(string $bucket): void
{
    $handle = fopen(checkout_state_dir() . '/checkout_rl_' . sha1($bucket), 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('The checkout rate limiter is unavailable.');
    }
    try {
        $now = time();
        $data = json_decode((string)stream_get_contents($handle), true);
        $record = ['count' => 0, 'reset' => $now + CHECKOUT_RATE_LIMIT_WINDOW];
        if (is_array($data) && isset($data['count'], $data['reset']) && $data['reset'] > $now) {
            $record = $data;
        }
        if ($record['count'] >= checkout_rate_limit_max()) {
            throw new CheckoutRateLimitException('Too many checkout attempts. Please wait a minute and try again.');
        }
        $record['count']++;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($record, JSON_THROW_ON_ERROR));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
