<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/order_details.php';
require_once __DIR__ . '/../includes/order_details_service.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$order_id = 0;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        api_error('Method not allowed.', 405);
    }
    $input = input_parse(order_details_input_schema(), $_GET);
    $order_id = $input['order_id'];
    $pdo = db();

    // Get order details
    $orderStmt = $pdo->prepare("
        SELECT po.*, s.name as store_name,
               u.email as user_email,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display,
               u.role as user_role,
               u.region_id as owner_region_id,
               r.name as owner_region_name
        FROM purchase_orders po
        LEFT JOIN stores s ON po.store_id = s.id
        LEFT JOIN users u ON po.user_id = u.id
        LEFT JOIN regions r ON u.region_id = r.id
        WHERE po.id = ?
    ");
    $orderStmt->execute([$order_id]);
    $order = $orderStmt->fetch();
    if (!$order) {
        throw new NotFoundException('Order not found');
    }

    if (!order_details_can_view($order, order_details_viewer())) {
        throw new ForbiddenException('Access denied. You do not have permission to view this order.');
    }

    // Get order items
    $items = $pdo->prepare('
        SELECT poi.id, poi.order_id, poi.product_id, poi.quantity, poi.notes, poi.created_at,
               poi.bill_to_store_id, poi.receive_to_van_qty, poi.receive_to_region_qty,
               p.product_id as sku, p.name, p.image_path, p.is_tracked_asset,
               billing_store.name AS bill_to_store_name
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        LEFT JOIN stores billing_store ON billing_store.id = poi.bill_to_store_id
        WHERE poi.order_id = ?
        ORDER BY p.name ASC
    ');
    $items->execute([$order_id]);
    $items = $items->fetchAll();
    $receiptStmt = $pdo->prepare('
        SELECT id, order_item_id, snipe_asset_id, serial, asset_tag, destination, disposition, created_at
        FROM purchase_order_asset_receipts
        WHERE order_id = ?
        ORDER BY created_at ASC, id ASC
    ');
    $receiptStmt->execute([$order_id]);
    $receiptsByItem = [];
    foreach ($receiptStmt->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
        $receiptsByItem[(int)$receipt['order_item_id']][] = $receipt;
    }
    foreach ($items as &$item) {
        $item['tracked_receipts'] = $receiptsByItem[(int)$item['id']] ?? [];
    }
    unset($item);
    echo json_encode([
        'success' => true,
        'order' => $order,
        'items' => $items
    ]);
} catch (Throwable $e) {
    api_fail($e, 'order_details_api_error', [
        'order_id' => $order_id,
        'user_id' => (int)$_SESSION['uid'],
    ], 'Unable to retrieve order details. Please try again.');
}
