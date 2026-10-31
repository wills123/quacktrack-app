<?php

declare(strict_types=1);

/**
 * The database version may change between authentication checks.
 *
 * @phpstan-impure
 */
function user_session_version(int $userId): ?int
{
    $stmt = db()->prepare('SELECT session_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $version = $stmt->fetchColumn();
    return $version === false ? null : (int)$version;
}

/**
 * Revocation may commit while the session is being renewed.
 *
 * @phpstan-impure
 */
function session_version_is_current(int $userId, mixed $version): bool
{
    // Existing sessions predate this column and belong to version zero. Never
    // upgrade one to the database value: that would resurrect a revoked session.
    return is_int($version) && user_session_version($userId) === $version;
}

function revoke_user_sessions(int $userId): bool
{
    $stmt = db()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?');
    $stmt->execute([$userId]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    Logger::security('admin_force_logout', ['target_user_id' => $userId]);
    return true;
}
