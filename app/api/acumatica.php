<?php

/**
 * Acumatica API
 *
 * Live stock levels, stock item, shipment tracking and purchase-order status lookups, and purchase-order creation
 * (always on Hold). Dispatch only; the checks, input and answers are in includes/acumatica_api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_login();
require_once __DIR__ . '/../includes/acumatica_api.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

$action = strtolower(trim(req_action()));
$userId = (int)($_SESSION['uid'] ?? 0);

switch ($action) {
    case 'stock_levels':
    case 'stock_item':
    case 'shipment_tracking':
    case 'po_status':
    case 'create_po':
        acumatica_dispatch($action, $userId);
        break;
    default:
        api_error('Unsupported action.', 400);
}
