<?php

require_once 'config.php';
require_once __DIR__ . '/includes/csv_export.php';
require_login();

$pdo = db();
$current_user_id = (int)($_SESSION['uid'] ?? 0);
$can_view_reports = has_permission('reports.view');
$canViewAll       = has_permission('assets.reports'); // Snipe-IT reports permission
$snipe_enabled    = env('SNIPE_IT_ENABLED', '0') === '1'
                    && env('SNIPE_IT_API_URL', '') !== ''
                    && env('SNIPE_IT_API_KEY', '') !== '';

// ── Tab ────────────────────────────────────────────────────────────
$allowed_tabs = ['store_usage', 'all', 'equipment'];
// Remove equipment tab if Snipe-IT not configured
if (!$snipe_enabled) {
    $allowed_tabs = array_diff($allowed_tabs, ['equipment']);
}
$tab = req_raw_string($_GET, 'tab', 'store_usage');
$tab = in_array($tab, $allowed_tabs, true) ? $tab : 'store_usage';

// All Activity tab requires reports.view permission
if ($tab === 'all' && !$can_view_reports) {
    $tab = 'store_usage';
}

// ── Pagination ─────────────────────────────────────────────────────
$page  = max(1, req_int($_GET, 'page', 1));
$limit = req_int($_GET, 'limit', 50);
$limit = in_array($limit, [25, 50, 100], true) ? $limit : 50;
$offset = ($page - 1) * $limit;

// ── Date range ─────────────────────────────────────────────────────
$default_start = date('Y-m-d', strtotime('-30 days'));
$default_end   = date('Y-m-d');
$date_regex    = '/^\d{4}-\d{2}-\d{2}$/';

$start_param = req_raw_string($_GET, 'start_date');
$end_param   = req_raw_string($_GET, 'end_date');
$start_date = preg_match($date_regex, $start_param) ? $start_param : $default_start;
$end_date   = preg_match($date_regex, $end_param) ? $end_param : $default_end;

// Ensure start <= end
if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$start_dt = $start_date . ' 00:00:00';
$end_dt   = $end_date   . ' 23:59:59';

// ── Filters ────────────────────────────────────────────────────────
$store_id = req_int($_GET, 'store_id');
$default_reason = ($tab === 'store_usage') ? 'use' : 'all';
$reason   = strtolower(req_string($_GET, 'reason', (string)$default_reason));
$allowed_reasons = ['add', 'use', 'adjust', 'transfer', 'return', 'damage', 'loss', 'all'];
if (!in_array($reason, $allowed_reasons, true)) {
    $reason = 'use';
}
$scope = $can_view_reports ? ($_GET['scope'] ?? 'own') : 'own';
$scope = ($scope === 'all' && $can_view_reports) ? 'all' : 'own';
$export = $_GET['export'] ?? null;

// ── Store list ─────────────────────────────────────────────────────
$stores = $pdo->query('SELECT id, name FROM stores ORDER BY name ASC')->fetchAll();

// ── WHERE builder (for van_stock_transactions tabs) ────────────────
$where  = [];
$params = [];

// Supply reports exclude serialized equipment; Snipe-IT activity is shown in the equipment tab.
$where[] = 'EXISTS (SELECT 1 FROM products p_filter WHERE p_filter.id = vst.product_id AND p_filter.is_tracked_asset = 0)';

if ($scope === 'own') {
    $where[]  = 'vst.user_id = ?';
    $params[] = $current_user_id;
}
$where[]  = 'vst.created_at BETWEEN ? AND ?';
$params[] = $start_dt;
$params[] = $end_dt;

if ($store_id > 0) {
    $where[]  = 'vst.target_store_id = ?';
    $params[] = $store_id;
}
if ($reason !== 'all') {
    $where[]  = 'vst.reason = ?';
    $params[] = $reason;
}
if ($tab === 'store_usage') {
    $where[] = 'vst.target_store_id IS NOT NULL';
    $where[] = '(vst.source_store_id IS NULL OR vst.source_store_id != vst.target_store_id)';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── Supply data (store_usage + all tabs) ───────────────────────────
$usage_rows    = [];
$usage_summary = [];
$total_records = 0;
$total_pages   = 1;

if ($tab !== 'equipment') {
    // Total count for pagination
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM van_stock_transactions vst {$where_sql}");
    $count_stmt->execute($params);
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages   = max(1, (int)ceil($total_records / $limit));
    $page          = min($page, $total_pages);
    $offset        = ($page - 1) * $limit;

    // Detail rows
    $detail_stmt = $pdo->prepare("
        SELECT vst.*, p.name AS product_name, p.product_id AS sku, p.id AS product_db_id,
               ss.name AS source_store_name, ts.name AS target_store_name,
               u.email AS user_email,
               COALESCE(NULLIF(u.username,''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name),''), u.email) AS user_display
        FROM van_stock_transactions vst
        JOIN products p ON p.id = vst.product_id
        LEFT JOIN stores ss ON ss.id = vst.source_store_id
        LEFT JOIN stores ts ON ts.id = vst.target_store_id
        LEFT JOIN users u ON u.id = vst.user_id
        {$where_sql}
        ORDER BY vst.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $detail_stmt->execute(array_merge($params, [$limit, $offset]));
    $usage_rows = $detail_stmt->fetchAll();

    // Summary (store_usage tab only)
    if ($tab === 'store_usage') {
        $summary_stmt = $pdo->prepare("
            SELECT ts.name AS target_store_name, ts.id AS target_store_id,
                   ss.name AS source_store_name, ss.id AS source_store_id,
                   p.name AS product_name, p.product_id AS sku, p.id AS product_db_id,
                   SUM(ABS(vst.quantity_change)) AS total_qty
            FROM van_stock_transactions vst
            JOIN products p ON p.id = vst.product_id
            LEFT JOIN stores ss ON ss.id = vst.source_store_id
            LEFT JOIN stores ts ON ts.id = vst.target_store_id
            {$where_sql}
            GROUP BY ts.id, ts.name, ss.id, ss.name, p.id, p.product_id, p.name
            ORDER BY ts.name ASC, p.name ASC
        ");
        $summary_stmt->execute($params);
        $usage_summary = $summary_stmt->fetchAll();
    }
}

// ── Equipment checkouts (Snipe-IT) ─────────────────────────────────
$equipment_rows = [];
$snipe_error    = null;

if ($tab === 'equipment' && $snipe_enabled) {
    try {
        require_once __DIR__ . '/includes/SnipeItClient.php';
        $client = SnipeItClient::getInstance();
        $client->setCurrentUserId($current_user_id);

        $snipe_params = ['limit' => 500, 'offset' => 0];
        if (!empty($start_date)) {
            $snipe_params['start_date'] = $start_date;
        }
        if (!empty($end_date)) {
            $snipe_params['end_date']   = $end_date;
        }

        $activities = $client->listActivities($snipe_params);
        if ($activities !== null) {
            $snipe_user_id = (int)($_SESSION['snipe_user_id'] ?? 0);
            $raw = $activities['rows'] ?? [];

            if (!$canViewAll) {
                $raw = $snipe_user_id > 0
                    ? array_filter($raw, fn($r) => (int)($r['target']['id'] ?? $r['user_id'] ?? 0) === $snipe_user_id)
                    : [];
            }

            $equipment_rows = array_map(function ($row) {
                // Resolve user name: target (who it was checked out to) preferred over admin (who did it)
                $targetName = $row['target']['name'] ?? null;
                $adminName  = $row['admin']['name']  ?? null;
                if ($targetName !== null && !is_numeric(trim((string)$targetName))) {
                    $userName = $targetName;
                } elseif ($adminName !== null && !is_numeric(trim((string)$adminName))) {
                    $userName = $adminName;
                } else {
                    $userName = '—';
                }

                // Normalize datetime fields
                $normalizeDate = function ($val) {
                    if (!isset($val)) {
                        return '';
                    }
                    if (is_array($val)) {
                        return $val['datetime'] ?? $val['formatted'] ?? '';
                    }
                    return (string)$val;
                };

                return [
                    // Asset / item
                    'item_name'    => $row['item']['name']     ?? $row['item_name']  ?? 'Unknown',
                    'item_type'    => $row['item']['type']     ?? '',
                    'asset_tag'    => $row['item']['asset_tag'] ?? '',

                    // Action
                    'action_type'  => $row['action_type'] ?? $row['action'] ?? '—',

                    // Who performed the action (admin/technician)
                    'admin_name'   => $adminName ?? '—',

                    // Who/what it was checked out to (target)
                    'target_name'  => $targetName ?? '—',
                    'target_type'  => $row['target']['type'] ?? '',

                    // Location at time of action
                    'location'     => $row['location']['name'] ?? '',

                    // Note attached to the action
                    'note'         => $row['note'] ?? '',

                    // Timestamps
                    'created_at'   => $normalizeDate($row['created_at'] ?? null),
                    'updated_at'   => $normalizeDate($row['updated_at'] ?? null),
                ];
            }, array_values($raw));
        }
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::error('reports_equipment', $e, ['tab' => 'equipment']);
        $snipe_error = 'Could not connect to Snipe-IT. ' . (env('APP_ENV') === 'dev' ? $e->getMessage() : '');
    }
}

// ── CSV exports (supply tabs only, manager+) ───────────────────────
if ($export && $can_view_reports && $tab !== 'equipment') {
    $tab_label  = ($tab === 'store_usage') ? 'billable' : 'all';
    $scope_slug = $scope === 'all' ? 'all-techs' : 'me';
    $base_name  = "usage_{$tab_label}_{$scope_slug}_{$start_date}_to_{$end_date}";

    if ($export === 'summary' && $tab === 'store_usage') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$base_name}_summary.csv\"");
        $out = csv_open_output();
        csv_write_row($out, ['Used At', 'From', 'Product', 'SKU', 'Total Qty']);
        foreach ($usage_summary as $row) {
            csv_write_row($out, [
                $row['target_store_name'] ?? 'Unassigned',
                $row['source_store_name'] ?? 'Van',
                $row['product_name'] ?? '',
                $row['sku'] ?? '',
                (int)$row['total_qty'],
            ]);
        }
        fclose($out);
        exit;
    }

    if ($export === 'detailed') {
        $export_stmt = $pdo->prepare("
            SELECT vst.*, p.name AS product_name, p.product_id AS sku,
                   ss.name AS source_store_name, ts.name AS target_store_name,
                   u.email AS user_email,
                   COALESCE(NULLIF(u.username,''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name),''), u.email) AS user_display
            FROM van_stock_transactions vst
            JOIN products p ON p.id = vst.product_id
            LEFT JOIN stores ss ON ss.id = vst.source_store_id
            LEFT JOIN stores ts ON ts.id = vst.target_store_id
            LEFT JOIN users u ON u.id = vst.user_id
            {$where_sql}
            ORDER BY vst.created_at DESC
        ");
        $export_stmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$base_name}_detailed.csv\"");
        $out = csv_open_output();
        csv_write_row($out, ['Date', 'User', 'Product', 'SKU', 'Change', 'Reason', 'Source Store', 'Target Store', 'Note']);
        foreach ($export_stmt->fetchAll() as $row) {
            csv_write_row($out, [
                $row['created_at'] ?? '',
                $row['user_display'] ?? $row['user_email'] ?? '',
                $row['product_name'] ?? '',
                $row['sku'] ?? '',
                (int)$row['quantity_change'],
                $row['reason'] ?? '',
                $row['source_store_name'] ?? '',
                $row['target_store_name'] ?? '',
                $row['note'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}

// ── Pagination info ────────────────────────────────────────────────
$pagination = [
    'page'        => $page,
    'limit'       => $limit,
    'total'       => $total_records,
    'total_pages' => $total_pages,
    'has_prev'    => $page > 1,
    'has_next'    => $page < $total_pages,
    'start_item'  => $total_records > 0 ? $offset + 1 : 0,
    'end_item'    => min($offset + $limit, $total_records),
];

$title      = 'Reports';
$main_class = 'main-content';

require VIEW_ROOT . '/reports.view.php';
