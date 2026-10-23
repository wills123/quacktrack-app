<?php

require_once __DIR__ . '/config.php';
require_login();

$pdo = db();
$current_user_id = $_SESSION['uid'];
$is_admin = has_permission('admin.access');

// Check if user needs to complete profile
$profile_status = needs_profile_completion($current_user_id);
$needs_profile_completion = $profile_status['needs_completion'];

// Fetch available regions if profile needs completion
if ($needs_profile_completion) {
    $regions_stmt = $pdo->prepare('SELECT id, name FROM regions WHERE allow_registration = 1 ORDER BY name');
    $regions_stmt->execute();
    $available_regions = $regions_stmt->fetchAll();
}

// Fetch user's low stock threshold
$threshold_stmt = $pdo->prepare('SELECT low_stock_threshold FROM users WHERE id = ?');
$threshold_stmt->execute([$current_user_id]);
$threshold_row = $threshold_stmt->fetch();
$low_stock_threshold = (int)($threshold_row['low_stock_threshold'] ?? 5);

// Fetch van stock summary (top items with totals per product)
$van_stock_summary = $pdo->prepare('
    SELECT p.name, p.product_id as sku, SUM(vs.quantity) as total_qty,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ", ") as stores
    FROM van_stock vs
    JOIN products p ON p.id = vs.product_id
    LEFT JOIN stores s ON vs.store_id = s.id
    WHERE vs.user_id = ? AND vs.quantity > 0 AND p.is_tracked_asset = 0
    GROUP BY p.id, p.name, p.product_id
    ORDER BY total_qty DESC
    LIMIT 10
');
$van_stock_summary->execute([$current_user_id]);
$van_stock_summary = $van_stock_summary->fetchAll();

// Stat strip — total items, total qty, low stock count, out of stock count
$stats_stmt = $pdo->prepare('
    SELECT
        COUNT(DISTINCT product_id)                                                  AS total_items,
        COALESCE(SUM(item_qty), 0)                                                  AS total_qty,
        COUNT(DISTINCT CASE WHEN item_qty > 0 AND item_qty <= ? THEN product_id END) AS low_count,
        COUNT(DISTINCT CASE WHEN item_qty = 0 THEN product_id END)                  AS out_count
    FROM (
        SELECT vs.product_id, SUM(vs.quantity) AS item_qty
        FROM van_stock vs
        JOIN products p ON p.id = vs.product_id
        WHERE vs.user_id = ? AND p.is_tracked_asset = 0
        GROUP BY vs.product_id
    ) totals
');
$stats_stmt->execute([$low_stock_threshold, $current_user_id]);
$stats = $stats_stmt->fetch();

// Low/out stock items for alerts card
$alerts_stmt = $pdo->prepare('
    SELECT p.name, p.product_id as sku, SUM(vs.quantity) as total_qty
    FROM van_stock vs
    JOIN products p ON p.id = vs.product_id
    WHERE vs.user_id = ? AND p.is_tracked_asset = 0
    GROUP BY p.id, p.name, p.product_id
    HAVING total_qty <= ?
    ORDER BY total_qty ASC
    LIMIT 8
');
$alerts_stmt->execute([$current_user_id, $low_stock_threshold]);
$alert_items = $alerts_stmt->fetchAll();

// Pending cart count
$cart_stmt = $pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ?');
$cart_stmt->execute([$current_user_id]);
$cart_count = (int)$cart_stmt->fetchColumn();

// Recent transactions (last 5)
$recent_transactions = $pdo->prepare('
    SELECT t.created_at, t.change_qty, t.reason, p.name, p.product_id as sku
    FROM transactions t
    JOIN products p ON t.product_id = p.id
    WHERE t.user_id = ? AND p.is_tracked_asset = 0
    ORDER BY t.created_at DESC LIMIT 5
');
$recent_transactions->execute([$current_user_id]);
$recent_transactions = $recent_transactions->fetchAll();

require __DIR__ . '/views/index.view.php';
