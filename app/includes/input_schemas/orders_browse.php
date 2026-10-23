<?php

/**
 * Request input of the order browsing actions of api/orders.php (list, get, reorder, cancel), one schema per
 * action (see includes/input_schema.php). The status and receipt actions of the same endpoint keep their own
 * handlers. purchase_orders.status is the ENUM of db/migrations/077; tests/schema_drift_test.php checks the
 * id binding.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** purchase_orders.id and stores.id / users.id are INT columns. */
const ORDERS_BROWSE_MAX_ID = 2147483647;

/** The states an order can be in (purchase_orders.status). */
const ORDERS_BROWSE_STATUSES = ['draft', 'sent', 'partially_received', 'received', 'cancelled'];

/** The actions that only read; the others change data. */
const ORDERS_BROWSE_READ_ACTIONS = ['list', 'get'];

/**
 * Every action's schema, keyed by action name. The list's paging is clamped into range, as it always was.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function orders_browse_input_schemas(): array
{
    $orderId = ['order_id' => in_int(min: 1, max: ORDERS_BROWSE_MAX_ID, required: true, label: 'Order', column: 'purchase_orders.id')];
    return [
        'list' => [
            'limit' => in_int(min: 1, max: 100, default: 25, clamp: true, label: 'Limit'),
            'page' => in_int(min: 1, max: 1000000, default: 1, clamp: true, label: 'Page'),
            'status' => in_enum(ORDERS_BROWSE_STATUSES, label: 'Status'),
            'store_id' => in_int(min: 1, max: ORDERS_BROWSE_MAX_ID, label: 'Store'),
            'user_id' => in_int(min: 1, max: ORDERS_BROWSE_MAX_ID, label: 'User'),
        ],
        'get' => $orderId,
        'reorder' => $orderId,
        'cancel' => $orderId,
    ];
}

/**
 * Parse the request for $action: reads take the query, changes the body.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function orders_browse_input(string $action): array
{
    static $schemas = null;
    $schemas ??= orders_browse_input_schemas();
    return input_parse($schemas[$action], in_array($action, ORDERS_BROWSE_READ_ACTIONS, true) ? $_GET : $_POST);
}
