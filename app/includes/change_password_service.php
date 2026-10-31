<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/auth_policy.php';

/** An incorrect current credential keeps the existing 400 contract and its security event. */
final class ChangePasswordCurrentException extends BadRequestException
{
}

/** The API preserves these validation fields at the top level for existing callers. */
final class ChangePasswordStrengthException extends BadRequestException
{
    /** @param array<string, mixed> $strength */
    public function __construct(array $strength)
    {
        parent::__construct('Password does not meet security requirements', [
            'validation_errors' => $strength['errors'],
            'warnings' => $strength['warnings'] ?? [],
        ]);
    }
}

/**
 * @param array<string, mixed> $input validated raw credential fields
 * @param callable(string, bool): array $strengthPolicy the existing application's password policy
 * @return array{was_forced: bool, session_version: int} The initiating session may adopt only this committed version.
 */
function change_password_save(PDO $pdo, int $userId, array $input, callable $strengthPolicy, int $sessionVersion): array
{
    $pdo->beginTransaction();
    try {
        // Re-read under the row lock: another request must not verify a stale credential hash.
        $select = $pdo->prepare('SELECT password_hash, must_reset_password, login_method, session_version FROM users WHERE id = ? FOR UPDATE');
        $select->execute([$userId]);
        $user = $select->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new NotFoundException('User not found');
        }
        if (!user_accepts_local_credentials((string)$user['login_method']) || (int)$user['session_version'] !== $sessionVersion) {
            throw new ForbiddenException('This account must sign in again through its approved login method.');
        }
        if (!password_verify($input['current_password'], (string)$user['password_hash'])) {
            throw new ChangePasswordCurrentException('Current password is incorrect');
        }
        if ($input['current_password'] === $input['new_password']) {
            throw new BadRequestException('New password must be different from current password');
        }
        $strength = $strengthPolicy($input['new_password'], strlen($input['current_password']) < 12);
        if (!$strength['valid']) {
            throw new ChangePasswordStrengthException($strength);
        }
        $hash = password_hash($input['new_password'], PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE users SET password_hash = ?, must_reset_password = 0, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?');
        $update->execute([$hash, $userId]);
        $pdo->commit();
        return ['was_forced' => (bool)$user['must_reset_password'], 'session_version' => (int)$user['session_version'] + 1];
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
