<?php

/** Keep account authorization and writes consistent with concurrent role changes. */
function user_admin_begin_change(PDO $pdo, int $targetUserId = 0): void
{
    $actorId = (int)($_SESSION['uid'] ?? 0);
    $pdo->beginTransaction();
    // Both admin controllers use this order. Role changes cannot pass a pending account check.
    $pdo->query('SELECT id FROM roles ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare('SELECT id, session_version FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
    $stmt->execute([$actorId, $targetUserId]);
    $currentVersion = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        if ((int)$user['id'] === $actorId) {
            $currentVersion = (int)$user['session_version'];
        }
    }
    if (
        $currentVersion === null
        || !isset($_SESSION['session_version'])
        || $currentVersion !== (int)$_SESSION['session_version']
    ) {
        throw new RuntimeException('Your session changed. Please sign in again.');
    }
}
