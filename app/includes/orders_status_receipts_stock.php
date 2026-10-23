<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory_ownership.php';
require_once __DIR__ . '/api_errors.php';

/**
 * qm-ok: flag-param - the flags are the order destination data
 * Dedicated destinations receive the whole quantity; split orders must conserve
 * it. Legacy unsplit rows go to the van, as checkout's default allocation does.
 * @param array<string, mixed> $item
 * @return array{van: int, region: int}
 */
function orders_status_receipts_allocation(array $item, bool $van, bool $region): array
{
    $quantity = (int)$item['quantity'];
    if ($quantity <= 0) {
        throw new UnprocessableException('Order quantities must be positive.');
    }
    if (!$van || !$region) {
        return ['van' => $van ? $quantity : 0, 'region' => $region ? $quantity : 0];
    }
    return orders_status_receipts_split($item, $quantity);
}

/**
 * @param array<string, mixed> $item
 * @return array{van: int, region: int}
 */
function orders_status_receipts_split(array $item, int $quantity): array
{
    $a = (int)$item['receive_to_van_qty'];
    $b = (int)$item['receive_to_region_qty'];
    if ($a === 0 && $b === 0) {
        $a = $quantity;
    }
    if ($a < 0 || $b < 0 || $a + $b !== $quantity) {
        throw new UnprocessableException('Order destination quantities must add up to the ordered quantity.');
    }
    return ['van' => $a, 'region' => $b];
}

/**
 * qm-ok: too-many-params - explicit order, destination, actor and storage location fields
 * The caller holds the order lock and transaction throughout inventory and ledger writes.
 * @param array<string, mixed> $order
 */
function orders_status_receipts_stock(PDO $pdo, array $order, bool $van, bool $region, int $actorId, string $location): string
{
    $totals = orders_status_receipts_totals($pdo, (int)$order['id'], $van, $region);
    $done = 0;
    foreach ($totals as $destination => $products) {
        foreach ($products as $productId => $quantity) {
            if ($quantity > 0 && orders_status_receipts_stock_item($pdo, $order, $destination, $productId, $quantity, $actorId, $location)) {
                $done++;
            }
        }
    }
    return $done ? "Stock updated for {$done} destination items." : 'Supplies already received, or only tracked assets remain to scan.';
}

/**
 * qm-ok: flag-param - the flags are destination data for the allocation calculation
 * @return array<string, array<int, int>>
 */
function orders_status_receipts_totals(PDO $pdo, int $orderId, bool $van, bool $region): array
{
    $stmt = $pdo->prepare('SELECT poi.product_id, poi.quantity, poi.receive_to_van_qty, poi.receive_to_region_qty
        FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id
        WHERE poi.order_id = ? AND p.is_tracked_asset = 0 ORDER BY poi.product_id, poi.id');
    $stmt->execute([$orderId]);
    $totals = ['van' => [], 'region' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        foreach (orders_status_receipts_allocation($item, $van, $region) as $destination => $quantity) {
            $id = (int)$item['product_id'];
            $totals[$destination][$id] = ($totals[$destination][$id] ?? 0) + $quantity;
        }
    }
    return $totals;
}

/**
 * qm-ok: too-many-params - explicit destination receipt fields, all derived from the locked order
 * @param array<string, mixed> $order
 */
function orders_status_receipts_stock_item(PDO $pdo, array $order, string $destination, int $productId, int $quantity, int $actorId, string $location): bool
{
    $stmt = $pdo->prepare('SELECT id FROM purchase_order_stock_receipts WHERE order_id = ? AND product_id = ? AND destination = ?');
    $stmt->execute([$order['id'], $productId, $destination]);
    if ($stmt->fetchColumn()) {
        return false;
    }
    InventoryOwnershipPolicy::requireQuantityProduct($pdo, $productId);
    $storeId = null;
    $userId = null;
    $regionId = null;
    if ($destination === 'van') {
        $storeId = orders_status_receipts_store($pdo, (int)($order['store_id'] ?? 0));
        $userId = (int)$order['user_id'];
        orders_status_receipts_van($pdo, $order, $productId, $quantity, $storeId);
    } else {
        $regionId = (int)$order['owner_region_id'];
        if ($regionId <= 0) {
            throw new UnprocessableException('Order owner is not assigned to a region.');
        }
        orders_status_receipts_region($pdo, $productId, $regionId, (string)$order['owner_region_name'], $location, $quantity);
    }
    $stmt = $pdo->prepare('INSERT INTO purchase_order_stock_receipts
        (order_id, product_id, destination, quantity, target_user_id, target_region_id, store_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$order['id'], $productId, $destination, $quantity, $userId, $regionId, $storeId, $actorId]);
    return true;
}

/** Resolve an explicit store, or the legacy default when none was stored; never redirect a nonexistent explicit store. */
function orders_status_receipts_store(PDO $pdo, int $storeId): int
{
    $storeId = $storeId > 0 ? $storeId : 1;
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    if (!$stmt->fetchColumn()) {
        throw new UnprocessableException('Valid store destination not found for van stock receipt.');
    }
    return $storeId;
}

/** @param array<string, mixed> $order */
function orders_status_receipts_van(PDO $pdo, array $order, int $productId, int $quantity, int $storeId): void
{
    $stmt = $pdo->prepare('INSERT INTO van_stock (product_id, user_id, store_id, quantity) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()');
    $stmt->execute([$productId, $order['user_id'], $storeId, $quantity]);
    $stmt = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    $storeName = (string)$stmt->fetchColumn();
    $note = 'Order ' . $order['order_number'] . ' received into van stock.';
    $stmt = $pdo->prepare('INSERT INTO transactions (product_id, user_id, change_qty, reason, store_id, store, note, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$productId, $order['user_id'], $quantity, 'add', $storeId, $storeName, $note]);
    // Audit failure must roll back the stock and receipt, rather than silently omit its history.
    $stmt = $pdo->prepare('INSERT INTO van_stock_transactions
        (product_id, user_id, quantity_change, reason, source_store_id, target_store_id, note, metadata, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$productId, $order['user_id'], $quantity, 'add', $storeId, $storeId, $note, json_encode(['order_number' => $order['order_number']])]);
}

/** Fresh installs have region_id; migrated installations may still use the legacy region key.
 * qm-ok: too-many-params - explicit region inventory fields
 */
function orders_status_receipts_region(PDO $pdo, int $productId, int $regionId, string $region, string $location, int $quantity): void
{
    $stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'region_storage' AND column_name IN ('region_id', 'location')");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('location', $columns, true)) {
        if (in_array('region_id', $columns, true)) {
            $stmt = $pdo->prepare('INSERT INTO region_storage (product_id, region_id, region, quantity)
                VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), region = VALUES(region)');
            $stmt->execute([$productId, $regionId, $region, $quantity]);
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO region_storage (product_id, region, quantity)
            VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)');
        $stmt->execute([$productId, $region, $quantity]);
        return;
    }
    if (in_array('region_id', $columns, true)) {
        $stmt = $pdo->prepare('INSERT INTO region_storage (product_id, region_id, region, location, quantity)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), region = VALUES(region)');
        $stmt->execute([$productId, $regionId, $region, $location, $quantity]);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO region_storage (product_id, region, location, quantity)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), region = VALUES(region)');
    $stmt->execute([$productId, $region, $location, $quantity]);
}
