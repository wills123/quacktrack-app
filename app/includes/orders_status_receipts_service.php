<?php

declare(strict_types=1);

require_once __DIR__ . '/orders_browse_service.php';
require_once __DIR__ . '/order_receipts.php';
require_once __DIR__ . '/orders_status_receipts_stock.php';

/** @return array<string, mixed> */
function orders_status_receipts_order(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT po.*, u.region_id AS owner_region_id, r.name AS owner_region_name
        FROM purchase_orders po LEFT JOIN users u ON u.id = po.user_id
        LEFT JOIN regions r ON r.id = u.region_id WHERE po.id = ?');
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new NotFoundException('Order not found.');
}

/** @return array<string, mixed> */
function orders_status_receipts_locked_order(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT id FROM purchase_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    return orders_status_receipts_order($pdo, $orderId);
}

/**
 * Stored preferences are used only when both flags are omitted. Explicit flags
 * must keep the destination of any receipts already recorded.
 * @param array<string, mixed> $order
 * @param array<string, mixed> $input
 * @return array{0: bool, 1: bool}
 */
function orders_status_receipts_flags(array $order, array $input): array
{
    if (!array_key_exists('receive_to_van', $input) && !array_key_exists('receive_to_region', $input)) {
        return [!empty($order['receive_to_van']) || empty($order['receive_to_region']), !empty($order['receive_to_region'])];
    }
    $van = $input['receive_to_van'] ?? false;
    $region = $input['receive_to_region'] ?? false;
    if (!$van && !$region && $input['status'] === 'received') {
        throw new UnprocessableException('Select a receipt destination.');
    }
    return [$van, $region];
}

/**
 * qm-ok: flag-param - van and region are destination data, not switches selecting unrelated behavior
 * @param array<string, mixed> $order
 */
function orders_status_receipts_authorize(array $order, OrdersViewer $viewer, bool $van, bool $region): void
{
    $own = (int)$order['user_id'] === $viewer->userId;
    $regional = $region && $viewer->regionId > 0 && $viewer->regionId === (int)$order['owner_region_id'];
    if (!$viewer->canManage && !(($van && $own) || $regional)) {
        throw new ForbiddenException('You do not have permission to receive this order.');
    }
}

/** @param array<string, mixed> $order */
function orders_status_receipts_existing(PDO $pdo, array $order): bool
{
    $stmt = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM purchase_order_stock_receipts WHERE order_id = ?)
        OR EXISTS(SELECT 1 FROM purchase_order_asset_receipts WHERE order_id = ?)');
    $stmt->execute([$order['id'], $order['id']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Refuse reopening terminal orders or changing the destination behind a receipt.
 * qm-ok: flag-param - the flags describe the destination being validated
 * @param array<string, mixed> $order
 */
function orders_status_receipts_transition(PDO $pdo, array $order, string $status, bool $van, bool $region): void
{
    if ($order['status'] === 'cancelled' || ($order['status'] === 'received' && $status !== 'received')) {
        throw new ConflictException('A completed or cancelled order cannot change status.');
    }
    if (!orders_status_receipts_existing($pdo, $order)) {
        return;
    }
    [$storedVan, $storedRegion] = orders_status_receipts_flags($order, []);
    if ($van !== $storedVan || $region !== $storedRegion || in_array($status, ['draft', 'sent'], true)) {
        throw new ConflictException('An order with receipts cannot be reopened or redirected.');
    }
}

/**
 * Serialize all status and receipt decisions on the purchase order, including the
 * receipt ledger read. A second request sees the first request's committed state.
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function orders_status_receipts_update(PDO $pdo, OrdersViewer $viewer, array $input): array
{
    return orders_browse_transaction($pdo, static function () use ($pdo, $viewer, $input): array {
        $order = orders_status_receipts_locked_order($pdo, $input['order_id']);
        [$van, $region] = orders_status_receipts_flags($order, $input);
        $status = $input['status'];
        if (!$viewer->canManage) {
            orders_status_receipts_require_stored($order, $status, $van, $region);
        }
        orders_status_receipts_authorize($order, $viewer, $van, $region);
        orders_status_receipts_transition($pdo, $order, $status, $van, $region);
        $message = '';
        if ($status === 'received') {
            $message = orders_status_receipts_stock($pdo, $order, $van, $region, $viewer->userId, $input['receive_to_region_location']);
            $status = syncOrderReceiptStatus($pdo, (int)$order['id']);
        }
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = ?, receive_to_van = ?, receive_to_region = ?,
            sent_at = CASE WHEN ? = 'sent' THEN COALESCE(sent_at, NOW()) ELSE sent_at END WHERE id = ?");
        $stmt->execute([$status, (int)$van, (int)$region, $input['status'], $order['id']]);
        return ['status' => $status, 'message' => 'Order status updated', 'receipt_message' => $message];
    });
}

/**
 * qm-ok: flag-param - van and region are destination data, not switches selecting unrelated behavior
 * @param array<string, mixed> $order
 */
function orders_status_receipts_require_stored(array $order, string $status, bool $van, bool $region): void
{
    [$storedVan, $storedRegion] = orders_status_receipts_flags($order, []);
    if ($status !== 'received' || $van !== $storedVan || $region !== $storedRegion) {
        throw new ForbiddenException('You do not have permission to update this order.');
    }
}
