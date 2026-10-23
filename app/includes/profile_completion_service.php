<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/** @return array<string, mixed> */
function profile_completion_status(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT first_name, last_name, username, region_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        throw new NotFoundException('User profile not found.');
    }
    $missing = [];
    foreach (['first_name', 'last_name', 'username', 'region_id'] as $field) {
        if ($user[$field] === null || trim((string)$user[$field]) === '' || ($field === 'region_id' && (int)$user[$field] <= 0)) {
            $missing[] = $field;
        }
    }
    // Unlike the shared UI helper, a database fault must propagate to the API's fault boundary.
    $regions = $pdo->query('SELECT id, name FROM regions WHERE allow_registration = 1 ORDER BY name');
    return [
        'success' => true,
        'needs_completion' => $missing !== [],
        'missing_fields' => $missing,
        'regions' => $regions->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Only this known unique index represents a client username conflict; other integrity errors are faults. */
function profile_completion_username_conflict(PDOException $error): bool
{
    return ($error->errorInfo[0] ?? null) === '23000'
        && (int)($error->errorInfo[1] ?? 0) === 1062
        && preg_match("/for key ['`](?:[^'`]*\\.)?uk_users_username['`]/", (string)($error->errorInfo[2] ?? '')) === 1;
}

/** @param array<string, mixed> $input */
function profile_completion_save(PDO $pdo, int $userId, array $input): string
{
    $pdo->beginTransaction();
    try {
        $owner = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $owner->execute([$userId]);
        if ($owner->fetchColumn() === false) {
            throw new NotFoundException('User profile not found.');
        }
        // Keep the selected region valid until commit, including concurrent deletion/disable attempts.
        $region = $pdo->prepare('SELECT name FROM regions WHERE id = ? AND allow_registration = 1 LOCK IN SHARE MODE');
        $region->execute([$input['region_id']]);
        $regionName = $region->fetchColumn();
        if ($regionName === false) {
            throw new UnprocessableException('Invalid region selected.');
        }
        $update = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, username = ?, region_id = ? WHERE id = ?');
        $update->execute([$input['first_name'], $input['last_name'], $input['username'], $input['region_id'], $userId]);
        $pdo->commit();
        return (string)$regionName;
    } catch (PDOException $error) {
        if (profile_completion_username_conflict($error)) {
            throw new ConflictException('Username already taken. Please choose another.', previous: $error);
        }
        throw $error;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
