<?php

/**
 * Request input of the write actions of api/snipe-it-assets.php (checkout, checkin, update), one schema per action
 * (see includes/input_schema.php). Nothing here is stored locally, so there are no column bindings; text bounds are
 * Snipe-IT's own column sizes (255 for names, tags and serials).
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** The actions this file describes. */
const SNIPE_WRITES_ACTIONS = ['checkout', 'checkin', 'update'];

/** Snipe-IT ids are INT columns. */
const SNIPE_WRITES_MAX_ID = 2147483647;

/** The largest purchase cost Snipe-IT stores (DECIMAL(20,2)), kept to what a float holds exactly in cents. */
const SNIPE_WRITES_MAX_COST = 99999999999.99;

/** The asset fields an update may change; notes is the only one that may be cleared with an empty value. */
const SNIPE_WRITES_UPDATE_FIELDS = [
    'name', 'asset_tag', 'serial', 'order_number', 'notes', 'status_id', 'model_id', 'manufacturer_id', 'supplier_id', 'location_id',
    'purchase_date', 'expected_checkin', 'purchase_cost', 'warranty_months', 'requestable',
];

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function snipe_writes_input_schemas(): array
{
    $asset = ['asset_id' => in_int(min: 1, max: SNIPE_WRITES_MAX_ID, required: true, label: 'Asset')];
    $ref = static fn(string $label): array => in_int(min: 1, max: SNIPE_WRITES_MAX_ID, label: $label);
    $text = static fn(string $label): array => in_string(max: 255, label: $label);
    return [
        'checkout' => $asset + [
            'assigned_to' => in_int(min: 1, max: SNIPE_WRITES_MAX_ID, required: true, label: 'User'),
            'note' => in_text(label: 'Note'),
        ],
        'checkin' => $asset + ['note' => in_text(label: 'Note')],
        'update' => $asset + [
            'name' => $text('Name'),
            'asset_tag' => $text('Asset tag'),
            'serial' => $text('Serial'),
            'order_number' => $text('Order number'),
            'notes' => in_text(label: 'Notes'),
            'status_id' => $ref('Status'),
            'model_id' => $ref('Model'),
            'manufacturer_id' => $ref('Manufacturer'),
            'supplier_id' => $ref('Supplier'),
            'location_id' => $ref('Location'),
            'purchase_date' => in_date(label: 'Purchase date'),
            'expected_checkin' => in_date(label: 'Expected check-in'),
            'purchase_cost' => in_float(min: 0, max: SNIPE_WRITES_MAX_COST, label: 'Purchase cost'),
            'warranty_months' => in_int(min: 0, max: 1200, label: 'Warranty months'),
            'requestable' => in_flag(label: 'Requestable'),
        ],
    ];
}

/**
 * Parse the form for $action. An update needs at least one field; only notes may be emptied, and a cost has at most
 * two decimals (it is money, nothing is rounded away).
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function snipe_writes_input(string $action): array
{
    static $schemas = null;
    $schemas ??= snipe_writes_input_schemas();
    $in = input_parse($schemas[$action], $_POST);
    if ($action !== 'update') {
        return $in;
    }
    $fields = array_diff_key($in, ['asset_id' => true]);
    if ($fields === []) {
        throw new UnprocessableException('No fields to update.');
    }
    foreach (['name', 'asset_tag', 'serial', 'order_number'] as $field) {
        if (($fields[$field] ?? null) === '') {
            throw new UnprocessableException(ucfirst(str_replace('_', ' ', $field)) . ' must not be empty.', ['fields' => [$field => 'Must not be empty.']]);
        }
    }
    if (isset($fields['purchase_cost']) && round($fields['purchase_cost'], 2) !== (float)$fields['purchase_cost']) {
        throw new UnprocessableException('Purchase cost must have at most two decimals.', ['fields' => ['purchase_cost' => 'At most two decimals.']]);
    }
    return $in;
}
