<?php

/**
 * Snipe-IT OAuth2 Callback Handler
 *
 * Dispatch only; the flow (login and link intents, state and PKCE checks, token exchange, account linking and
 * SSO provisioning) is in includes/snipe_oauth_callback.php.
 *
 * Note: This endpoint does NOT require require_login() because it IS the login handler for the 'login' intent.
 * The 'link' intent validates the session itself.
 */

declare(strict_types=1);

// Bootstrap and shutdown logging read REQUEST_URI. Keep callback credentials out
// of that metadata; PHP has already parsed the original query into $_GET.
$_SERVER['REQUEST_URI'] = explode('?', $_SERVER['REQUEST_URI'] ?? '/api/snipe-oauth-callback', 2)[0];

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/snipe_oauth_callback.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// The authorization code is in this URL; do not let it leak through a Referer header.
header('Referrer-Policy: no-referrer');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

snipe_oauth_callback();
