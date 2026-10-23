<?php

/**
 * Request input of api/favorites.php, one schema per action (see includes/input_schema.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** products.id is an INT column; a larger number can never name a product. */
const FAVORITES_MAX_ID = 2147483647;

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function favorites_input_schemas(): array
{
    $product = ['product_id' => in_int(min: 1, max: FAVORITES_MAX_ID, required: true, label: 'Product ID')];

    return [
        'add' => $product,
        'remove' => $product,
        'check' => $product,
        'list' => [
            'limit' => in_int(min: 1, max: 100, default: 25, clamp: true),
            'page' => in_int(min: 1, max: 1000000, default: 1, clamp: true),
        ],
    ];
}

/**
 * Parse the request for $action. add and remove change data, so they read the body only: a GET
 * (which carries no CSRF check) can never reach them with a product id.
 *
 * @return array<string, mixed>
 */
function favorites_input(string $action): array
{
    static $schemas = null;
    $schemas ??= favorites_input_schemas();
    return input_parse($schemas[$action], in_array($action, ['add', 'remove'], true) ? $_POST : $_GET);
}
