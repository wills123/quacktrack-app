<?php

declare(strict_types=1);

/**
 * Owners and global viewers may read any applicable order. Other viewers must share the owner's
 * region and either have region scope or be receiving an order into regional storage.
 * admin.access and system.full_access are already expanded by has_permission().
 *
 * @param array<string, mixed> $order
 * @param array{user_id: int, region_id: int, all: bool, region: bool} $viewer
 */
function order_details_can_view(array $order, array $viewer): bool
{
    if ((int)$order['user_id'] === $viewer['user_id'] || $viewer['all']) {
        return true;
    }
    return $viewer['region_id'] > 0
        && (int)$order['owner_region_id'] === $viewer['region_id']
        && ($viewer['region'] || !empty($order['receive_to_region']));
}

/**
 * The signed-in user as order_details_can_view() sees them.
 *
 * @return array{user_id: int, region_id: int, all: bool, region: bool}
 */
function order_details_viewer(): array
{
    return [
        'user_id' => (int)($_SESSION['uid'] ?? 0),
        'region_id' => (int)($_SESSION['region_id'] ?? 0),
        'all' => has_permission('orders.view_all') || has_permission('orders.manage'),
        'region' => has_permission('orders.view_region'),
    ];
}

/** Whether the signed-in user may copy order $orderId into their cart: only an order they may view. */
function orders_page_can_reorder(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare('SELECT po.user_id, po.receive_to_region, u.region_id AS owner_region_id
        FROM purchase_orders po LEFT JOIN users u ON u.id = po.user_id WHERE po.id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    return $order !== false && order_details_can_view($order, order_details_viewer());
}
