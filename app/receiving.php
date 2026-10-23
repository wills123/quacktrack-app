<?php

require_once __DIR__ . '/config.php';
require_login();

$pdo = db();
$userId = (int)$_SESSION['uid'];
$regionId = isset($_SESSION['region_id']) ? (int)$_SESSION['region_id'] : 0;

$canManageOrders = has_permission('orders.manage');
$canViewAll = $canManageOrders
    || has_permission('system.full_access')
    || has_permission('admin.access')
    || has_permission('orders.view_all');
$hasRegion = $regionId > 0;
$canViewRegionOrders = $hasRegion && has_permission('orders.view_region');
$canManageAssets = has_permission('assets.manage');

// Everything still waiting to be received that this user can see: their own
// orders, any order in their region that includes regional storage (anyone in
// the region can receive those), all of their region's orders if they have
// regional order visibility, or everything (managers).
$where = 'po.user_id = ?';
$params = [$userId];
if ($canViewAll) {
    $where = '1 = 1';
    $params = [];
} elseif ($canViewRegionOrders) {
    $where = '(po.user_id = ? OR u.region_id = ?)';
    $params = [$userId, $regionId];
} elseif ($hasRegion) {
    $where = '(po.user_id = ? OR (u.region_id = ? AND po.receive_to_region = 1))';
    $params = [$userId, $regionId];
}

$stmt = $pdo->prepare("
    SELECT po.id, po.order_number, po.created_at, po.status, po.user_id,
           po.receive_to_van, po.receive_to_region, u.region_id AS owner_region_id,
           COALESCE(NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.username, u.email) AS technician,
           s.name AS store_name,
           COUNT(poi.id) AS line_count,
           COALESCE(SUM(poi.quantity), 0) AS total_quantity,
           COUNT(DISTINCT CASE WHEN p.is_tracked_asset = 0 THEN poi.product_id END) AS supply_lines,
           COALESCE(SUM(CASE WHEN p.is_tracked_asset = 1 THEN poi.quantity END), 0) AS tracked_qty,
           COUNT(CASE WHEN p.is_tracked_asset = 1 THEN poi.id END) AS tracked_lines,
           (SELECT COUNT(DISTINCT r.product_id) FROM purchase_order_stock_receipts r WHERE r.order_id = po.id) AS supply_lines_received,
           (SELECT COUNT(*) FROM purchase_order_asset_receipts ar WHERE ar.order_id = po.id) AS tracked_matched
    FROM purchase_orders po
    JOIN users u ON u.id = po.user_id
    JOIN purchase_order_items poi ON poi.order_id = po.id
    JOIN products p ON p.id = poi.product_id
    LEFT JOIN stores s ON s.id = po.store_id
    WHERE {$where}
      AND po.status IN ('sent', 'partially_received')
    GROUP BY po.id
    ORDER BY po.created_at ASC
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Work out, per order, what this user may do. Mirrors the checks in api/orders.php.
foreach ($orders as &$order) {
    $isOwner = (int)$order['user_id'] === $userId;
    $storedVan = !empty($order['receive_to_van']);
    $storedRegion = !empty($order['receive_to_region']);
    $regionMatch = $hasRegion && (int)$order['owner_region_id'] === $regionId;
    // Anyone in the order's region can receive an order that includes regional
    // storage; the owner can receive an order that includes their van.
    $mayReceive = $canManageOrders
        || ($storedRegion && $regionMatch)
        || (($storedVan || !$storedRegion) && $isOwner);

    $order['destination'] = $storedVan && $storedRegion ? 'Van & region' : ($storedRegion ? 'Region stock' : 'Van stock');
    $order['supplies_pending'] = max(0, (int)$order['supply_lines'] - (int)$order['supply_lines_received']);
    $order['tracked_remaining'] = max(0, (int)$order['tracked_qty'] - (int)$order['tracked_matched']);
    $order['can_receive_supplies'] = $order['supplies_pending'] > 0 && $mayReceive;
    $order['can_match_tracked'] = (int)$order['tracked_lines'] > 0 && $order['tracked_remaining'] > 0 && (
        $isOwner || $canManageOrders || ($storedRegion && $regionMatch)
    );
    $order['can_cancel'] = $isOwner || $canManageOrders;
    $order['has_region'] = $storedRegion;
    // Managers post the order's own flags so a van/region split is honoured.
    $order['post_van'] = $storedVan || !$storedRegion;
    $order['post_region'] = $storedRegion;
}
unset($order);

$title = 'Receiving Inbox';
$main_class = 'main-content';
$extra_scripts = ['/assets/pages/receiving.js'];
require __DIR__ . '/views/receiving.view.php';
