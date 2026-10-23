<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_login();
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/snipe_catalog_api.php';
require_once __DIR__ . '/../includes/snipe_detail_history_api.php';
require_once __DIR__ . '/../includes/snipe_writes_api.php';
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');
$action = req_action();
$action = strtolower(trim($action));
$userId = (int)($_SESSION['uid'] ?? 0);
// Each group of actions has its own checks and error handling and ends the request: the catalog reads
// (includes/snipe_catalog_api.php), the asset detail, activity and statistics (includes/snipe_detail_history_api.php)
// and the writes, which use the person's own Snipe-IT token (includes/snipe_writes_api.php).
match ($action) {
    'list'          => snipe_catalog_dispatch($action, $userId),
    'categories'    => snipe_catalog_dispatch($action, $userId),
    'models'        => snipe_catalog_dispatch($action, $userId),
    'statuses'      => snipe_catalog_dispatch($action, $userId),
    'locations'     => snipe_catalog_dispatch($action, $userId),
    'manufacturers' => snipe_catalog_dispatch($action, $userId),
    'suppliers'     => snipe_catalog_dispatch($action, $userId),
    'users'         => snipe_catalog_dispatch($action, $userId),
    'get'           => snipe_detail_history_dispatch($action, $userId),
    'activity'      => snipe_detail_history_dispatch($action, $userId),
    'stats'         => snipe_detail_history_dispatch($action, $userId),
    'checkout'      => snipe_writes_dispatch($action, $userId),
    'checkin'       => snipe_writes_dispatch($action, $userId),
    'update'        => snipe_writes_dispatch($action, $userId),
    default         => api_error('Unsupported action', 400),
};
