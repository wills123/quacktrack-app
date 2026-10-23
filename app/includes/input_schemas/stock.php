<?php

/**
 * Request input of api/stock.php, one schema per action (see includes/input_schema.php). The stock
 * quantity and note limits are admin settings, so they are checked after parsing by stock_check_limits().
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** products.id, stores.id and users.id are INT columns; van_stock.quantity and transactions.change_qty too. */
const STOCK_MAX_ID = 2147483647;

/**
 * A required whole number from 1 to the INT column limit.
 *
 * @return array<string, mixed>
 */
function stock_id(string $label): array
{
    return in_int(min: 1, max: STOCK_MAX_ID, required: true, label: $label);
}

/**
 * Every action's schema, keyed by action name. The note is optional free text; its limit is a setting.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function stock_input_schemas(): array
{
    $note = in_text(label: 'Note');
    return [
        'add_item' => [
            'product_id' => stock_id('Product'),
            'quantity' => stock_id('Quantity'),
            'store_id' => stock_id('Store'),
            'note' => $note,
        ],
        'adjust' => [
            'product_id' => stock_id('Product'),
            'adjustment_type' => in_enum(['add', 'use'], required: true, label: 'Adjustment type'),
            'quantity' => stock_id('Quantity'),
            'store_id' => stock_id('Location'),
            'note' => $note,
        ],
        'transfer' => [
            'product_id' => stock_id('Product'),
            'from_store_id' => stock_id('Source store'),
            'to_store_id' => stock_id('Destination store'),
            'quantity' => stock_id('Quantity'),
            'note' => $note,
        ],
        'transfer_van' => [
            'product_id' => stock_id('Product'),
            'from_store_id' => stock_id('Source location'),
            'target_user_id' => stock_id('Target van'),
            'to_store_id' => stock_id('Destination location'),
            'quantity' => stock_id('Quantity'),
            'note' => $note,
        ],
    ];
}

/**
 * Parse the request body for $action; stock changes read the body only and never the query string.
 * The result always has a 'note' (blank when none was sent).
 *
 * @return array<string, mixed>
 */
function stock_input(string $action): array
{
    static $schemas = null;
    $schemas ??= stock_input_schemas();
    $values = input_parse($schemas[$action], $_POST);
    $values['note'] ??= '';
    return $values;
}

/**
 * The limits an administrator sets: the largest quantity of one operation and the longest note.
 *
 * @param array<string, mixed> $values parsed input
 * @throws UnprocessableException
 */
function stock_check_limits(array $values, int $maxQuantity, int $maxNoteLength): void
{
    $problems = [];
    if ($values['quantity'] > $maxQuantity) {
        $problems['quantity'] = "Quantity cannot exceed {$maxQuantity}. For larger operations, contact your administrator.";
    }
    $length = mb_strlen((string)$values['note']);
    if ($length > $maxNoteLength) {
        $problems['note'] = "Note cannot exceed {$maxNoteLength} characters (currently {$length}). Please shorten your note or contact your administrator.";
    }
    if ($problems !== []) {
        throw new UnprocessableException(implode(' ', array_values($problems)), ['fields' => $problems]);
    }
}
