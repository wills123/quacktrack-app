<?php

declare(strict_types=1);

/**
 * Starting the Snipe-IT OAuth2 authorization-code flow.
 *
 * One builder for both entry points (the login page's "Sign in with Snipe IT" and the
 * Assets page's account link), so the CSRF state, the PKCE challenge and the session
 * bookkeeping the callback relies on are created in exactly one place.
 */

/**
 * Whether OAuth is switched on and fully configured.
 */
function snipe_oauth_is_configured(): bool
{
    return snipe_oauth_transport_ready()
        && env('SNIPE_IT_OAUTH_ENABLED', '0') === '1'
        && env('SNIPE_IT_OAUTH_CLIENT_ID', '') !== ''
        && env('SNIPE_IT_OAUTH_REDIRECT_URI', '') !== ''
        && env('SNIPE_IT_OAUTH_BASE_URL', '') !== '';
}

/**
 * Create the authorize URL for a new flow and remember what the callback needs.
 *
 * @param string $intent    'login' (sign in with Snipe-IT) or 'link' (attach to the signed-in user)
 * @param string $returnUrl Local path to land on after a successful link
 */
function snipe_oauth_authorize_url(string $intent, string $returnUrl = '/'): string
{
    if (!snipe_oauth_is_configured()) {
        throw new RuntimeException('Secure SSO configuration is required.');
    }
    $state = bin2hex(random_bytes(16));
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_intent'] = $intent === 'link' ? 'link' : 'login';
    $_SESSION['oauth_return_url'] = $returnUrl;
    $_SESSION['oauth_pkce_verifier'] = $verifier;

    return rtrim(env('SNIPE_IT_OAUTH_BASE_URL', ''), '/') . '/oauth/authorize?' . http_build_query([
        'client_id'             => env('SNIPE_IT_OAUTH_CLIENT_ID', ''),
        'redirect_uri'          => env('SNIPE_IT_OAUTH_REDIRECT_URI', ''),
        'response_type'         => 'code',
        'scope'                 => '',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Only ever redirect to a path on this site, never to a URL or protocol-relative address.
 */
function snipe_oauth_safe_return_path(string $path, string $fallback = '/'): string
{
    return preg_match('#^/(?![/\\\\])[^\r\n]*$#', $path) === 1 ? $path : $fallback;
}

/** Refuse to send a browser to a cleartext identity provider in a production environment. */
function snipe_oauth_transport_ready(): bool
{
    if (env('APP_ENV', 'prod') === 'dev') {
        return true;
    }
    foreach (['SNIPE_IT_OAUTH_BASE_URL', 'SNIPE_IT_OAUTH_REDIRECT_URI', 'SNIPE_IT_API_URL'] as $key) {
        $url = env($key);
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https' || !parse_url($url, PHP_URL_HOST)) {
            return false;
        }
    }
    return true;
}
