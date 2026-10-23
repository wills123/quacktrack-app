<?php

/**
 * The service desk's asset review queue: `options` (GET) loads the form defaults and Snipe-IT choices for a review,
 * `create_asset`, `link_existing` and `dismiss` (POST) resolve it. The checks and steps are in
 * includes/snipe_review_api.php and includes/snipe_review_service.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/snipe_review_api.php';
require_login();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');

// A method the endpoint never answers is refused before the action is read (PHP parses no PUT or DELETE body).
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    api_error('Method not allowed', 405);
}
$action = strtolower(trim(req_action()));
match ($action) {
    'options', 'create_asset', 'link_existing', 'dismiss' => snipe_review_dispatch($action, (int)($_SESSION['uid'] ?? 0)),
    default => api_error('Unsupported review action.', 400),
};
