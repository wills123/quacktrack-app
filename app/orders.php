<?php

require_once __DIR__ . '/config.php';
require_login();

$pdo = db();
$current_user_id = $_SESSION['uid'];
$current_region_id = $_SESSION['region_id'] ?? null;
$can_view_all_orders = has_permission('orders.view_all');
$can_view_region_orders = has_permission('orders.view_region');
$can_manage_orders = has_permission('orders.manage');
$can_manage_assets = has_permission('assets.manage');
$page = max(req_int($_GET, 'page', 1), 1);
$limit = max(1, min(req_int($_GET, 'limit', 25), 100));
$offset = ($page - 1) * $limit;

$availableScopes = ['own' => 'My Orders'];
if ($can_view_region_orders) {
    $availableScopes['region'] = 'My Region';
}
if ($can_view_all_orders) {
    $availableScopes['all'] = 'All Orders';
}

$scope = req_string($_GET, 'scope', (string)array_key_first($availableScopes));
if (!array_key_exists($scope, $availableScopes)) {
    $scope = array_key_first($availableScopes);
}
if ($scope === 'all' && !$can_view_all_orders) {
    $scope = array_key_first($availableScopes);
}
if ($scope === 'region' && !$can_view_region_orders) {
    $scope = array_key_first($availableScopes);
}

$selected_user_id = $current_user_id;
$order_user_options = [];

if ($scope === 'all' && $can_view_all_orders) {
    $selected_user_id = max(0, req_int($_GET, 'user_id'));
    $users_stmt = $pdo->query("
        SELECT id, email, first_name, last_name, username, role,
               COALESCE(NULLIF(username, ''), NULLIF(CONCAT_WS(' ', first_name, last_name), ''), email) AS display_name
        FROM users
        ORDER BY email ASC
    ");
    $order_user_options = $users_stmt->fetchAll();
    $valid_user_ids = array_column($order_user_options, 'id');

    if ($selected_user_id > 0 && !in_array($selected_user_id, $valid_user_ids, true)) {
        $selected_user_id = 0;
    }
}

// Handle reorder action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    csrf_verify();
    $order_id = req_int($_POST, 'order_id');

    if ($order_id > 0) {
        try {
            // Get order items (allow admins to reorder on behalf of others)
            $items = $pdo->prepare('
                SELECT poi.product_id, poi.quantity, poi.notes, poi.bill_to_store_id,
                       poi.receive_to_van_qty, poi.receive_to_region_qty, po.user_id
                FROM purchase_order_items poi
                JOIN purchase_orders po ON poi.order_id = po.id
                WHERE po.id = ?
            ');
            $items->execute([$order_id]);
            $items = $items->fetchAll();

            if (!empty($items)) {
                // Add items to cart
                $cart_stmt = $pdo->prepare('
                    INSERT INTO cart_items
                        (user_id, product_id, quantity, notes, bill_to_store_id, bill_to_store_key, receive_to_van_qty, receive_to_region_qty)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                    notes = VALUES(notes),
                    receive_to_van_qty = receive_to_van_qty + VALUES(receive_to_van_qty),
                    receive_to_region_qty = receive_to_region_qty + VALUES(receive_to_region_qty)
                ');

                foreach ($items as $item) {
                    $cart_stmt->execute([
                        $current_user_id,
                        $item['product_id'],
                        $item['quantity'],
                        $item['notes'],
                        $item['bill_to_store_id'],
                        (int)($item['bill_to_store_id'] ?? 0),
                        $item['receive_to_van_qty'],
                        $item['receive_to_region_qty']
                    ]);
                }

                flash('Items added to cart from previous order');
                header('Location: /cart');
                exit;
            }
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            flash('Error reordering: ' . $e->getMessage(), 'error');
        }
    }
}

// Determine filters for queries
$where_clause = '';
$where_params = [];

switch ($scope) {
    case 'all':
        if ($selected_user_id > 0) {
            $where_clause = 'WHERE po.user_id = ?';
            $where_params[] = $selected_user_id;
        }
        break;
    case 'region':
        if ($current_region_id) {
            $where_clause = 'WHERE u.region_id = ?';
            $where_params[] = $current_region_id;
        } else {
            $where_clause = 'WHERE po.user_id = ?';
            $where_params[] = $current_user_id;
        }
        break;
    case 'own':
    default:
        $where_clause = 'WHERE po.user_id = ?';
        $where_params[] = $current_user_id;
        break;
}

// Get total count for pagination
$joins = 'LEFT JOIN users u ON po.user_id = u.id';
$count_sql = 'SELECT COUNT(*) FROM purchase_orders po ' . $joins . ' ' . $where_clause;
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($where_params);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalOrders / $limit));

// Fetch paginated orders
$orders_sql = "
    SELECT po.*, s.name as store_name,
           COUNT(poi.id) as item_count,
           u.email as user_email,
           COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display,
           u.role as user_role
    FROM purchase_orders po
    LEFT JOIN stores s ON po.store_id = s.id
    LEFT JOIN purchase_order_items poi ON po.id = poi.order_id
    LEFT JOIN users u ON po.user_id = u.id
    " . $where_clause . "
    GROUP BY po.id
    ORDER BY po.created_at DESC
    LIMIT ? OFFSET ?
";
$ordersStmt = $pdo->prepare($orders_sql);
$ordersStmt->execute([...$where_params, $limit, $offset]);
$orders = $ordersStmt->fetchAll();

$pagination_query_params = ['limit' => $limit, 'scope' => $scope];
if ($scope === 'all' && $selected_user_id > 0) {
    $pagination_query_params['user_id'] = $selected_user_id;
}

$ordersPagination = [
    'page' => $page,
    'limit' => $limit,
    'total' => $totalOrders,
    'total_pages' => $totalPages,
    'has_prev' => $page > 1,
    'has_next' => $page < $totalPages,
    'query_params' => $pagination_query_params,
    'scope' => $scope,
    'selected_user_id' => $selected_user_id,
    'can_view_all' => $can_view_all_orders,
    'can_view_region' => $can_view_region_orders
];

$orderScopeOptions = $availableScopes;

// Status counts for stat strip (same scope filter)
$statusCountSql = "
    SELECT po.status, COUNT(*) as cnt
    FROM purchase_orders po
    LEFT JOIN users u ON po.user_id = u.id
    $where_clause
    GROUP BY po.status
";
$statusCountStmt = $pdo->prepare($statusCountSql);
$statusCountStmt->execute($where_params);
$statusCounts = [];
foreach ($statusCountStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$draftCount    = $statusCounts['draft']     ?? 0;
$sentCount     = $statusCounts['sent']      ?? 0;
$receivedCount = $statusCounts['received']  ?? 0;

$main_class = 'main-content';

require __DIR__ . '/views/orders.view.php';
