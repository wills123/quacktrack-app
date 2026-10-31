<?php

declare(strict_types=1);

/** Increment under MySQL's unique-key lock; checking and incrementing cannot lose concurrent attempts. */
function auth_rate_limit_hit(PDO $pdo, string $bucket, int $limit, int $seconds): bool
{
    $key = 'auth:' . hash('sha256', $bucket);
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (rate_key, hit_count, reset_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE
            hit_count = IF(reset_at <= NOW(), 1, LEAST(hit_count + 1, 1000000)),
            reset_at = IF(reset_at <= NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND), reset_at)'
    );
    $stmt->execute([$key, $seconds, $seconds]);
    $read = $pdo->prepare('SELECT hit_count FROM rate_limits WHERE rate_key = ?');
    $read->execute([$key]);
    return (int)$read->fetchColumn() <= $limit;
}

/** Account and source limits persist across fresh cookies. Unknown accounts take the same rate-limit path. */
function auth_request_allowed(PDO $pdo, string $operation, string $email): bool
{
    $login = $operation === 'login';
    $prefix = $login ? 'AUTH_LOGIN_' : 'AUTH_RECOVERY_';
    $sourceLimit = max(1, (int)env($prefix . 'IP_LIMIT', $login ? '100' : '20'));
    $accountLimit = max(1, (int)env($prefix . 'ACCOUNT_LIMIT', $login ? '10' : '3'));
    $source = auth_rate_limit_hit($pdo, $operation . ':ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $sourceLimit, 900);
    $account = auth_rate_limit_hit($pdo, $operation . ':account:' . strtolower(trim($email)), $accountLimit, 900);
    return $source && $account;
}
