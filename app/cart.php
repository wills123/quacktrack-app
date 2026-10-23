<?php

require_once __DIR__ . '/config.php';
require_login();
$pdo = db();
$current_user_id = $_SESSION['uid'];
$max_note_length = (int)get_app_setting('stock_max_note_length', '500');
if ($max_note_length < 50) {
    $max_note_length = 50;
}
$max_note_length = min($max_note_length, 5000);
// Get cart items with product details, stock info, and billing store
$cart_items = $pdo->prepare('
    SELECT ci.id, ci.quantity, ci.notes, ci.updated_at, ci.bill_to_store_id,
           ci.receive_to_van_qty, ci.receive_to_region_qty,
           p.id as product_id, p.product_id as sku, p.name, p.image_path,
           COALESCE(SUM(vs.quantity), 0) as van_stock,
           COALESCE(MAX(rs.quantity), 0) as region_stock,
           s.name as bill_to_store_name
    FROM cart_items ci
    JOIN products p ON ci.product_id = p.id
    LEFT JOIN stores s ON ci.bill_to_store_id = s.id
    LEFT JOIN van_stock vs ON p.id = vs.product_id AND vs.user_id = ?
    LEFT JOIN region_storage rs ON p.id = rs.product_id AND rs.region = ?
    WHERE ci.user_id = ?
    GROUP BY ci.id, ci.quantity, ci.notes, ci.updated_at, ci.bill_to_store_id,
             p.id, p.product_id, p.name, p.image_path, s.name
    ORDER BY ci.updated_at DESC
');
$cart_items->execute([$current_user_id, $_SESSION['region'] ?? '', $current_user_id]);
$cart_items = $cart_items->fetchAll();
// Get all stores for dropdown
$stores = $pdo->query('SELECT id, name FROM stores WHERE id > 1 ORDER BY name ASC')->fetchAll();
$main_class = 'main-content';
require __DIR__ . '/views/cart.view.php';
