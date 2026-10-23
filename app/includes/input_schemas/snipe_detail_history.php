<?php

/**
 * Query input of the asset detail, activity and statistics actions of api/snipe-it-assets.php (see
 * includes/input_schema.php). Snipe-IT asset ids are positive integers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** The actions this file describes. */
const SNIPE_DETAIL_HISTORY_ACTIONS = ['get', 'activity', 'stats'];

/** Snipe-IT keeps asset ids in an INT column. */
const SNIPE_DETAIL_HISTORY_MAX_ID = 2147483647;

/**
 * Every action's schema, keyed by action name (stats takes no input).
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function snipe_detail_history_input_schemas(): array
{
    $id = in_int(min: 1, max: SNIPE_DETAIL_HISTORY_MAX_ID, required: true, label: 'Asset ID');
    return [
        'get' => ['id' => $id],
        'activity' => [
            'id' => $id,
            // The panel shows the latest 20; more can be paged in.
            'limit' => in_int(min: 1, max: 100, default: 20, label: 'Limit'),
            'offset' => in_int(min: 0, max: 1000000, default: 0, label: 'Offset'),
        ],
        'stats' => [],
    ];
}

/**
 * Parse the query for $action.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function snipe_detail_history_input(string $action): array
{
    static $schemas = null;
    $schemas ??= snipe_detail_history_input_schemas();
    return input_parse($schemas[$action], $_GET);
}
