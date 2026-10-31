<?php

/** Assign an immutable company identity to an approved existing account; run only from the operator CLI. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('QUACKTRACK_NO_SESSION', true);
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/includes/company_sso_config.php';

$options = getopt('', ['user-id:', 'subject:']);
$userId = filter_var($options['user-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$subject = $options['subject'] ?? null;
if ($userId === false || $userId === null || !is_string($subject) || $subject === '' || strlen($subject) > 255) {
    fwrite(STDERR, "Usage: php scripts/bind_company_sso.php --user-id=NUMBER --subject=PROVIDER_SUBJECT\n");
    exit(2);
}
$pdo = db();
try {
    $config = company_sso_config();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Unknown account.');
    }
    // A conflicting binding must be investigated; never silently replace or move an identity.
    $pdo->prepare('INSERT INTO oidc_identities (user_id, issuer, subject) VALUES (?, ?, ?)')
        ->execute([$userId, $config['issuer'], $subject]);
    $pdo->prepare('UPDATE users SET login_method = ?, must_reset_password = 0, session_version = session_version + 1 WHERE id = ?')
        ->execute(['oidc', $userId]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ?')->execute([$userId]);
    $pdo->commit();
    fwrite(STDOUT, "Company identity assigned. Previous sessions and recovery links have been revoked.\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Assignment failed; configuration, account and existing bindings must be checked.\n");
    exit(1);
}
