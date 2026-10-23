<?php

declare(strict_types=1);

/**
 * JSON bulk-add input; the per-request maximum is shared with form actions.
 *
 * @return array<string, array<string, mixed>>
 */
function cart_bulk_input_schema(int $maxQuantity = 100): array
{
    return [
        'items' => in_records([
            'sku' => in_string(max: 100, required: true, column: 'products.product_id'),
            'quantity' => in_int(min: 1, max: $maxQuantity, default: 1),
        ], max: 500, required: true, label: 'Items'),
    ];
}

/** @return array<string, array<string, array<string, mixed>>> */
function cart_input_schemas(int $maxQuantity = 100, int $maxNotes = 255): array
{
    $itemId = ['cart_item_id' => in_int(min: 1, max: 2147483647, required: true, column: 'cart_items.id')];
    $fields = [
        'quantity' => in_int(min: 1, max: $maxQuantity, default: 1, column: 'cart_items.quantity'),
        'notes' => in_string(max: min($maxNotes, 255), default: '', column: 'cart_items.notes'),
        'bill_to_store_id' => in_int(min: 0, max: 2147483647, default: 0, column: 'cart_items.bill_to_store_id'),
        'receive_to_van_qty' => in_int(min: 0, max: $maxQuantity, column: 'cart_items.receive_to_van_qty'),
        'receive_to_region_qty' => in_int(min: 0, max: $maxQuantity, column: 'cart_items.receive_to_region_qty'),
    ];
    $update = $fields;
    $update['quantity'] = in_int(min: 0, max: $maxQuantity, default: 1, column: 'cart_items.quantity');
    return [
        'add' => ['product_id' => in_int(min: 1, max: 2147483647, required: true, column: 'products.id')] + $fields,
        'update' => $itemId + $update,
        'remove' => $itemId,
        'get' => [],
        'count' => [],
        'clear' => [],
        'bulk_add' => cart_bulk_input_schema($maxQuantity),
    ];
}

/** @return array<string, mixed> */
function cart_input(string $action, int $maxQuantity = 100, int $maxNotes = 255): array
{
    $schemas = cart_input_schemas($maxQuantity, $maxNotes);
    $schema = $schemas[$action] ?? throw new BadRequestException('Invalid action');
    $source = match ($action) {
        'bulk_add' => req_json(true),
        'get', 'count' => $_GET,
        default => $_POST,
    };
    return input_parse($schema, $source);
}
