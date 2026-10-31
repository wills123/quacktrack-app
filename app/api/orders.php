<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/inventory_ownership.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/SnipeAssetAssignment.php';
require_once __DIR__ . '/../includes/order_receipts.php';
require_once __DIR__ . '/../includes/orders_browse_api.php';
require_once __DIR__ . '/../includes/orders_status_receipts_api.php';
require_login();
// Ensure PHP notices don't corrupt JSON responses.
ini_set('display_errors', '0');
ini_set('html_errors', '0');
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$pdo = db();
$current_user_id = $_SESSION['uid'];
$action = req_action();

try {
    match ($action) {
        // Browsing, reordering and cancelling: their own checks and error handling (includes/orders_browse_api.php).
        'reorder' => orders_browse_dispatch($action, $pdo, $current_user_id),
        'list' => orders_browse_dispatch($action, $pdo, $current_user_id),
        'get' => orders_browse_dispatch($action, $pdo, $current_user_id),
        'cancel' => orders_browse_dispatch($action, $pdo, $current_user_id),
        'update_status' => orders_status_receipts_dispatch($action, $pdo, $current_user_id),
        'receive_tracked_asset' => orders_status_receipts_dispatch($action, $pdo, $current_user_id),
        default => throw new BadRequestException('Invalid action.')
    };
} catch (Throwable $e) {
    api_fail($e, 'orders_api_error', ['action' => $action, 'user_id' => $current_user_id]);
}
