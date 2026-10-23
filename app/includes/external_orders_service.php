<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/** The way the idempotent response is stored and replayed. */
const EXTERNAL_ORDERS_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

/**
 * Create the order for a validated payload, or replay the answer stored for its idempotency key.
 *
 * The key's own row is locked first, so a concurrent retry waits for the first request and then finds
 * its stored answer; every database check runs after that, so a replay never depends on the current
 * state of the catalog. Nothing is written unless the whole request is accepted.
 *
 * @param array{id: int, created_by_user_id: int} $auth
 * @param array<string, mixed> $payload normalized by quickqueue_validate_order_payload()
 * @return array{0: int, 1: array<string, mixed>} the HTTP status (201 created, 200 replayed) and the body
 * @throws ConflictException|UnprocessableException
 */
function external_orders_create(PDO $pdo, array $auth, array $payload, string $legacyRequestHash): array
{
    $requestHash = hash('sha256', json_encode($payload, EXTERNAL_ORDERS_JSON_FLAGS | JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM api_keys WHERE id = ? FOR UPDATE');
        $lock->execute([$auth['id']]);
        $stored = external_orders_stored_response($pdo, $auth['id'], $payload['idempotency_key'], $requestHash, $legacyRequestHash);
        if ($stored !== null) {
            $pdo->commit();
            return [200, $stored];
        }
        $response = external_orders_insert($pdo, $auth, $payload, external_orders_resolve($pdo, $payload), $requestHash);
        $pdo->commit();
        return [201, $response];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * The answer stored for this key and body, null for a new key.
 *
 * @return array<string, mixed>|null
 * @throws ConflictException when the key was used with a different body
 */
function external_orders_stored_response(PDO $pdo, int $keyId, string $idempotencyKey, string $requestHash, string $legacyRequestHash): ?array
{
    $stmt = $pdo->prepare('SELECT request_hash, response_json
        FROM external_api_idempotency
        WHERE api_key_id = ? AND idempotency_key = ?
        LIMIT 1');
    $stmt->execute([$keyId, $idempotencyKey]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }
    if (!hash_equals((string)$record['request_hash'], $requestHash) && !hash_equals((string)$record['request_hash'], $legacyRequestHash)) {
        throw new ConflictException('The idempotency key was already used with a different request.');
    }
    $response = json_decode((string)$record['response_json'], true);
    if (!is_array($response)) {
        throw new RuntimeException('The stored idempotent response is invalid.');
    }
    return $response;
}

/**
 * Look up what the order refers to: the store, the region (when receiving to one) and each product.
 *
 * @param array<string, mixed> $payload
 * @return array{region_name: ?string, products: list<array<string, mixed>>}
 * @throws UnprocessableException
 */
function external_orders_resolve(PDO $pdo, array $payload): array
{
    $store = $pdo->prepare('SELECT id FROM stores WHERE id = ? LIMIT 1');
    $store->execute([$payload['store_id']]);
    if (!$store->fetchColumn()) {
        throw new UnprocessableException('The selected store does not exist.');
    }
    $regionName = null;
    if ($payload['receive_to_region']) {
        $region = $pdo->prepare('SELECT name FROM regions WHERE id = ? LIMIT 1');
        $region->execute([$payload['region_id']]);
        $regionName = $region->fetchColumn();
        if ($regionName === false) {
            throw new UnprocessableException('The selected region does not exist.');
        }
    }
    return ['region_name' => $regionName === null ? null : (string)$regionName, 'products' => external_orders_products($pdo, $payload['items'])];
}

/**
 * Each requested line with its active product.
 *
 * @param list<array{sku: string, quantity: int, notes: string}> $items
 * @return list<array{product_id: int, sku: string, name: string, quantity: int, notes: string}>
 * @throws UnprocessableException
 */
function external_orders_products(PDO $pdo, array $items): array
{
    $maxQuantity = get_max_order_quantity();
    $lookup = $pdo->prepare('SELECT id, product_id, name, status FROM products WHERE product_id = ? LIMIT 1');
    $products = [];
    $seenProducts = [];
    foreach ($items as $item) {
        if ($item['quantity'] > $maxQuantity) {
            throw new UnprocessableException('Item quantity exceeds the configured maximum.', ['sku' => $item['sku'], 'max' => $maxQuantity]);
        }
        $lookup->execute([$item['sku']]);
        $product = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new UnprocessableException('One or more SKUs could not be found.', ['sku' => $item['sku']]);
        }
        if (strtolower((string)$product['status']) !== 'active') {
            throw new UnprocessableException('The requested SKU is not active.', ['sku' => $item['sku']]);
        }
        // Two spellings can name one product (the SKU column compares without case or accents).
        if (isset($seenProducts[(int)$product['id']])) {
            throw new UnprocessableException('Duplicate SKUs are not allowed in one order.', ['sku' => $item['sku']]);
        }
        $seenProducts[(int)$product['id']] = true;
        $products[] = [
            'product_id' => (int)$product['id'],
            'sku' => (string)$product['product_id'],
            'name' => (string)$product['name'],
            'quantity' => $item['quantity'],
            'notes' => $item['notes'],
        ];
    }
    return $products;
}

/**
 * Write the order, its lines, the ticket link and the idempotency record.
 *
 * @param array{id: int, created_by_user_id: int} $auth
 * @param array<string, mixed> $payload
 * @param array{region_name: ?string, products: list<array<string, mixed>>} $resolved
 * @return array<string, mixed> the response body
 */
function external_orders_insert(PDO $pdo, array $auth, array $payload, array $resolved, string $requestHash): array
{
    $orderNumber = 'ORD-' . strtoupper(bin2hex(random_bytes(8)));
    $products = $resolved['products'];
    $pdo->prepare('INSERT INTO purchase_orders
        (user_id, order_number, store_id, notes, status, receive_to_van, receive_to_region,
         total_items, sent_at, external_requested_by_email, external_requested_by_name)
        VALUES (?, ?, ?, ?, "sent", ?, ?, ?, CURRENT_TIMESTAMP, ?, ?)')->execute([
        $auth['created_by_user_id'],
        $orderNumber,
        $payload['store_id'],
        'Created by QuickQueue integration.',
        $payload['receive_to_van'] ? 1 : 0,
        $payload['receive_to_region'] ? 1 : 0,
        array_sum(array_column($products, 'quantity')),
        $payload['requested_by']['email'],
        $payload['requested_by']['name'],
    ]);
    $orderId = (int)$pdo->lastInsertId();
    external_orders_insert_lines($pdo, $orderId, $products, $payload);
    if ($payload['ticket'] !== null) {
        $pdo->prepare('INSERT INTO external_ticket_links (purchase_order_id, ticket_system, ticket_external_id) VALUES (?, ?, ?)')
            ->execute([$orderId, EXTERNAL_ORDERS_TICKET_SYSTEM, $payload['ticket']['id']]);
    }
    $response = [
        'success' => true,
        'data' => [
            'order_number' => $orderNumber,
            'status' => 'sent',
            'store_id' => $payload['store_id'],
            'receive_to_van' => $payload['receive_to_van'],
            'receive_to_region' => $payload['receive_to_region'],
            'region_id' => $payload['region_id'],
            'region_name' => $resolved['region_name'],
            'items' => $products,
        ],
    ];
    $pdo->prepare('INSERT INTO external_api_idempotency (api_key_id, idempotency_key, request_hash, response_json, order_id)
        VALUES (?, ?, ?, ?, ?)')->execute([
        $auth['id'],
        $payload['idempotency_key'],
        $requestHash,
        json_encode($response, EXTERNAL_ORDERS_JSON_FLAGS | JSON_THROW_ON_ERROR),
        $orderId,
    ]);
    return $response;
}

/**
 * @param list<array<string, mixed>> $products
 * @param array<string, mixed> $payload
 */
function external_orders_insert_lines(PDO $pdo, int $orderId, array $products, array $payload): void
{
    $insert = $pdo->prepare('INSERT INTO purchase_order_items
        (order_id, product_id, quantity, notes, receive_to_van_qty, receive_to_region_qty)
        VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($products as $product) {
        $insert->execute([
            $orderId,
            $product['product_id'],
            $product['quantity'],
            $product['notes'] !== '' ? $product['notes'] : null,
            $payload['receive_to_van'] ? $product['quantity'] : 0,
            $payload['receive_to_region'] ? $product['quantity'] : 0,
        ]);
    }
}

/**
 * The order one key created, with its lines and ticket; null when this key has no such order.
 *
 * @return array<string, mixed>|null
 */
function external_orders_find(PDO $pdo, int $keyId, string $orderNumber): ?array
{
    $stmt = $pdo->prepare('SELECT po.id, po.order_number, po.status, po.store_id, po.total_items,
            po.created_at, po.updated_at, po.receive_to_van, po.receive_to_region,
            po.external_requested_by_email, po.external_requested_by_name,
            etl.ticket_system, etl.ticket_external_id
        FROM purchase_orders po
        JOIN external_api_idempotency ei ON ei.order_id = po.id AND ei.api_key_id = ?
        LEFT JOIN external_ticket_links etl ON etl.purchase_order_id = po.id
        WHERE po.order_number = ?
        LIMIT 1');
    $stmt->execute([$keyId, $orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }
    $items = $pdo->prepare('SELECT p.product_id AS sku, poi.quantity, poi.notes
        FROM purchase_order_items poi JOIN products p ON p.id = poi.product_id
        WHERE poi.order_id = ? ORDER BY poi.id');
    $items->execute([(int)$order['id']]);
    $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
    $order['external_ticket'] = $order['ticket_system'] === null ? null : [
        'system' => $order['ticket_system'],
        'id' => $order['ticket_external_id'],
    ];
    unset($order['id'], $order['ticket_system'], $order['ticket_external_id']);
    return $order;
}
