<?php

/**
 * Request input of api/external/orders.php: the JSON body that creates an order (action "create") and
 * the query that reads one back ("show"). The body also carries two small objects, requested_by and
 * external_ticket; their schemas are separate because the schema system describes flat fields and
 * lists of records, and external_orders_parse_create() checks their shape before applying them.
 * See includes/input_schema.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** purchase_orders.store_id, region ids and purchase_order_items.quantity are INT columns. */
const EXTERNAL_ORDERS_MAX_INT = 2147483647;

/** Most lines one order may carry; a longer list is refused rather than cut. */
const EXTERNAL_ORDERS_MAX_ITEMS = 100;

/** external_api_idempotency.idempotency_key is VARCHAR(255). */
const EXTERNAL_ORDERS_KEY_MAX = 255;

/** The only ticket system this integration accepts. */
const EXTERNAL_ORDERS_TICKET_SYSTEM = 'quickqueue';

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function external_orders_input_schemas(): array
{
    return [
        'create' => [
            'idempotency_key' => in_string(max: EXTERNAL_ORDERS_KEY_MAX, required: true, label: 'Idempotency key', column: 'external_api_idempotency.idempotency_key'),
            'store_id' => in_int(min: 1, max: EXTERNAL_ORDERS_MAX_INT, required: true, label: 'Store', column: 'purchase_orders.store_id'),
            'receive_to_van' => in_flag(label: 'Receive to van'),
            'receive_to_region' => in_flag(label: 'Receive to region'),
            'region_id' => in_int(min: 1, max: EXTERNAL_ORDERS_MAX_INT, label: 'Region'),
            'region_location' => in_string(max: 255, default: '', label: 'Region location'),
            'items' => in_records([
                'sku' => in_string(max: 100, required: true, label: 'SKU', column: 'products.product_id'),
                'quantity' => in_int(min: 1, max: EXTERNAL_ORDERS_MAX_INT, required: true, label: 'Quantity', column: 'purchase_order_items.quantity'),
                'notes' => in_string(max: 255, default: '', label: 'Notes', column: 'purchase_order_items.notes'),
            ], max: EXTERNAL_ORDERS_MAX_ITEMS, required: true, label: 'Items'),
        ],
        'show' => [
            'order_number' => in_string(max: 50, required: true, label: 'Order number', column: 'purchase_orders.order_number'),
        ],
    ];
}

/**
 * The two objects of a create body, each parsed as a flat record.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function external_orders_object_schemas(): array
{
    return [
        'requested_by' => [
            'email' => in_string(max: 190, required: true, label: 'Requester email', column: 'purchase_orders.external_requested_by_email'),
            'name' => in_string(max: 190, required: true, label: 'Requester name', column: 'purchase_orders.external_requested_by_name'),
        ],
        'external_ticket' => [
            'system' => in_string(max: 50, required: true, label: 'Ticket system', column: 'external_ticket_links.ticket_system'),
            'id' => in_string(max: 100, required: true, label: 'Ticket id', column: 'external_ticket_links.ticket_external_id'),
        ],
    ];
}

/**
 * Parse the query of a "show" request.
 *
 * @return array<string, mixed>
 */
function external_orders_show_input(): array
{
    return input_parse(external_orders_input_schemas()['show'], $_GET);
}
