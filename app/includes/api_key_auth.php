<?php

declare(strict_types=1);

/**
 * Authenticate a stateless API-key request and enforce its scope/rate limit.
 *
 * @return array{id:int, scopes:string, created_by_user_id:int}
 */
function quacktrack_api_key_authenticate(string $requiredScope): array
{
    $apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($apiKey === '') {
        quacktrack_api_key_error(401, 'Unauthorized.');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, scopes, is_active, expires_at, created_by_user_id
        FROM api_keys
        WHERE key_hash = ?
        LIMIT 1');
    $stmt->execute([hash('sha256', $apiKey)]);
    $key = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$key || !(int)$key['is_active']) {
        Logger::security('api_key_auth_failed', ['reason' => 'invalid_or_inactive_key']);
        quacktrack_api_key_error(401, 'Unauthorized.');
    }

    if ($key['expires_at'] !== null && strtotime((string)$key['expires_at']) < time()) {
        Logger::security('api_key_auth_failed', ['reason' => 'expired_key', 'key_id' => (int)$key['id']]);
        quacktrack_api_key_error(401, 'Unauthorized.');
    }

    $scopes = array_values(array_filter(array_map('trim', explode(',', (string)$key['scopes']))));
    if (!in_array($requiredScope, $scopes, true)) {
        Logger::security('api_key_scope_denied', [
            'key_id' => (int)$key['id'],
            'required_scope' => $requiredScope,
        ]);
        quacktrack_api_key_error(403, 'Forbidden.');
    }

    $pdo->prepare('UPDATE api_keys
        SET last_used_at = NOW(), last_used_ip = ?, request_count = request_count + 1
        WHERE id = ?')->execute([
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        (int)$key['id'],
    ]);

    $bucket = intdiv(time(), 60);
    $rateStmt = $pdo->prepare('INSERT INTO api_key_rate_limits (key_id, minute_bucket, count, updated_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE count = count + 1, updated_at = NOW()');
    $rateStmt->execute([(int)$key['id'], $bucket]);
    $countStmt = $pdo->prepare('SELECT count FROM api_key_rate_limits WHERE key_id = ? AND minute_bucket = ?');
    $countStmt->execute([(int)$key['id'], $bucket]);
    if ((int)$countStmt->fetchColumn() > quacktrack_api_key_rate_limit()) {
        quacktrack_api_key_error(429, 'Rate limit exceeded.');
    }

    return [
        'id' => (int)$key['id'],
        'scopes' => (string)$key['scopes'],
        'created_by_user_id' => (int)$key['created_by_user_id'],
    ];
}

function quacktrack_api_key_error(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

/** Requests one key may make in a minute unless API_KEY_RATE_LIMIT_PER_MINUTE says otherwise. */
const API_KEY_RATE_LIMIT_DEFAULT = 60;

/** The per-minute request limit of an API key; the isolated fuzz run raises it, nothing else sets it. */
function quacktrack_api_key_rate_limit(): int
{
    return max(1, (int)env('API_KEY_RATE_LIMIT_PER_MINUTE', (string)API_KEY_RATE_LIMIT_DEFAULT));
}
