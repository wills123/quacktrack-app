<?php

declare(strict_types=1);

require_once __DIR__ . '/orders_status_receipts_service.php';
require_once __DIR__ . '/snipe_catalog_service.php';
require_once __DIR__ . '/SnipeAssetAssignment.php';

/** @return array<string, mixed> */
function orders_status_receipts_item(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare('SELECT poi.*, p.is_tracked_asset FROM purchase_order_items poi
        JOIN products p ON p.id = poi.product_id WHERE poi.id = ?');
    $stmt->execute([$itemId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new NotFoundException('Tracked order item not found.');
}

/**
 * @param array<string, mixed> $item
 * @param array<string, mixed> $order
 */
function orders_status_receipts_check_item(PDO $pdo, array $item, array $order, OrdersViewer $viewer): int
{
    [$van, $region] = orders_status_receipts_flags($order, []);
    orders_status_receipts_authorize($order, $viewer, $van, $region);
    if ((int)$item['is_tracked_asset'] !== 1) {
        throw new UnprocessableException('This order item is not configured as a tracked asset.');
    }
    if ($order['status'] === 'cancelled') {
        throw new ConflictException('Cancelled orders cannot receive assets.');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_order_asset_receipts WHERE order_item_id = ?');
    $stmt->execute([$item['id']]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= (int)$item['quantity']) {
        throw new ConflictException('All units for this order item have already been received.');
    }
    return $count;
}

/**
 * A failed provider lookup is distinct from a usable empty search result. Only
 * the latter may enqueue a service desk review.
 * @return array<string, mixed>|null
 */
function orders_status_receipts_find_asset(SnipeItClient $client, string $identifier): ?array
{
    $answer = $client->listAssets(['search' => $identifier, 'limit' => 100, 'offset' => 0]);
    if ($answer === null) {
        throw new SnipeProviderException('Asset lookup failed.');
    }
    foreach ($answer['rows'] as $asset) {
        orders_status_receipts_validate_asset($asset);
        $asset['serial'] = $asset['serial'] ?? '';
        if (strcasecmp(trim($asset['serial']), $identifier) === 0 || strcasecmp(trim($asset['asset_tag']), $identifier) === 0) {
            return $asset;
        }
    }
    if ((int)$answer['total'] > 100) {
        throw new SnipeProviderException('Asset search was incomplete.');
    }
    return null;
}

/** @param array<string, mixed> $asset */
function orders_status_receipts_validate_asset(array $asset): void
{
    orders_status_receipts_provider_id($asset['id'] ?? null);
    foreach (['serial', 'asset_tag'] as $key) {
        if ($key === 'serial' && ($asset[$key] ?? null) === null) {
            continue;
        }
        if (!is_string($asset[$key] ?? null) || mb_strlen($asset[$key]) > 255 || preg_match('/[[:cntrl:]]/', $asset[$key])) {
            throw new SnipeProviderException('Asset identifier is malformed.');
        }
    }
}

/** A provider identifier must fit the unsigned Snipe identity columns. */
function orders_status_receipts_provider_id(mixed $id): int
{
    if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
        throw new SnipeProviderException('Asset identity is malformed.');
    }
    return (int)$id;
}

/**
 * Return a successful receipt, or a committed review id for a genuine not-found
 * result. Provider assignment is deliberately attempted after the local commit.
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function orders_status_receipts_receive(PDO $pdo, OrdersViewer $viewer, array $input): array
{
    $item = orders_status_receipts_item($pdo, $input['order_item_id']);
    $order = orders_status_receipts_order($pdo, (int)$item['order_id']);
    orders_status_receipts_check_item($pdo, $item, $order, $viewer);
    $asset = orders_status_receipts_find_asset(snipe_catalog_client($viewer->userId), $input['identifier']);
    $result = orders_browse_transaction($pdo, static function () use ($pdo, $viewer, $input, $asset, $item): array {
        $order = orders_status_receipts_locked_order($pdo, (int)$item['order_id']);
        $current = orders_status_receipts_item($pdo, (int)$item['id']);
        $count = orders_status_receipts_check_item($pdo, $current, $order, $viewer);
        if ($asset === null) {
            return ['review_id' => orders_status_receipts_review($pdo, $current, $input['identifier'], $viewer->userId)];
        }
        $destination = orders_status_receipts_destination($pdo, $order);
        orders_status_receipts_insert_asset($pdo, $current, $asset, $destination, $viewer->userId);
        $pdo->prepare("UPDATE snipe_asset_review_queue SET status = 'linked', snipe_asset_id = ?, reviewed_by = ?, resolved_at = NOW()
            WHERE order_item_id = ? AND identifier = ? AND status = 'pending'")
            ->execute([$asset['id'], $viewer->userId, $current['id'], $input['identifier']]);
        return ['destination' => $destination, 'received_count' => $count + 1,
            'required_count' => (int)$current['quantity'], 'order_status' => syncOrderReceiptStatus($pdo, (int)$order['id'])];
    });
    if ($asset === null || !isset($result['destination'])) {
        return $result;
    }
    $sync = isset($asset['assigned_to'])
        ? ['synced' => true, 'code' => 'already_assigned', 'message' => 'Snipe-IT asset is already assigned to this destination.']
        : orders_status_receipts_sync($pdo, (int)$asset['id'], $result['destination'], $viewer->userId);
    unset($result['destination']);
    return $result + ['asset' => orders_status_receipts_public_asset($asset), 'snipe_sync' => $sync, 'message' => 'Snipe-IT asset matched to this order item.'];
}

/** @param array<string, mixed> $item */
function orders_status_receipts_review(PDO $pdo, array $item, string $identifier, int $actorId): int
{
    $stmt = $pdo->prepare("SELECT id FROM snipe_asset_review_queue WHERE order_item_id = ? AND identifier = ? AND status = 'pending'");
    $stmt->execute([$item['id'], $identifier]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    $stmt = $pdo->prepare('INSERT INTO snipe_asset_review_queue (order_id, order_item_id, product_id, identifier, submitted_by)
        VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$item['order_id'], $item['id'], $item['product_id'], $identifier, $actorId]);
    return (int)$pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $order
 * @return array<string, mixed>
 */
function orders_status_receipts_destination(PDO $pdo, array $order): array
{
    $regional = !empty($order['receive_to_region']) && empty($order['receive_to_van']);
    $regionId = (int)$order['owner_region_id'];
    if ($regional && $regionId <= 0) {
        throw new UnprocessableException('Order owner is not assigned to a region.');
    }
    return ['destination' => $regional ? 'region' : 'van', 'target_user_id' => $regional ? null : (int)$order['user_id'],
        'target_region_id' => $regional ? $regionId : null,
        'store_id' => $regional ? null : orders_status_receipts_store($pdo, (int)($order['store_id'] ?? 0)),
        'note' => 'Purchase order #' . $order['id'] . ' received through Quack Track'];
}

/**
 * @param array<string, mixed> $item
 * @param array<string, mixed> $asset
 * @param array<string, mixed> $destination
 */
function orders_status_receipts_insert_asset(PDO $pdo, array $item, array $asset, array $destination, int $actorId): void
{
    orders_status_receipts_check_assignment($pdo, $asset['assigned_to'] ?? null, $destination);
    try {
        $stmt = $pdo->prepare('INSERT INTO purchase_order_asset_receipts
            (order_id, order_item_id, product_id, snipe_asset_id, serial, asset_tag, destination, target_user_id, target_region_id, store_id, received_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$item['order_id'], $item['id'], $item['product_id'], $asset['id'], $asset['serial'], $asset['asset_tag'],
            $destination['destination'], $destination['target_user_id'], $destination['target_region_id'], $destination['store_id'], $actorId]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new ConflictException('This Snipe-IT asset has already been matched to a purchase order.', [], $e);
        }
        throw $e;
    }
}

/**
 * Receiving an asset already at the intended destination is valid. Never move
 * someone else's assigned asset just because its serial was scanned.
 * @param array<string, mixed> $destination
 */
function orders_status_receipts_check_assignment(PDO $pdo, mixed $assigned, array $destination): void
{
    if ($assigned === null) {
        return;
    }
    if (!is_array($assigned)) {
        throw new SnipeProviderException('Asset assignment is malformed.');
    }
    $assignedId = orders_status_receipts_provider_id($assigned['id'] ?? null);
    $van = $destination['destination'] === 'van';
    $type = $van ? 'user' : 'location';
    if ($van) {
        $expectedId = resolve_snipe_user_id($pdo, (int)$destination['target_user_id']);
    } else {
        $stmt = $pdo->prepare('SELECT snipe_location_id FROM snipe_it_location_mappings WHERE region_id = ? LIMIT 1');
        $stmt->execute([$destination['target_region_id']]);
        $expectedId = (int)$stmt->fetchColumn();
    }
    if (($assigned['type'] ?? null) !== $type || $assignedId !== $expectedId) {
        throw new ConflictException('This Snipe-IT asset is assigned to another destination. Check it in before receiving it.');
    }
}

/**
 * @param array<string, mixed> $destination
 * @return array<string, mixed>
 */
function orders_status_receipts_sync(PDO $pdo, int $assetId, array $destination, int $actorId): array
{
    try {
        return sync_received_asset_destination($pdo, $assetId, $destination, $actorId);
    } catch (Throwable $e) {
        Logger::error('order_receipt_provider_sync_failed', $e, ['asset_id' => $assetId, 'user_id' => $actorId]);
        return ['synced' => false, 'code' => 'snipe_sync_failed', 'message' => 'Snipe-IT assignment failed; local receipt was saved.'];
    }
}

/**
 * @param array<string, mixed> $asset
 * @return array<string, int|string>
 */
function orders_status_receipts_public_asset(array $asset): array
{
    $status = is_array($asset['status_label'] ?? null) ? ($asset['status_label']['name'] ?? '') : '';
    return ['id' => (int)$asset['id'], 'name' => is_string($asset['name'] ?? null) ? $asset['name'] : '',
        'serial' => $asset['serial'], 'asset_tag' => $asset['asset_tag'], 'status' => is_string($status) ? $status : ''];
}
