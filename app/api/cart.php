<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/cart.php';
require_once __DIR__ . '/../includes/cart_service.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$pdo = db();
$current_user_id = $_SESSION['uid'];
$action = req_action();
$max_note_length = (int)get_app_setting('stock_max_note_length', '500');
if ($max_note_length < 50) {
    $max_note_length = 50;
}
$max_note_length = min($max_note_length, 255);

try {
    $maxPerItem = get_max_order_quantity();
    cart_require_write_request($action);
    $in = cart_input($action, $maxPerItem, $max_note_length);
    $values = [];
    if (in_array($action, ['add', 'update'], true)) {
        cart_validate_store($pdo, $in['bill_to_store_id']);
        $split = normalize_receive_split(
            $in['quantity'],
            $in['receive_to_van_qty'] ?? null,
            $in['receive_to_region_qty'] ?? null,
            !empty($_SESSION['region_id'])
        );
        $values = cart_item_values($in, $split);
    }
    switch ($action) {
        case 'add':
            $product_check = $pdo->prepare('SELECT id, status, product_id as sku FROM products WHERE id = ?');
            $product_check->execute([$in['product_id']]);
            $product = $product_check->fetch();
            if (!$product) {
                throw new NotFoundException('Product not found');
            }
            if (($product['status'] ?? '') !== 'active') {
                throw new CartValidationException("{$product['sku']} is not currently orderable.");
            }

            // Add or update cart item
            // One cart line per product and billing store: the key column carries the store (0 for none), so adding
            // the product for another store makes its own line instead of rebilling the existing one.
            $stmt = $pdo->prepare('INSERT INTO cart_items (user_id, product_id, quantity, notes, bill_to_store_id, receive_to_van_qty, receive_to_region_qty, bill_to_store_key)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE
                                   quantity = quantity + VALUES(quantity),
                                   notes = VALUES(notes),
                                   receive_to_van_qty = receive_to_van_qty + VALUES(receive_to_van_qty),
                                   receive_to_region_qty = receive_to_region_qty + VALUES(receive_to_region_qty),
                                   updated_at = CURRENT_TIMESTAMP');
            cart_resync_key($pdo, (int)$current_user_id, $in['product_id'], (int)($values[2] ?? 0));
            $stmt->execute([$current_user_id, $in['product_id'], ...$values, (int)($values[2] ?? 0)]);
            api_success(null, 'Item added to cart');

            break;
        case 'update':
            $owned = $pdo->prepare('SELECT id FROM cart_items WHERE id = ? AND user_id = ?');
            $owned->execute([$in['cart_item_id'], $current_user_id]);
            if (!$owned->fetch()) {
                throw new NotFoundException('Cart item not found');
            }
            if ($in['quantity'] === 0) {
                $stmt = $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?');
                $stmt->execute([$in['cart_item_id'], $current_user_id]);
                api_success(null, 'Item removed from cart');
            }
            $stmt = $pdo->prepare('UPDATE cart_items SET quantity = ?, notes = ?, bill_to_store_id = ?,
                                  receive_to_van_qty = ?, receive_to_region_qty = ?, bill_to_store_key = ?, updated_at = CURRENT_TIMESTAMP
                                  WHERE id = ? AND user_id = ?');
            cart_update_line($stmt, [...$values, (int)($values[2] ?? 0), $in['cart_item_id'], $current_user_id]);
            api_success(null, 'Cart updated');

            break;
        case 'remove':
            $stmt = $pdo->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?');
            $stmt->execute([$in['cart_item_id'], $current_user_id]);
            api_success(null, 'Item removed from cart');

            break;
        case 'get':
            $cart_items = $pdo->prepare('
                SELECT ci.id, ci.quantity, ci.notes, ci.updated_at, ci.bill_to_store_id,
                       ci.receive_to_van_qty, ci.receive_to_region_qty,
                       p.id as product_id, p.product_id as sku, p.name, p.image_path,
                       COALESCE(vs.quantity, 0) as van_stock,
                       COALESCE(rs.quantity, 0) as region_stock,
                       s.name as bill_to_store_name
                FROM cart_items ci
                JOIN products p ON ci.product_id = p.id
                LEFT JOIN stores s ON ci.bill_to_store_id = s.id
                LEFT JOIN (
                    SELECT product_id, SUM(quantity) AS quantity FROM van_stock
                    WHERE user_id = ? GROUP BY product_id
                ) vs ON p.id = vs.product_id
                LEFT JOIN (
                    SELECT product_id, SUM(quantity) AS quantity FROM region_storage
                    WHERE region = ? GROUP BY product_id
                ) rs ON p.id = rs.product_id
                WHERE ci.user_id = ?
                ORDER BY ci.updated_at DESC
            ');
            $cart_items->execute([$current_user_id, $_SESSION['region'] ?? '', $current_user_id]);
            api_success($cart_items->fetchAll(), 'Cart items retrieved');

            break;
        case 'count':
            $count = $pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ?');
            $count->execute([$current_user_id]);
            api_success(['count' => (int)$count->fetchColumn()], 'Cart count retrieved');

            break;
        case 'clear':
            $stmt = $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?');
            $stmt->execute([$current_user_id]);
            api_success(null, 'Cart cleared');

            break;
        case 'bulk_add':
            $items = $in['items'];

            $added = 0;
            $errors = [];
            foreach ($items as $item) {
                $sku = $item['sku'];
                $quantity = $item['quantity'];

                $product_check = $pdo->prepare('SELECT id, status FROM products WHERE product_id = ?');
                $product_check->execute([$sku]);
                $product = $product_check->fetch();
                if (!$product) {
                    $errors[] = "Product not found: {$sku}";
                    continue;
                }

                if (($product['status'] ?? '') !== 'active') {
                    $errors[] = "{$sku} is not currently orderable.";
                    continue;
                }

                $stmt = $pdo->prepare('INSERT INTO cart_items (user_id, product_id, quantity)
                                       VALUES (?, ?, ?)
                                       ON DUPLICATE KEY UPDATE
                                       quantity = quantity + VALUES(quantity)');
                $stmt->execute([$current_user_id, $product['id'], $quantity]);
                $added++;
            }

            $message = "Added {$added} items to cart";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode(', ', $errors);
            }

            api_success(['added' => $added, 'errors' => $errors], $message);

            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Exception $e) {
    // api_fail() answers every ClientError itself (status, details, endpoint context), so this
    // catch does not hand them to the boundary via qt_rethrow_bad_input().
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    api_fail($e, 'cart_api_error', [
        'action' => $action,
        'user_id' => $current_user_id,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ], 'Cart operation failed. Please try again.');
}
