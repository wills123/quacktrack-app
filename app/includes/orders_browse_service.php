<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/**
 * Browsing, reordering and cancelling purchase orders (the list, get, reorder and cancel actions of api/orders.php).
 *
 * A reorder and a cancellation run in one transaction on the rows they read, so two requests cannot both pass the
 * same check.
 */

/** Who is asking: the user, their region and which of the order permissions they hold. */
final class OrdersViewer
{
    public function __construct(
        public readonly int $userId,
        public readonly int $regionId,
        public readonly bool $canViewAll,
        public readonly bool $canViewRegion,
        public readonly bool $canManage
    ) {
    }
}

/** The FROM and WHERE of the order list: the caller's scope first, then the optional filters (0 or '' = not sent). */
const ORDERS_LIST_SCOPE = '
    FROM purchase_orders po
    LEFT JOIN stores s ON po.store_id = s.id
    LEFT JOIN users u ON po.user_id = u.id
    WHERE (? = 1 OR po.user_id = ? OR (? > 0 AND u.region_id = ?))
      AND (? = 0 OR po.user_id = ?)
      AND (? = \'\' OR po.status = ?)
      AND (? = 0 OR po.store_id = ?)';

/**
 * The parameters of ORDERS_LIST_SCOPE for this caller and these filters: each value then its comparison twin.
 * A user sees everything with orders.view_all, their own orders, and with orders.view_region those of their region.
 *
 * @param array<string, mixed> $filters
 * @return list<int|string>
 */
function orders_list_params(OrdersViewer $viewer, array $filters): array
{
    $all = $viewer->canViewAll ? 1 : 0;
    $region = $viewer->canViewRegion ? $viewer->regionId : 0;
    $user = (int)($filters['user_id'] ?? 0);
    $status = (string)($filters['status'] ?? '');
    $store = (int)($filters['store_id'] ?? 0);
    return [$all, $viewer->userId, $region, $region, $user, $user, $status, $status, $store, $store];
}

/**
 * One page of orders newest first, with the paging totals.
 *
 * @param array<string, mixed> $filters limit, page, status, store_id, user_id
 * @return array{orders: list<array<string, mixed>>, pagination: array<string, int|bool>}
 */
function orders_browse_list(PDO $pdo, OrdersViewer $viewer, array $filters): array
{
    $limit = (int)$filters['limit'];
    $page = (int)$filters['page'];
    $offset = ($page - 1) * $limit;
    $params = orders_list_params($viewer, $filters);
    $count = $pdo->prepare('SELECT COUNT(*) ' . ORDERS_LIST_SCOPE);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT po.*, s.name AS store_name,
               (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.order_id = po.id) AS item_count,
               u.email AS user_email,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display
        " . ORDERS_LIST_SCOPE . '
        ORDER BY po.created_at DESC, po.id DESC
        LIMIT ? OFFSET ?
    ');
    $stmt->execute([...$params, $limit, $offset]);
    return [
        'orders' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'has_more' => ($offset + $limit) < $total],
    ];
}

/**
 * One order with its lines. Anyone may read their own orders; orders.view_all reads every order.
 *
 * @return array{order: array<string, mixed>, items: list<array<string, mixed>>}
 * @throws NotFoundException
 */
function orders_browse_get(PDO $pdo, OrdersViewer $viewer, int $orderId): array
{
    $stmt = $pdo->prepare("
        SELECT po.*, s.name AS store_name, u.email AS user_email,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display
        FROM purchase_orders po
        LEFT JOIN stores s ON po.store_id = s.id
        LEFT JOIN users u ON po.user_id = u.id
        WHERE po.id = ? AND (? = 1 OR po.user_id = ?)
    ");
    $stmt->execute([$orderId, $viewer->canViewAll ? 1 : 0, $viewer->userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new NotFoundException('Order not found');
    }
    $items = $pdo->prepare('
        SELECT poi.product_id, poi.quantity, poi.notes, p.name, p.image_path
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.id
        WHERE poi.order_id = ?
        ORDER BY p.name ASC, poi.id ASC
    ');
    $items->execute([$orderId]);
    return ['order' => $order, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
}

/**
 * Run $work in a transaction; any failure rolls it back.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function orders_browse_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Put the lines of one of the caller's own orders into their cart (quantities add to what is already there); every
 * product must still be active or nothing is added. Returns the number of lines added.
 *
 * @throws NotFoundException|UnprocessableException
 */
function orders_browse_reorder(PDO $pdo, int $userId, int $orderId): int
{
    return orders_browse_transaction($pdo, static function () use ($pdo, $userId, $orderId): int {
        $order = $pdo->prepare('SELECT id FROM purchase_orders WHERE id = ? AND user_id = ? FOR SHARE');
        $order->execute([$orderId, $userId]);
        if (!$order->fetchColumn()) {
            throw new NotFoundException('Order not found');
        }
        $stmt = $pdo->prepare('
            SELECT poi.product_id, poi.quantity, poi.notes, poi.bill_to_store_id, poi.receive_to_van_qty, poi.receive_to_region_qty,
                   p.product_id AS sku, p.status
            FROM purchase_order_items poi
            LEFT JOIN products p ON poi.product_id = p.id
            WHERE poi.order_id = ?
            ORDER BY poi.id ASC
            FOR SHARE OF poi, p
        ');
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            throw new UnprocessableException('This order has no items to reorder.');
        }
        orders_browse_require_active($items);
        $cart = $pdo->prepare('
            INSERT INTO cart_items
                (user_id, product_id, quantity, notes, bill_to_store_id, bill_to_store_key, receive_to_van_qty, receive_to_region_qty)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                receive_to_van_qty = receive_to_van_qty + VALUES(receive_to_van_qty),
                receive_to_region_qty = receive_to_region_qty + VALUES(receive_to_region_qty),
                notes = VALUES(notes)
        ');
        foreach ($items as $item) {
            [$van, $region] = orders_browse_split($item);
            $billTo = $item['bill_to_store_id'] !== null ? (int)$item['bill_to_store_id'] : null;
            $cart->execute([$userId, $item['product_id'], $item['quantity'], $item['notes'], $billTo, (int)$billTo, $van, $region]);
        }
        return count($items);
    });
}

/**
 * Where a line's quantity is received: its own van and region quantities, or all to the van for an order placed
 * before the split existed (both zero), so the cart row's parts always add up to its quantity.
 *
 * @param array<string, mixed> $item
 * @return array{0: int, 1: int}
 */
function orders_browse_split(array $item): array
{
    $van = (int)$item['receive_to_van_qty'];
    $region = (int)$item['receive_to_region_qty'];
    return $van + $region === 0 ? [(int)$item['quantity'], 0] : [$van, $region];
}

/**
 * Every line's product is active.
 *
 * @param list<array<string, mixed>> $items
 * @throws UnprocessableException
 */
function orders_browse_require_active(array $items): void
{
    $unavailable = [];
    foreach ($items as $item) {
        if (($item['status'] ?? null) !== 'active') {
            $unavailable[] = $item['sku'] ?? ('ID ' . $item['product_id']);
        }
    }
    if ($unavailable !== []) {
        throw new UnprocessableException('Unable to reorder. Update quantities or remove unavailable products: ' . implode(', ', $unavailable));
    }
}

/**
 * Cancel an order that is not finished. The owner or an order manager may; anyone else sees "not found".
 *
 * @throws NotFoundException|ConflictException
 */
function orders_browse_cancel(PDO $pdo, OrdersViewer $viewer, int $orderId): void
{
    orders_browse_transaction($pdo, static function () use ($pdo, $viewer, $orderId): void {
        $stmt = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE id = ? AND (user_id = ? OR ? = 1) FOR UPDATE');
        $stmt->execute([$orderId, $viewer->userId, $viewer->canManage ? 1 : 0]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new NotFoundException('Order not found');
        }
        if (in_array($order['status'], ['cancelled', 'received'], true)) {
            throw new ConflictException('Cannot cancel orders with status: ' . $order['status']);
        }
        $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute(['cancelled', $orderId]);
    });
}
