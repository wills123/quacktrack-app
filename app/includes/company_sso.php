<?php

declare(strict_types=1);

require_once __DIR__ . '/company_oidc_client.php';

/** Consume the browser's flow before any provider request, even when the return is invalid. */
function company_sso_take_flow(array $query): array
{
    $flow = $_SESSION['company_sso_flow'] ?? null;
    unset($_SESSION['company_sso_flow']);
    if (!company_sso_flow_pending($flow) || !company_sso_query_valid($query)) {
        throw new RuntimeException('The SSO return does not match a pending sign-in.');
    }
    if (!hash_equals($flow['state'], $query['state'])) {
        throw new RuntimeException('The SSO return does not match a pending sign-in.');
    }
    return $flow;
}

function company_sso_flow_pending(mixed $flow): bool
{
    return is_array($flow) && is_int($flow['started'] ?? null)
        && $flow['started'] >= time() - 600 && $flow['started'] <= time()
        && is_string($flow['state'] ?? null);
}

function company_sso_query_valid(array $query): bool
{
    return is_string($query['state'] ?? null) && is_string($query['code'] ?? null)
        && $query['code'] !== '' && strlen($query['code']) <= 4096 && !isset($query['error']);
}

/** No automatic email linking or privilege mapping: an administrator assigns the immutable identity. */
function company_sso_user(PDO $pdo, string $issuer, string $subject): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.first_name, u.last_name, u.username, u.role, u.region_id,
                u.session_version, r.name AS region_name
         FROM oidc_identities i JOIN users u ON u.id = i.user_id
         LEFT JOIN regions r ON r.id = u.region_id
         WHERE i.issuer = ? AND i.subject = ? AND u.login_method = ?'
    );
    $stmt->execute([$issuer, $subject, 'oidc']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('The company identity has not been assigned to an account.');
    }
    return $user;
}

function company_sso_create_session(array $user, string $issuer, int $authenticatedAt): void
{
    regenerate_session_secure();
    // Adopt only the version read with the identity, never a later concurrently revoked version.
    $_SESSION = [
        'uid' => (int)$user['id'], 'email' => $user['email'], 'first_name' => $user['first_name'],
        'last_name' => $user['last_name'], 'username' => $user['username'], 'role' => $user['role'],
        'region_id' => $user['region_id'], 'region' => $user['region_name'],
        'display_name' => build_user_display_name($user['first_name'], $user['last_name'], $user['username'], $user['email']),
        'session_version' => (int)$user['session_version'], 'login_method' => 'oidc',
        'sso_issuer' => $issuer, 'sso_authenticated_at' => $authenticatedAt,
        'login_time' => time(), 'last_activity' => time(), 'last_regeneration' => time(),
        'fingerprint' => generate_session_fingerprint(), 'fingerprint_version' => 2,
    ];
    reset_user_permission_cache();
    Logger::security('successful_login', ['user_id' => (int)$user['id'], 'method' => 'oidc']);
}

/** Never log provider exceptions: their text can contain codes, tokens or identity claims. */
function company_sso_failure(Throwable $error): void
{
    Logger::security('company_sso_failed', ['exception_type' => get_class($error)]);
    flash('Company sign-in could not be completed. Please try again or contact your administrator.', 'error');
    redirect('/login');
}
