<?php

/**
 * Query input of api/snipe-it-reports.php (see includes/input_schema.php): an optional date window for every report,
 * and paging for the asset history. A date that is not a real calendar day, or a window that ends before it starts,
 * is refused instead of being passed on.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** The actions this file describes. */
const SNIPE_REPORTS_ACTIONS = ['asset_history', 'combined_summary', 'export_csv'];

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function snipe_reports_input_schemas(): array
{
    $window = ['start_date' => in_date(label: 'Start date'), 'end_date' => in_date(label: 'End date')];
    return [
        'asset_history' => $window + [
            'limit' => in_int(min: 1, max: 500, default: 100, label: 'Limit'),
            'offset' => in_int(min: 0, max: 1000000, default: 0, label: 'Offset'),
        ],
        'combined_summary' => $window,
        'export_csv' => $window,
    ];
}

/**
 * Parse the query for $action.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function snipe_reports_input(string $action): array
{
    static $schemas = null;
    $schemas ??= snipe_reports_input_schemas();
    $values = input_parse($schemas[$action], $_GET);
    $start = $values['start_date'] ?? null;
    $end = $values['end_date'] ?? null;
    if ($start !== null && $end !== null && $start > $end) {
        throw new UnprocessableException('Start date must not be after the end date.', ['fields' => ['start_date' => 'Start date must not be after the end date.']]);
    }
    return $values;
}
