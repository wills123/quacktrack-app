<?php

/**
 * Snipe-IT OAuth Account Link Initiator
 *
 * Starts the OAuth Authorization Code flow for an already-logged-in
 * Quack Track user who wants to link their personal Snipe-IT account.
 *
 * Intent stored in session as 'link' so the callback knows NOT to
 * create a new Quack Track session — it only stores the token.
 *
 * Flow:
 *   1. User clicks "Sign in with Snipe-IT" on the assets connect screen
 *   2. This endpoint validates they're logged in, builds the OAuth URL
 *   3. Redirects to Snipe-IT's /oauth/authorize
 *   4. Snipe-IT redirects back to snipe-oauth-callback.php with intent=link
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/snipe_oauth.php';
require_login();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

if (env('SNIPE_IT_OAUTH_ENABLED', '0') !== '1') {
    flash('Snipe-IT OAuth is not enabled. Please contact your administrator.', 'error');
    redirect('/assets');
    exit;
}

if (!snipe_oauth_is_configured()) {
    flash('Snipe-IT OAuth is not fully configured. Please contact your administrator.', 'error');
    redirect('/assets');
    exit;
}

http_response_code(302);
header('Location: ' . snipe_oauth_authorize_url('link', '/assets'));
exit;
