<?php

/**
 * Query input of api/quackmap-assets.php (see includes/input_schema.php): the Quack Track store, and an optional page
 * of its assets (the first 100 when none is asked for, as before).
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** @return array<string, array<string, mixed>> */
function quackmap_assets_input_schema(): array
{
    return [
        // stores.id is an INT column. A decimal id with leading zeros ("007") is the same id.
        'store_id' => in_int(min: 1, max: 2147483647, required: true, label: 'store_id'),
        'limit' => in_int(min: 1, max: 500, default: 100, label: 'Limit'),
        'offset' => in_int(min: 0, max: 1000000, default: 0, label: 'Offset'),
    ];
}

/**
 * @return array{store_id: int, limit: int, offset: int}
 * @throws BadRequestException|UnprocessableException
 */
function quackmap_assets_input(): array
{
    $values = input_parse(quackmap_assets_input_schema(), $_GET);
    return ['store_id' => $values['store_id'], 'limit' => $values['limit'], 'offset' => $values['offset']];
}
