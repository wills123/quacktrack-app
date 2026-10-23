<?php

require_once __DIR__ . '/config.php';
require __DIR__ . '/includes/van_stock_manager.php';
require_once __DIR__ . '/includes/SnipeItClient.php';
require_login();

// Set JSON header early for AJAX requests to ensure clean responses
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    // Ensure we're sending JSON even if there are issues
    header('Content-Type: application/json; charset=utf-8');
}

$pdo = db();
$current_user_id = $_SESSION['uid'];

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
    $stmt = $pdo->prepare("SELECT id, email, first_name, last_name, username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        return [
            'id' => $user_id,
            'display_name' => 'Unknown User',
            'email' => ''
        ];
    }
    $display = $user['username']
        ?: trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
        ?: $user['email'];
    return [
        'id' => (int)$user['id'],
        'display_name' => $display,
        'email' => $user['email']
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

// Handle API request for product list (GET requests don't need CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_products') {
    header('Content-Type: application/json');

    try {
        // Get optional search parameter for server-side filtering
        $search = req_string($_GET, 'search');

        // Limit results for performance when not searching
        $limit = req_int($_GET, 'limit', 100);
        $limit = max(1, min($limit, 500)); // Clamp between 1 and 500

        if ($search !== '') {
            // Server-side search: filter by name or product_id (SKU)
            // Use LIKE for partial matching, escape special characters
            $searchTerm = '%' . $search . '%';
            $stmt = $pdo->prepare('
                SELECT id, product_id, name, image_path
                FROM products
                WHERE is_tracked_asset = 0
                  AND (name LIKE ? OR product_id LIKE ?)
                ORDER BY
                    CASE
                        WHEN name LIKE ? THEN 0
                        WHEN product_id LIKE ? THEN 1
                        ELSE 2
                    END,
                    name ASC
                LIMIT ?
            ');
            $exactMatch = $search . '%';
            $stmt->execute([$searchTerm, $searchTerm, $exactMatch, $exactMatch, $limit]);
        } else {
            // No search: return limited products sorted by name
            $stmt = $pdo->prepare('SELECT id, product_id, name, image_path FROM products WHERE is_tracked_asset = 0 ORDER BY name ASC LIMIT ?');
            $stmt->execute([$limit]);
        }

        $products = $stmt->fetchAll();

        api_success($products, 'Products retrieved successfully');
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        Logger::error('stock_get_products', $e, ['user_id' => $current_user_id]);
        api_handle_exception($e, 'stock_get_products');
    }
    exit;
}

// Page rendering: Fetch data for display
$search = req_string($_GET, 'search');
$status = req_string($_GET, 'status', 'all');
$location_id = req_int($_GET, 'location_id');
$category_id = req_int($_GET, 'category_id');
$sort = req_string($_GET, 'sort', 'name');

$allowed_status = ['all', 'in', 'low'];
if (!in_array($status, $allowed_status, true)) {
    $status = 'all';
}

$allowed_sort = ['name', 'qty', 'updated'];
if (!in_array($sort, $allowed_sort, true)) {
    $sort = 'name';
}

$has_category_column = stock_column_exists($pdo, 'products', 'category_id');
$has_categories = $has_category_column && stock_table_exists($pdo, 'product_categories');

$query = '
    SELECT
        p.id,
        p.product_id,
        p.name,
        p.image_path,
        ' . ($has_category_column ? 'p.category_id,' : 'NULL AS category_id,') . '
        ' . ($has_categories ? 'pc.name AS category_name,' : "NULL AS category_name,") . '
        v.quantity AS qty,
        v.updated_at,
        s.name AS store_name,
        s.id AS store_id
    FROM van_stock v
    JOIN products p ON v.product_id = p.id
    ' . ($has_categories ? 'LEFT JOIN product_categories pc ON p.category_id = pc.id' : '') . '
    LEFT JOIN stores s ON v.store_id = s.id
    WHERE v.user_id = ? AND p.is_tracked_asset = 0
';

$params = [$view_user_id];

if ($search !== '') {
    $query .= ' AND (p.name LIKE ? OR p.product_id LIKE ?)';
    $search_term = '%' . $search . '%';
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($location_id > 0) {
    $query .= ' AND v.store_id = ?';
    $params[] = $location_id;
}

if ($category_id > 0 && $has_categories) {
    $query .= ' AND p.category_id = ?';
    $params[] = $category_id;
}

$query .= ' ORDER BY p.name ASC, s.name ASC';

$products_stmt = $pdo->prepare($query);
$products_stmt->execute($params);
$products = $products_stmt->fetchAll();

// Serialized equipment is owned by Snipe-IT. Fetch only assets assigned to
// the selected van owner so this page can show a complete, read-only picture
// without turning those assets into quantity-stock rows.
$tracked_assets = [];
$tracked_assets_error = null;
$snipe_base_url = env('SNIPE_IT_OAUTH_BASE_URL', '');
if ($snipe_base_url === '') {
    $snipe_base_url = rtrim(preg_replace('#/api/v\\d+$#i', '', env('SNIPE_IT_API_URL', '')), '/');
}

if (
    env('SNIPE_IT_ENABLED', '0') === '1'
    && has_permission('assets.view')
    && stock_column_exists($pdo, 'users', 'snipe_user_id')
) {
    $snipe_user_stmt = $pdo->prepare('SELECT snipe_user_id FROM users WHERE id = ?');
    $snipe_user_stmt->execute([$view_user_id]);
    $snipe_user_id = (int)$snipe_user_stmt->fetchColumn();

    if ($snipe_user_id > 0) {
        try {
            $snipe_client = SnipeItClient::getInstance();
            $snipe_client->setCurrentUserId($current_user_id);
            $tracked_asset_response = $snipe_client->listUserAssets($snipe_user_id);
            $tracked_assets = $tracked_asset_response['rows'] ?? [];
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('stock_load_tracked_assets', $e, [
                'user_id' => $current_user_id,
                'view_user_id' => $view_user_id,
                'snipe_user_id' => $snipe_user_id,
            ]);
            $tracked_assets_error = 'Snipe-IT equipment is temporarily unavailable.';
        }
    }
}

if ($search !== '') {
    $tracked_assets = array_values(array_filter($tracked_assets, static function (array $asset) use ($search): bool {
        $searchable = [
            $asset['name'] ?? '',
            $asset['asset_tag'] ?? '',
            $asset['serial'] ?? '',
            $asset['model']['name'] ?? '',
            $asset['status_label']['name'] ?? '',
            $asset['location']['name'] ?? '',
        ];
        $haystack = implode(' ', array_map(static fn($value): string => (string)$value, $searchable));
        return stripos($haystack, $search) !== false;
    }));
}

// Fetch all stores for the dropdown (cached in session for performance)
if (!isset($_SESSION['cached_stores']) || empty($_SESSION['cached_stores'])) {
    $all_stores = $pdo->query('SELECT id, name FROM stores ORDER BY name ASC')->fetchAll();
    $_SESSION['cached_stores'] = $all_stores;
} else {
    $all_stores = $_SESSION['cached_stores'];
}

$location_options_stmt = $pdo->prepare('
    SELECT DISTINCT s.id, s.name
    FROM van_stock v
    JOIN products p ON p.id = v.product_id
    JOIN stores s ON v.store_id = s.id
    WHERE v.user_id = ? AND p.is_tracked_asset = 0
    ORDER BY s.name ASC
');
$location_options_stmt->execute([$view_user_id]);
$location_options = $location_options_stmt->fetchAll();

$category_options = [];
if ($has_categories) {
    $category_options = $pdo->query('SELECT id, name FROM product_categories WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
}

$all_products = $pdo->prepare('SELECT id, product_id, name FROM products WHERE is_tracked_asset = 0 ORDER BY name ASC');
$all_products->execute();
$all_products = $all_products->fetchAll();

$grouped_products = [];
$last_updated_at = null;
foreach ($products as $p) {
    $prod_id = (int)$p['id'];
    if (!isset($grouped_products[$prod_id])) {
        $grouped_products[$prod_id] = [
            'product_details' => $p,
            'total_qty' => 0,
            'stores' => [],
            'updated_at' => $p['updated_at']
        ];
    }

    $qty = (int)$p['qty'];
    $grouped_products[$prod_id]['total_qty'] += $qty;
    if ($qty > 0) {
        $grouped_products[$prod_id]['stores'][] = [
            'store_id' => $p['store_id'],
            'store_name' => $p['store_name'] ?? 'Unassigned',
            'qty' => $qty
        ];
    }

    if (!empty($p['updated_at'])) {
        $updated_at = $p['updated_at'];
        if ($last_updated_at === null || $updated_at > $last_updated_at) {
            $last_updated_at = $updated_at;
        }
        if ($grouped_products[$prod_id]['updated_at'] === null || $updated_at > $grouped_products[$prod_id]['updated_at']) {
            $grouped_products[$prod_id]['updated_at'] = $updated_at;
        }
    }
}

$summary_pool = array_values($grouped_products);
$summary = [
    'total_skus' => count($summary_pool),
    'total_units' => 0,
    'low_stock' => 0
];

foreach ($summary_pool as $item) {
    $total_qty = (int)$item['total_qty'];
    $summary['total_units'] += $total_qty;
    if ($total_qty <= $low_stock_threshold) {
        $summary['low_stock']++;
    }
}

$display_products = [];
foreach ($summary_pool as $item) {
    $total_qty = (int)$item['total_qty'];
    if ($total_qty <= 0) {
        continue;
    }
    if ($status === 'low' && $total_qty > $low_stock_threshold) {
        continue;
    }
    if ($status === 'in' && $total_qty <= $low_stock_threshold) {
        continue;
    }
    $display_products[] = $item;
}

usort($display_products, function ($a, $b) use ($sort) {
    if ($sort === 'qty') {
        return $b['total_qty'] <=> $a['total_qty'];
    }
    if ($sort === 'updated') {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    }
    return strcasecmp($a['product_details']['name'] ?? '', $b['product_details']['name'] ?? '');
});

$query_params = [
    'search' => $search,
    'status' => $status,
    'location_id' => $location_id ?: null,
    'category_id' => $category_id ?: null,
    'sort' => $sort
];

if ($viewing_other_user) {
    $query_params['van_user_id'] = $view_user_id;
}

require __DIR__ . '/views/stock.view.php';
