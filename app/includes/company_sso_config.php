<?php

declare(strict_types=1);

/** URLs come from trusted deployment configuration, never a request Host header. */
function company_sso_url(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || strlen($url) > 512) {
        return false;
    }
    foreach (['user', 'pass', 'query', 'fragment'] as $component) {
        if (isset($parts[$component])) {
            return false;
        }
    }
    return ($parts['scheme'] ?? '') === 'https'
        || (env('APP_ENV', 'prod') === 'dev' && ($parts['scheme'] ?? '') === 'http');
}

/** @return array{issuer: string, client_id: string, client_secret: string, callback: string, max_age: int} */
function company_sso_config(): array
{
    $config = [
        'issuer' => env('SSO_ISSUER_URL', ''),
        'client_id' => env('SSO_CLIENT_ID', ''),
        'client_secret' => env('SSO_CLIENT_SECRET', ''),
        'callback' => env('SSO_CALLBACK_URL', ''),
        'max_age' => (int)env('SSO_SESSION_MAX_AGE', '3600'),
    ];
    if (
        env('SSO_ENABLED', '0') !== '1' || $config['client_id'] === '' || $config['client_secret'] === ''
        || !company_sso_url($config['issuer']) || !company_sso_url($config['callback'])
        || parse_url($config['callback'], PHP_URL_PATH) !== '/sso/callback'
        || $config['max_age'] < 300 || $config['max_age'] > 86400
    ) {
        throw new RuntimeException('Company SSO is disabled or its configuration is incomplete.');
    }
    return $config;
}

function company_sso_is_configured(): bool
{
    try {
        company_sso_config();
        return true;
    } catch (RuntimeException) {
        return false;
    }
}

/** A configuration change or the absolute SSO deadline invalidates an existing session. */
function company_sso_session_allowed(): bool
{
    if (!company_sso_is_configured()) {
        return false;
    }
    $config = company_sso_config();
    return ($_SESSION['sso_issuer'] ?? null) === $config['issuer']
        && is_int($_SESSION['sso_authenticated_at'] ?? null)
        && time() < $_SESSION['sso_authenticated_at'] + $config['max_age'];
}

/** Discovery may use another path (Entra does), but cannot move credentials to another origin. */
function company_sso_same_origin(string $url, string $issuer): bool
{
    if (!company_sso_url($url)) {
        return false;
    }
    foreach ([PHP_URL_SCHEME, PHP_URL_HOST, PHP_URL_PORT] as $part) {
        if (parse_url($url, $part) !== parse_url($issuer, $part)) {
            return false;
        }
    }
    return true;
}
