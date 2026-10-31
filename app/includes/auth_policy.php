<?php

declare(strict_types=1);

require_once __DIR__ . '/company_sso_config.php';

/** Local accounts remain available for development and explicitly approved deployments. */
function local_auth_enabled(): bool
{
    return env('LOCAL_AUTH_ENABLED', '1') === '1' && env('SSO_REQUIRED', '0') !== '1';
}

/** Public registration is opt-in; corporate deployments should provision through their identity provider. */
function public_registration_enabled(): bool
{
    return local_auth_enabled() && env('PUBLIC_REGISTRATION_ENABLED', '0') === '1';
}

/** An integration link does not change a local account's login method; an SSO login does. */
function user_accepts_local_credentials(string $loginMethod): bool
{
    return local_auth_enabled() && $loginMethod === 'local';
}

/** Company SSO enforcement also closes the separate Snipe-IT login path, while keeping integration links. */
function snipe_sso_login_enabled(): bool
{
    return env('SSO_REQUIRED', '0') !== '1';
}

function session_auth_method_allowed(string $method): bool
{
    return match ($method) {
        'local' => local_auth_enabled(),
        'snipe_oauth' => snipe_sso_login_enabled(),
        'oidc' => company_sso_session_allowed(),
        default => false,
    };
}
