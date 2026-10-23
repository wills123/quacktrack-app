<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/** Database INT quantities are trusted types, but legacy template/cart rows still need rule checks. */
function order_template_require_quantity(int $quantity, int $maximum): void
{
    if ($quantity < 1 || $quantity > $maximum) {
        throw new UnprocessableException("Template and resulting cart quantities must be between 1 and {$maximum}.");
    }
}

/**
 * The existing template key holds one row per product, so never discard distinct billing lines.
 *
 * @param list<array<string, mixed>> $items
 */
function order_template_validate_snapshot(array $items, int $maximum): void
{
    if ($items === []) {
        throw new UnprocessableException('Cart is empty - add items before creating template');
    }
    $products = [];
    foreach ($items as $item) {
        order_template_require_quantity((int)$item['quantity'], $maximum);
        $productId = (int)$item['product_id'];
        if (isset($products[$productId])) {
            throw new UnprocessableException('A template cannot save multiple billing lines for the same product.');
        }
        $products[$productId] = true;
    }
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function order_template_create(PDO $pdo, int $userId, array $input): array
{
    $cart = $pdo->prepare('SELECT product_id, quantity, bill_to_store_id FROM cart_items WHERE user_id = ? ORDER BY id FOR UPDATE');
    $cart->execute([$userId]);
    $items = $cart->fetchAll(PDO::FETCH_ASSOC);
    order_template_validate_snapshot($items, get_max_order_quantity());
    $pdo->prepare('INSERT INTO order_templates (name, description, user_id, is_public) VALUES (?, ?, ?, ?)')
        ->execute([$input['name'], $input['description'], $userId, (int)$input['is_public']]);
    $templateId = (int)$pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO order_template_items (template_id, product_id, quantity, bill_to_store_id) VALUES (?, ?, ?, ?)');
    foreach ($items as $item) {
        $insert->execute([$templateId, $item['product_id'], $item['quantity'], $item['bill_to_store_id']]);
    }
    return ['success' => true, 'template_id' => $templateId, 'message' => 'Template created successfully'];
}

/** @return array<string, mixed> */
function order_template_list(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT ot.*, COUNT(oti.id) AS item_count
        FROM order_templates ot
        LEFT JOIN order_template_items oti ON ot.id = oti.template_id
        WHERE ot.user_id = ? OR ot.is_public = 1
        GROUP BY ot.id
        ORDER BY ot.created_at DESC, ot.id DESC
    ');
    $stmt->execute([$userId]);
    return ['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/** @return list<array<string, mixed>> */
function order_template_read_items(PDO $pdo, int $userId, int $templateId): array
{
    $check = $pdo->prepare('SELECT id FROM order_templates WHERE id = ? AND (user_id = ? OR is_public = 1) FOR UPDATE');
    $check->execute([$templateId, $userId]);
    if (!$check->fetch()) {
        throw new NotFoundException('Template not found or access denied');
    }
    $stmt = $pdo->prepare('
        SELECT oti.product_id, oti.quantity, oti.bill_to_store_id, p.product_id AS sku, p.status
        FROM order_template_items oti
        LEFT JOIN products p ON p.id = oti.product_id
        WHERE oti.template_id = ? ORDER BY oti.id FOR UPDATE
    ');
    $stmt->execute([$templateId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($items === []) {
        throw new UnprocessableException('Template has no items');
    }
    return $items;
}

/**
 * @param list<array<string, mixed>> $items
 * @return array{items: list<array<string, mixed>>, skipped: list<string>}
 */
function order_template_orderable_items(array $items, int $maximum): array
{
    $active = [];
    $skipped = [];
    foreach ($items as $item) {
        if (($item['status'] ?? null) !== 'active') {
            $skipped[] = (string)($item['sku'] ?? ('ID ' . $item['product_id']));
            continue;
        }
        order_template_require_quantity((int)$item['quantity'], $maximum);
        $active[] = $item;
    }
    if ($active === []) {
        throw new UnprocessableException('None of the template items are currently orderable. Please update the template.');
    }
    return ['items' => $active, 'skipped' => $skipped];
}

/**
 * Index by actual billing store as well as the stored key: legacy callers may have left key zero.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{billing: array<string, list<array<string, mixed>>>, keys: array<string, array<string, mixed>>}
 */
function order_template_cart_index(array $rows): array
{
    $index = ['billing' => [], 'keys' => []];
    foreach ($rows as $row) {
        $prefix = (int)$row['product_id'] . '_';
        $index['billing'][$prefix . (int)$row['bill_to_store_id']][] = $row;
        $index['keys'][$prefix . (int)$row['bill_to_store_key']] = $row;
    }
    return $index;
}

/** @param array<string, mixed>|null $row @return array{0: int, 1: int} */
function order_template_existing_split(?array $row): array
{
    if ($row === null) {
        return [0, 0];
    }
    $quantity = (int)$row['quantity'];
    $van = (int)$row['receive_to_van_qty'];
    $region = (int)$row['receive_to_region_qty'];
    if ($van < 0 || $region < 0 || $van + $region !== $quantity) {
        return [$quantity, 0]; // same fallback as checkout for legacy unallocated cart rows
    }
    return [$van, $region];
}

/**
 * @param array<string, mixed> $item
 * @param array{billing: array<string, list<array<string, mixed>>>, keys: array<string, array<string, mixed>>} $index
 * @return array<string, mixed>
 */
function order_template_cart_change(array $item, array $index, int $maximum): array
{
    $key = (int)$item['product_id'] . '_' . (int)$item['bill_to_store_id'];
    $matches = $index['billing'][$key] ?? [];
    if (count($matches) > 1) {
        throw new UnprocessableException('Cart has duplicate billing lines. Resolve them before applying a template.');
    }
    $old = $matches[0] ?? null;
    if ($old === null && isset($index['keys'][$key])) {
        throw new UnprocessableException('Cart billing keys conflict. Resolve the existing billing line before applying a template.');
    }
    $oldQuantity = $old === null ? 0 : (int)$old['quantity'];
    if ($old !== null) {
        order_template_require_quantity($oldQuantity, $maximum);
    }
    $quantity = $oldQuantity + (int)$item['quantity'];
    order_template_require_quantity($quantity, $maximum);
    [$van, $region] = order_template_existing_split($old);
    return [
        'id' => $old['id'] ?? null,
        'product_id' => (int)$item['product_id'],
        'quantity' => $quantity,
        'bill_to_store_id' => $item['bill_to_store_id'],
        'bill_to_store_key' => (int)$item['bill_to_store_id'],
        'van' => $van + (int)$item['quantity'],
        'region' => $region,
    ];
}

/** @param list<array<string, mixed>> $changes */
function order_template_apply_changes(PDO $pdo, int $userId, array $changes): void
{
    $update = $pdo->prepare('UPDATE cart_items SET quantity = ?, receive_to_van_qty = ?, receive_to_region_qty = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
    $insert = $pdo->prepare('INSERT INTO cart_items (user_id, product_id, quantity, bill_to_store_id, bill_to_store_key, receive_to_van_qty, receive_to_region_qty) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($changes as $change) {
        if ($change['id'] !== null) {
            $update->execute([$change['quantity'], $change['van'], $change['region'], $change['id'], $userId]);
            continue;
        }
        $insert->execute([$userId, $change['product_id'], $change['quantity'], $change['bill_to_store_id'], $change['bill_to_store_key'], $change['van'], $change['region']]);
    }
}

/** @return array<string, mixed> */
function order_template_apply(PDO $pdo, int $userId, int $templateId): array
{
    $maximum = get_max_order_quantity();
    $selected = order_template_orderable_items(order_template_read_items($pdo, $userId, $templateId), $maximum);
    $cart = $pdo->prepare('SELECT * FROM cart_items WHERE user_id = ? ORDER BY id FOR UPDATE');
    $cart->execute([$userId]);
    $index = order_template_cart_index($cart->fetchAll(PDO::FETCH_ASSOC));
    $changes = [];
    foreach ($selected['items'] as $item) {
        $changes[] = order_template_cart_change($item, $index, $maximum);
    }
    order_template_apply_changes($pdo, $userId, $changes);
    $message = 'Template items added to cart';
    if ($selected['skipped'] !== []) {
        $message .= '. Skipped: ' . implode(', ', $selected['skipped']);
    }
    return ['success' => true, 'message' => $message, 'skipped' => $selected['skipped']];
}

/** @return array<string, mixed> */
function order_template_delete(PDO $pdo, int $userId, int $templateId): array
{
    $stmt = $pdo->prepare('DELETE FROM order_templates WHERE id = ? AND user_id = ?');
    $stmt->execute([$templateId, $userId]);
    if ($stmt->rowCount() === 0) {
        throw new NotFoundException('Template not found or access denied');
    }
    return ['success' => true, 'message' => 'Template deleted'];
}
