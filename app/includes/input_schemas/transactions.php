<?php

/**
 * Request input of api/transactions.php (see includes/input_schema.php): `list` reads the query, `restock` a JSON body.
 * Values that do not fit are refused instead of being replaced by a default, as the old handler did.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** The actions this file describes. */
const TRANSACTIONS_ACTIONS = ['list', 'restock'];

/** van_stock_transactions.reason values, plus 'all' (no filter). */
const TRANSACTIONS_REASONS = ['add', 'use', 'adjust', 'transfer', 'return', 'damage', 'loss', 'all'];

/** Page sizes the history offers. */
const TRANSACTIONS_PAGE_SIZES = [25, 50, 100];

/** stores.id and products.id are INT columns. */
const TRANSACTIONS_MAX_ID = 2147483647;

/** Most lines one restock may carry. */
const TRANSACTIONS_MAX_RESTOCK_ITEMS = 100;

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function transactions_input_schemas(): array
{
    $id = static fn(string $label): array => in_int(min: 1, max: TRANSACTIONS_MAX_ID, required: true, label: $label);
    return [
        'list' => [
            'tab' => in_enum(['store_usage', 'all'], default: 'store_usage', label: 'Tab'),
            'start_date' => in_date(label: 'Start date'),
            'end_date' => in_date(label: 'End date'),
            // 0 (or nothing) means every store.
            'store_id' => in_int(min: 0, max: TRANSACTIONS_MAX_ID, default: 0, label: 'Store'),
            'reason' => in_enum(TRANSACTIONS_REASONS, label: 'Reason'),
            'scope' => in_enum(['own', 'all'], default: 'own', label: 'Scope'),
            'page' => in_int(min: 1, max: 1000000, default: 1, label: 'Page'),
            'limit' => in_int(min: 1, max: 100, default: 50, label: 'Limit'),
        ],
        'restock' => [
            'items' => in_records([
                'product_id' => $id('Product'),
                'quantity' => in_int(min: 1, max: TRANSACTIONS_MAX_ID, required: true, label: 'Quantity'),
                'store_id' => $id('Store'),
            ], max: TRANSACTIONS_MAX_RESTOCK_ITEMS, required: true, label: 'Items'),
        ],
    ];
}

/**
 * Parse the request for $action.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function transactions_input(string $action): array
{
    static $schemas = null;
    $schemas ??= transactions_input_schemas();
    if ($action === 'restock') {
        $values = input_parse($schemas['restock'], req_json(true));
        if ($values['items'] === []) {
            throw new UnprocessableException('No items provided.', ['fields' => ['items' => 'Items is required.']]);
        }
        return $values;
    }
    $values = input_parse($schemas['list'], $_GET);
    if (!in_array($values['limit'], TRANSACTIONS_PAGE_SIZES, true)) {
        throw new UnprocessableException('Limit must be 25, 50 or 100.', ['fields' => ['limit' => 'Limit must be 25, 50 or 100.']]);
    }
    return $values + transactions_date_range($values['start_date'] ?? null, $values['end_date'] ?? null);
}

/**
 * The date range of a history request: the last 30 days up to the end date (today when not sent). A start after the
 * end is refused, not swapped.
 *
 * @return array{start_date: string, end_date: string}
 * @throws UnprocessableException
 */
function transactions_date_range(?string $start, ?string $end): array
{
    $end ??= date('Y-m-d');
    $start ??= date('Y-m-d', (int)strtotime($end . ' -30 days'));
    if ($start > $end) {
        throw new UnprocessableException('Start date must not be after the end date.', ['fields' => ['start_date' => 'Start date must not be after the end date.']]);
    }
    return ['start_date' => $start, 'end_date' => $end];
}
