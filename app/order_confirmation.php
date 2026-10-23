<?php

require_once __DIR__ . '/config.php';
require_login();

$pdo = db();
$current_user_id = $_SESSION['uid'];
$order_id = req_int($_GET, 'order_id');

if ($order_id <= 0) {
    header('Location: /cart.php');
    exit;
}

// Get order details
$order = $pdo->prepare("
    SELECT po.*, s.name as store_name, u.email as user_email,
           COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display
    FROM purchase_orders po
    LEFT JOIN stores s ON po.store_id = s.id
    LEFT JOIN users u ON po.user_id = u.id
    WHERE po.id = ? AND po.user_id = ?
");
$order->execute([$order_id, $current_user_id]);
$order = $order->fetch();

if (!$order) {
    flash('Order not found', 'error');
    header('Location: /cart.php');
    exit;
}

// Get order items
$order_items = $pdo->prepare('
    SELECT poi.*, p.product_id as sku, p.name, p.image_path
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.order_id = ?
    ORDER BY p.name ASC
');
$order_items->execute([$order_id]);
$order_items = $order_items->fetchAll();

// Address the checkout email went to isn't stored on the order, so resolve it
// the same way checkout does: the user's preferred address, else the default.
$emailStmt = $pdo->prepare('
    SELECT pe.email
    FROM purchasing_emails pe
    WHERE pe.is_active = 1
      AND pe.id = COALESCE(
          (SELECT u.preferred_purchasing_email_id FROM users u WHERE u.id = ?),
          (SELECT id FROM purchasing_emails WHERE is_default = 1 AND is_active = 1 LIMIT 1),
          (SELECT id FROM purchasing_emails WHERE is_active = 1 ORDER BY sort_order LIMIT 1)
      )
');
$emailStmt->execute([$current_user_id]);
$purchasing_email = (string)($emailStmt->fetchColumn() ?: env('PURCHASING_EMAIL', ''));

$extra_styles = ['/assets/pages/stock.css', '/assets/pages/orders.css', '/assets/pages/order_confirmation.css'];
require __DIR__ . '/views/order_confirmation.view.php';
