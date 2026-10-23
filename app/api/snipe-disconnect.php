<?php

/**
 * Snipe-IT account disconnect.
 *
 * Forgets the signed-in user's stored Snipe-IT token and cached permissions, so write
 * actions stop being attributed to them until they connect again. Quack Track login
 * is unaffected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SnipeTokenManager.php';
require_login();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!csrf_verify_api()) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$userId = (int)($_SESSION['uid'] ?? 0);
if (!SnipeTokenManager::revoke($userId)) {
    // The stored token is still there: say so instead of reporting a disconnect that did not happen.
    Logger::security('snipe_disconnect_failed', ['user_id' => $userId]);
    flash('Your Snipe-IT account could not be disconnected. Please try again.', 'error');
    redirect('/assets');
}
Logger::security('snipe_disconnected', ['user_id' => $userId]);

flash('Snipe-IT account disconnected.', 'success');
redirect('/assets');
