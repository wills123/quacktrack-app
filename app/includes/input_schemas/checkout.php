<?php

/**
 * Request input of api/checkout.php, one schema per action (see includes/input_schema.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** stores.id and purchasing_emails.id are INT columns; a larger number can never name a row. */
const CHECKOUT_MAX_ID = 2147483647;

/** The longest idempotency key accepted; the same bound applies to the Idempotency-Key header. */
const CHECKOUT_KEY_MAX = 64;

/**
 * Every action's schema, keyed by action name. A store or email of 0 means "not chosen": the order
 * code answers that with its own message (and the email falls back to the user's preference).
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function checkout_input_schemas(): array
{
    return [
        'create_order' => [
            'store_id' => in_int(min: 0, max: CHECKOUT_MAX_ID, default: 0, label: 'Store'),
            'purchasing_email_id' => in_int(min: 0, max: CHECKOUT_MAX_ID, default: 0, label: 'Purchasing email'),
            'notes' => in_text(default: '', column: 'purchase_orders.notes'),
            'idempotency_key' => in_string(max: CHECKOUT_KEY_MAX),
        ],
    ];
}

/**
 * Parse the request body for $action; checkout reads the body only, never the query string.
 *
 * @return array<string, mixed>
 */
function checkout_input(string $action): array
{
    static $schemas = null;
    $schemas ??= checkout_input_schemas();
    return input_parse($schemas[$action], $_POST);
}
