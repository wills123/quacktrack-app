<?php

/**
 * Request input of api/products.php, one schema per action (see includes/input_schema.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** products.id is an INT column; a larger number can never name a product. */
const PRODUCTS_MAX_ID = 2147483647;

/** The most products one bulk status change may name. */
const PRODUCTS_BULK_MAX = 500;

/** Search paging and filter limits. */
const PRODUCTS_DEFAULT_LIMIT = 50;
const PRODUCTS_MAX_MANUFACTURERS = 50;
const PRODUCTS_MAX_OFFSET = 1000000;

/** products.status is VARCHAR(50). */
const PRODUCTS_STATUS_MAX = 50;

/** The values of products.status that the bulk change may set. */
const PRODUCT_BULK_STATUSES = ['active', 'inactive', 'discontinued'];

/**
 * Every action's schema, keyed by action name. get_manufacturers and get_categories take no input.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function products_input_schemas(): array
{
    return [
        'search' => [
            'q' => in_string(max: 200),
            'category' => in_string(max: 100, column: 'products.asset_type'),
            'status' => in_string(max: PRODUCTS_STATUS_MAX, column: 'products.status'),
            'manufacturer' => in_list(in_string(max: 100), max: PRODUCTS_MAX_MANUFACTURERS, unique: true, single: true, label: 'Manufacturer'),
            'limit' => in_int(min: 1, max: 100, default: PRODUCTS_DEFAULT_LIMIT, clamp: true),
            'offset' => in_int(min: 0, max: PRODUCTS_MAX_OFFSET, default: 0, clamp: true),
        ],
        'get' => ['id' => in_int(min: 1, max: PRODUCTS_MAX_ID, required: true, label: 'Product ID')],
        'upload_image' => ['product_id' => in_int(min: 1, max: PRODUCTS_MAX_ID, required: true, label: 'Product ID')],
        'bulk_update_status' => [
            'product_ids' => in_list(
                in_int(min: 1, max: PRODUCTS_MAX_ID),
                max: PRODUCTS_BULK_MAX,
                required: true,
                unique: true,
                single: true,
                label: 'Products',
            ),
            'status' => in_enum(PRODUCT_BULK_STATUSES, required: true),
        ],
    ];
}

/**
 * Parse the request for $action. upload_image and bulk_update_status change data, so they read the
 * body only: a GET (which carries no CSRF check) can never reach them with input.
 *
 * @return array<string, mixed>
 */
function products_input(string $action): array
{
    static $schemas = null;
    $schemas ??= products_input_schemas();
    return input_parse($schemas[$action], in_array($action, ['upload_image', 'bulk_update_status'], true) ? $_POST : $_GET);
}
