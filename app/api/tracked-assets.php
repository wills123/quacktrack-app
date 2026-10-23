<?php

/**
 * Tracked assets: `dispose` records what happened to a received asset (returned, repair, retired, lost) and checks it
 * in to Snipe-IT with the person's own token. The checks and steps are in includes/tracked_assets_api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/tracked_assets_api.php';
require_login();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');

// The method comes first, so a request without a known action still learns that only POST is answered.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    api_error('Method not allowed', 405);
}
$action = strtolower(trim((string)(req_action())));
match ($action) {
    'dispose' => tracked_assets_dispatch((int)($_SESSION['uid'] ?? 0)),
    default => api_error('Unsupported tracked-asset action.', 400),
};
