<?php

/**
 * Request input of the catalog actions of api/snipe-it-assets.php (list, the reference lists and users), one schema
 * per action (see includes/input_schema.php). Nothing here is stored locally, so there are no column bindings; the
 * bounds are the ones the UI and Snipe-IT's own paging use. The reference lists take no input.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** Snipe-IT ids are INT columns. */
const SNIPE_CATALOG_MAX_ID = 2147483647;

/** The longest search text sent to Snipe-IT. */
const SNIPE_CATALOG_SEARCH_MAX = 100;

/** The assets the list can be sorted by. */
const SNIPE_CATALOG_SORTS = ['id', 'name', 'asset_tag', 'serial', 'created_at', 'updated_at'];

/** The reference lists, by action, with the Snipe-IT list each reads. */
const SNIPE_CATALOG_REFERENCES = [
    'categories' => 'categories', 'models' => 'models', 'statuses' => 'statuslabels',
    'locations' => 'locations', 'manufacturers' => 'manufacturers', 'suppliers' => 'suppliers',
];

/**
 * Every action's schema, keyed by action name. The list's paging is clamped into range, as it always was.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function snipe_catalog_input_schemas(): array
{
    $id = static fn(string $label): array => in_int(min: 1, max: SNIPE_CATALOG_MAX_ID, label: $label);
    $schemas = [
        'list' => [
            'limit' => in_int(min: 1, max: 100, default: 50, clamp: true, label: 'Limit'),
            'offset' => in_int(min: 0, max: 1000000, default: 0, clamp: true, label: 'Offset'),
            'search' => in_string(max: SNIPE_CATALOG_SEARCH_MAX, label: 'Search'),
            'status_id' => $id('Status'),
            'category_id' => $id('Category'),
            'model_id' => $id('Model'),
            'location_id' => $id('Location'),
            'manufacturer_id' => $id('Manufacturer'),
            'sort' => in_enum(SNIPE_CATALOG_SORTS, label: 'Sort'),
            'order' => in_enum(['asc', 'desc'], label: 'Order'),
        ],
        'users' => ['search' => in_string(max: SNIPE_CATALOG_SEARCH_MAX, label: 'Search')],
    ];
    return $schemas + array_fill_keys(array_keys(SNIPE_CATALOG_REFERENCES), []);
}

/**
 * Parse the query for $action.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function snipe_catalog_input(string $action): array
{
    static $schemas = null;
    $schemas ??= snipe_catalog_input_schemas();
    return input_parse($schemas[$action], $_GET);
}
