<?php

/**
 * Request input of api/acumatica.php, one schema per action (see includes/input_schema.php). Column limits of the
 * purchase-order record mirror db/migrations/079-create-acumatica-integration-tables.sql; the field lengths Acumatica
 * itself enforces (identifiers, description, vendor reference) are the limits of its own entities.
 * tests/schema_drift_test.php checks the bound columns.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** Most lines on one purchase order; larger requests are rejected, never truncated. */
const ACUMATICA_PO_MAX_LINES = 500;

/** Most InventoryIDs one stock lookup may ask for: each is a separate call to Acumatica. */
const ACUMATICA_MAX_STOCK_IDS = 50;

/** The longest key (inventory, shipment or order number) sent in a request path. */
const ACUMATICA_KEY_MAX = 50;

/** Actions that only read; create_po changes data. */
const ACUMATICA_READ_ACTIONS = ['stock_levels', 'stock_item', 'shipment_tracking', 'po_status'];

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function acumatica_input_schemas(): array
{
    return [
        'stock_levels' => [
            'inventory_ids' => in_list(in_string(max: ACUMATICA_KEY_MAX, label: 'Inventory ID'), max: ACUMATICA_MAX_STOCK_IDS, required: true, single: true, label: 'Inventory IDs'),
        ],
        'stock_item' => ['inventory_id' => in_string(max: ACUMATICA_KEY_MAX, required: true, label: 'Inventory ID')],
        'shipment_tracking' => ['shipment_nbr' => in_string(max: ACUMATICA_KEY_MAX, required: true, label: 'Shipment number')],
        'po_status' => ['order_nbr' => in_string(max: ACUMATICA_KEY_MAX, required: true, label: 'Order number')],
        'create_po' => [
            'vendor_id' => in_string(max: 15, required: true, label: 'Vendor', column: 'acumatica_purchase_orders.acumatica_vendor_id'),
            'source_type' => in_string(max: 50, required: true, label: 'Source type', column: 'acumatica_purchase_orders.source_type'),
            'source_id' => in_int(min: 1, max: 2147483647, label: 'Source', column: 'acumatica_purchase_orders.source_id'),
            'description' => in_string(max: 256, label: 'Description'),
            'vendor_ref' => in_string(max: 40, label: 'Vendor reference'),
            'lines' => in_records([
                'inventory_id' => in_string(max: ACUMATICA_KEY_MAX, required: true, label: 'Inventory ID'),
                'qty' => in_float(min: 0.000001, max: 999999999.0, required: true, label: 'Quantity'),
                'warehouse_id' => in_string(max: 30, label: 'Warehouse'),
                'unit_cost' => in_float(min: 0.0, max: 999999999.99, label: 'Unit cost'),
            ], max: ACUMATICA_PO_MAX_LINES, required: true, label: 'Lines'),
        ],
    ];
}

/**
 * Parse the request for $action: reads take the query, create_po the JSON body.
 *
 * @param array<string, mixed> $body the decoded JSON body (create_po only)
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function acumatica_input(string $action, array $body = []): array
{
    static $schemas = null;
    $schemas ??= acumatica_input_schemas();
    return input_parse($schemas[$action], in_array($action, ACUMATICA_READ_ACTIONS, true) ? $_GET : $body);
}
