<?php

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, mixed>> */
function order_details_input_schema(): array
{
    return [
        'order_id' => in_int(min: 1, max: 2147483647, required: true, column: 'purchase_orders.id'),
    ];
}
