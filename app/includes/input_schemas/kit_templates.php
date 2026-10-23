<?php

/**
 * Request input of api/kit-templates.php (kit item changes and the three lookups), one schema per action (see
 * includes/input_schema.php). Column limits mirror db/migrations/062-create-kit-tables.sql; tests/schema_drift_test.php
 * checks them against a real database. The quantity bound is the one the kit form offers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** kit_templates.id, kit_template_items.id, products.id and Snipe-IT ids are INT columns. */
const KIT_TEMPLATES_MAX_ID = 2147483647;

/** The largest quantity of one kit item (the kit form's own limit). */
const KIT_TEMPLATES_MAX_QUANTITY = 999;

/** The longest search text. */
const KIT_TEMPLATES_SEARCH_MAX = 100;

/** The longest serial number or asset tag (Snipe-IT keeps both in 255-character columns). */
const KIT_TEMPLATES_SERIAL_MAX = 255;

/** The kinds of item a kit can hold; a tracked product is stored as a product. */
const KIT_TEMPLATES_ITEM_TYPES = ['product', 'product_tracked', 'snipe_model'];

/** The actions that read (GET or HEAD). */
const KIT_TEMPLATES_READS = ['get_kit_snipe_items', 'lookup_serial', 'search_items'];

/** The actions that change kit items (POST, CSRF, kit.manage). */
const KIT_TEMPLATES_WRITES = ['add_item', 'remove_item', 'update_item_quantity'];

/**
 * A required row id.
 *
 * @return array<string, mixed>
 */
function kit_templates_id(string $label, string $column): array
{
    return in_int(min: 1, max: KIT_TEMPLATES_MAX_ID, required: true, label: $label, column: $column);
}

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function kit_templates_input_schemas(): array
{
    $quantity = in_int(min: 1, max: KIT_TEMPLATES_MAX_QUANTITY, required: true, label: 'Quantity', column: 'kit_template_items.quantity');
    return [
        'get_kit_snipe_items' => ['kit_template_id' => kit_templates_id('Kit template', 'kit_templates.id')],
        'lookup_serial' => [
            'serial' => in_string(max: KIT_TEMPLATES_SERIAL_MAX, required: true, label: 'Serial number'),
            'snipe_model_id' => in_int(min: 0, max: KIT_TEMPLATES_MAX_ID, default: 0, label: 'Snipe-IT model'),
        ],
        'search_items' => ['q' => in_string(max: KIT_TEMPLATES_SEARCH_MAX, default: '', label: 'Search')],
        'add_item' => [
            'kit_template_id' => kit_templates_id('Kit template', 'kit_template_items.kit_template_id'),
            'item_type' => in_enum(KIT_TEMPLATES_ITEM_TYPES, required: true, label: 'Item type'),
            'product_id' => in_int(min: 1, max: KIT_TEMPLATES_MAX_ID, label: 'Product', column: 'kit_template_items.product_id'),
            'snipe_model_id' => in_int(min: 1, max: KIT_TEMPLATES_MAX_ID, label: 'Snipe-IT model', column: 'kit_template_items.snipe_model_id'),
            'snipe_model_name' => in_string(max: 255, label: 'Snipe-IT model name', column: 'kit_template_items.snipe_model_name'),
            'quantity' => ['required' => false, 'default' => 1] + $quantity,
            'notes' => in_string(max: 255, label: 'Notes', column: 'kit_template_items.notes'),
        ],
        'remove_item' => ['item_id' => kit_templates_id('Item', 'kit_template_items.id')],
        'update_item_quantity' => [
            'item_id' => kit_templates_id('Item', 'kit_template_items.id'),
            'quantity' => $quantity,
        ],
    ];
}

/**
 * Parse the request for $action: the query for a read, the form for a change. An item's own fields depend on its type:
 * a product needs product_id, a Snipe-IT model needs snipe_model_id and snipe_model_name; the other type's fields are
 * ignored (the kit form always sends them, blank).
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function kit_templates_input(string $action): array
{
    static $schemas = null;
    $schemas ??= kit_templates_input_schemas();
    $in = input_parse($schemas[$action], in_array($action, KIT_TEMPLATES_WRITES, true) ? $_POST : $_GET);
    if ($action !== 'add_item') {
        return $in;
    }
    $needed = $in['item_type'] === 'snipe_model' ? ['snipe_model_id' => 'Snipe-IT model', 'snipe_model_name' => 'Snipe-IT model name'] : ['product_id' => 'Product'];
    foreach ($needed as $field => $label) {
        if (!isset($in[$field])) {
            throw new BadRequestException($label . ' is required.');
        }
        if ($in[$field] === '') {
            throw new UnprocessableException($label . ' must not be blank.');
        }
    }
    return array_diff_key($in, $in['item_type'] === 'snipe_model' ? ['product_id' => 1] : ['snipe_model_id' => 1, 'snipe_model_name' => 1]);
}
