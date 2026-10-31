<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_policy.php';
require_once __DIR__ . '/api_errors.php';

/**
 * Only current hashed links are accepted. Never retry a digest as a legacy plaintext bearer token.
 *
 * @return array<string, mixed>|null
 */
function password_recovery_find(PDO $pdo, string $token): ?array
{
    if (!local_auth_enabled() || preg_match('/\A[0-9a-f]{64}\z/D', $token) !== 1) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.user_id, u.email, u.login_method FROM password_reset_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW() AND t.used_at IS NULL'
    );
    $stmt->execute([hash_password_reset_token($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false && user_accepts_local_credentials((string)$row['login_method']) ? $row : null;
}

/**
 * Recheck the account under a lock so an SSO conversion cannot race issuance.
 *
 * @return array{email: string, token: string}|null The raw token goes only to the mail sender.
 */
function password_recovery_issue(PDO $pdo, string $email): ?array
{
    if (!local_auth_enabled()) {
        return null;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, email, login_method FROM users WHERE email = ? FOR UPDATE');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false || !user_accepts_local_credentials((string)$user['login_method'])) {
            $pdo->rollBack();
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
            ->execute([(int)$user['id'], hash_password_reset_token($token)]);
        $pdo->commit();
        return ['email' => (string)$user['email'], 'token' => $token];
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/** Consume a token once, update the password, and revoke every older session in the same transaction. */
function password_recovery_complete(PDO $pdo, string $token, string $password): int
{
    $found = password_recovery_find($pdo, $token);
    if ($found === null) {
        throw new BadRequestException('Invalid or expired reset link. Please request a new one.');
    }
    $strength = validate_password_strength($password, false);
    if (!$strength['valid']) {
        throw new BadRequestException(implode('; ', $strength['errors']));
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $userId = (int)$found['user_id'];
    $pdo->beginTransaction();
    try {
        password_recovery_lock($pdo, $userId, $token);
        $pdo->prepare('UPDATE users SET password_hash = ?, must_reset_password = 0, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?')
            ->execute([$hash, $userId]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $pdo->commit();
        return $userId;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/** Lock the account first, then recheck the token against its committed state and current time. */
function password_recovery_lock(PDO $pdo, int $userId, string $token): void
{
    // All credential writers lock the user first. This serializes different tokens for the same account too.
    $userStmt = $pdo->prepare('SELECT login_method FROM users WHERE id = ? FOR UPDATE');
    $userStmt->execute([$userId]);
    $method = $userStmt->fetchColumn();
    if (!is_string($method) || !user_accepts_local_credentials($method)) {
        throw new BadRequestException('Invalid or expired reset link. Please request a new one.');
    }
    $tokenStmt = $pdo->prepare('SELECT id FROM password_reset_tokens WHERE user_id = ? AND token = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
    $tokenStmt->execute([$userId, hash_password_reset_token($token)]);
    if ($tokenStmt->fetchColumn() === false) {
        throw new ConflictException('This reset link is no longer valid. Please request a new one.');
    }
}
