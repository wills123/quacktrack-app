<?php

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, array<string, mixed>>> */
function orders_status_receipts_input_schemas(): array
{
    return [
        'update_status' => [
            'order_id' => in_int(min: 1, max: 2147483647, required: true, column: 'purchase_orders.id'),
            'status' => in_enum(['draft', 'sent', 'received', 'cancelled'], required: true),
            'receive_to_van' => in_flag(),
            'receive_to_region' => in_flag(),
            'receive_to_region_location' => in_string(max: 255, default: '', column: 'region_storage.location'),
        ],
        'receive_tracked_asset' => [
            'order_item_id' => in_int(min: 1, max: 2147483647, required: true, column: 'purchase_order_items.id'),
            'identifier' => in_string(max: 255, required: true, column: 'snipe_asset_review_queue.identifier'),
        ],
    ];
}

/** @return array<string, mixed> */
function orders_status_receipts_input(string $action): array
{
    $schema = orders_status_receipts_input_schemas()[$action] ?? throw new BadRequestException('Invalid action');
    $input = input_parse($schema, $_POST);
    foreach (['identifier', 'receive_to_region_location'] as $key) {
        if (isset($input[$key]) && preg_match('/[[:cntrl:]]/', $input[$key])) {
            throw new UnprocessableException('Control characters are not allowed.', ['fields' => [$key => 'Invalid text.']]);
        }
    }
    return $input;
}
