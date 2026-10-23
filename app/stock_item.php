<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/inventory_ownership.php';
require_login();

$pdo = db();
$current_user_id = $_SESSION['uid'];

$product_id = req_int($_GET, 'product_id');
if ($product_id <= 0) {
    flash('Select a valid product to view details.', 'error');
    redirect('/stock');
}

$view_user_id = $current_user_id;
$viewing_other_user = false;
$requested_user_id = req_int($_GET, 'van_user_id');
if ($requested_user_id > 0 && $requested_user_id !== $current_user_id && is_admin()) {
    $view_user_id = $requested_user_id;
    $viewing_other_user = true;
}

function stock_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function stock_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function stock_get_user_display(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, username, region_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        return [
            'id' => $user_id,
            'display_name' => 'Unknown User',
            'email' => '',
            'region_id' => null
        ];
    }
    $display = $user['username']
        ?: trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
        ?: $user['email'];
    return [
        'id' => (int)$user['id'],
        'display_name' => $display,
        'email' => $user['email'],
        'region_id' => $user['region_id'] ?? null
    ];
}

function stock_get_low_stock_threshold(PDO $pdo, int $user_id): int
{
    if (!stock_column_exists($pdo, 'users', 'low_stock_threshold')) {
        return 5;
    }
    $stmt = $pdo->prepare('SELECT low_stock_threshold FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null) {
        return 5;
    }
    return max(1, (int)$value);
}

$view_user = stock_get_user_display($pdo, $view_user_id);
$low_stock_threshold = stock_get_low_stock_threshold($pdo, $view_user_id);
$max_note_length = (int)get_app_setting('stock_max_note_length', '500');
if ($max_note_length < 50) {
    $max_note_length = 50;
}
$max_note_length = min($max_note_length, 5000);
$has_categories = stock_table_exists($pdo, 'product_categories') && stock_column_exists($pdo, 'products', 'category_id');
$has_product_updated = stock_column_exists($pdo, 'products', 'updated_at');
$has_product_modified = stock_column_exists($pdo, 'products', 'modified_at');

$product_update_field = 'NULL AS product_updated_at';
if ($has_product_updated) {
    $product_update_field = 'p.updated_at AS product_updated_at';
} elseif ($has_product_modified) {
    $product_update_field = 'p.modified_at AS product_updated_at';
}

$product_sql = '
    SELECT p.id, p.product_id, p.name, p.image_path, p.is_tracked_asset,
           ' . $product_update_field . ',
           ' . ($has_categories ? 'pc.name AS category_name' : 'NULL AS category_name') . '
    FROM products p
    ' . ($has_categories ? 'LEFT JOIN product_categories pc ON pc.id = p.category_id' : '') . '
    WHERE p.id = ?
';
$product_stmt = $pdo->prepare($product_sql);
$product_stmt->execute([$product_id]);
$product = $product_stmt->fetch();

if (!$product) {
    flash('Product not found.', 'error');
    redirect('/stock');
}

if ((int)($product['is_tracked_asset'] ?? 0) === 1) {
    flash('This is a Snipe-IT asset. Open the Assets page to view or manage it.', 'warning');
    redirect('/stock');
}

$locations_stmt = $pdo->prepare('
    SELECT v.store_id, s.name AS store_name, s.store_number, v.quantity, v.updated_at
    FROM van_stock v
    LEFT JOIN stores s ON v.store_id = s.id
    WHERE v.user_id = ? AND v.product_id = ? AND v.quantity > 0
    ORDER BY CASE WHEN s.store_number IS NULL OR s.store_number = "" THEN 1 ELSE 0 END,
             s.store_number ASC, s.name ASC
');
$locations_stmt->execute([$view_user_id, $product_id]);
$locations = $locations_stmt->fetchAll();

$total_qty = 0;
$last_updated_at = null;
if (!empty($product['product_updated_at'])) {
    $last_updated_at = $product['product_updated_at'];
}
foreach ($locations as $row) {
    $total_qty += (int)$row['quantity'];
    if (!empty($row['updated_at']) && ($last_updated_at === null || $row['updated_at'] > $last_updated_at)) {
        $last_updated_at = $row['updated_at'];
    }
}

if ($total_qty <= 0) {
    flash('This item is not currently in the van stock list.', 'warning');
    redirect('/stock');
}

if ($total_qty <= $low_stock_threshold) {
    $status_label = 'Low';
    $status_class = 'stock-badge--low';
} else {
    $status_label = 'In stock';
    $status_class = 'stock-badge--in';
}

$all_stores = $pdo->query('
    SELECT id, name, store_number
    FROM stores
    ORDER BY CASE WHEN store_number IS NULL OR store_number = "" THEN 1 ELSE 0 END,
             store_number ASC, name ASC
')->fetchAll();

$van_users = [];
$users_stmt = $pdo->prepare('
    SELECT id, email, first_name, last_name, username
    FROM users
    WHERE id <> ?
    ORDER BY email ASC
');
$users_stmt->execute([$view_user_id]);
$van_users = $users_stmt->fetchAll();

$has_van_transactions = stock_table_exists($pdo, 'van_stock_transactions');
$transactions = [];

if ($has_van_transactions) {
    $transactions_stmt = $pdo->prepare('
        SELECT vst.id, vst.quantity_change, vst.reason, vst.note, vst.created_at,
               ss.name AS source_store_name,
               ts.name AS target_store_name,
               u.email AS user_email,
               COALESCE(NULLIF(u.username, ""), NULLIF(CONCAT_WS(" ", u.first_name, u.last_name), ""), u.email) AS user_display
        FROM van_stock_transactions vst
        LEFT JOIN stores ss ON ss.id = vst.source_store_id
        LEFT JOIN stores ts ON ts.id = vst.target_store_id
        LEFT JOIN users u ON u.id = vst.user_id
        WHERE vst.product_id = ? AND vst.user_id = ?
        ORDER BY vst.created_at DESC
        LIMIT 10
    ');
    $transactions_stmt->execute([$product_id, $view_user_id]);
    $transactions = $transactions_stmt->fetchAll();
} else {
    $transactions_stmt = $pdo->prepare('
        SELECT t.id, t.change_qty AS quantity_change, t.reason, t.note, t.created_at,
               s.name AS target_store_name,
               NULL AS source_store_name,
               u.email AS user_email,
               COALESCE(NULLIF(u.username, ""), NULLIF(CONCAT_WS(" ", u.first_name, u.last_name), ""), u.email) AS user_display
        FROM transactions t
        LEFT JOIN stores s ON s.id = t.store_id
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.product_id = ? AND t.user_id = ?
        ORDER BY t.created_at DESC
        LIMIT 10
    ');
    $transactions_stmt->execute([$product_id, $view_user_id]);
    $transactions = $transactions_stmt->fetchAll();
}

$title        = 'Item Details — ' . ($product['name'] ?? 'Van Stock');
$main_class   = 'main-content';
$extra_styles = ['/assets/opdash.css', '/assets/pages/stock_item.css'];
require __DIR__ . '/views/stock_item.view.php';
