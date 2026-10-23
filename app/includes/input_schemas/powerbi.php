<?php

/**
 * Query input of api/powerbi.php (see includes/input_schema.php). Every table takes the same optional paging and date
 * filters; only submissions and answers apply them (the dimension tables are always whole), but they are checked for
 * every table so a connector that sends a bad value learns about it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** The tables, in the order meta lists them (meta itself first). */
const POWERBI_TABLES = ['meta', 'submissions', 'answers', 'stores', 'regions', 'versions', 'categories', 'questions'];

/** Most rows one request returns. */
const POWERBI_MAX_LIMIT = 10000;

/** @return array<string, array<string, mixed>> */
function powerbi_input_schema(): array
{
    return [
        'limit' => in_int(min: 1, max: POWERBI_MAX_LIMIT, default: POWERBI_MAX_LIMIT, label: 'Limit'),
        'offset' => in_int(min: 0, default: 0, label: 'Offset'),
        'date_from' => in_date(label: 'Date from'),
        'date_to' => in_date(label: 'Date to'),
    ];
}

/**
 * The table a request asks for (meta when none), lower-cased. A value that is not text names no table, so it is
 * refused like an unknown name, after the key checks.
 */
function powerbi_table(): string
{
    $table = $_GET['table'] ?? 'meta';
    if (!is_string($table)) {
        return '(not text)';
    }
    $table = strtolower(trim($table));
    return $table === '' ? 'meta' : $table;
}

/**
 * Paging and date filters of the request.
 *
 * @return array{limit: int, offset: int, date_from: ?string, date_to: ?string}
 * @throws BadRequestException|UnprocessableException
 */
function powerbi_input(): array
{
    $values = input_parse(powerbi_input_schema(), $_GET);
    $from = powerbi_exact_date($values, 'date_from', 'Date from');
    $to = powerbi_exact_date($values, 'date_to', 'Date to');
    if ($from !== null && $to !== null && $from > $to) {
        throw new UnprocessableException('Date from must not be after date to.', ['fields' => ['date_from' => 'Date from must not be after date to.']]);
    }
    return ['limit' => $values['limit'], 'offset' => $values['offset'], 'date_from' => $from, 'date_to' => $to];
}

/**
 * A parsed date filter, only when it was sent exactly as YYYY-MM-DD: the date parser also takes words such as "today",
 * other layouts and a time of day, which would make the window depend on the server clock or drop the time silently.
 *
 * @param array<string, mixed> $values
 * @throws UnprocessableException
 */
function powerbi_exact_date(array $values, string $field, string $label): ?string
{
    $date = $values[$field] ?? null;
    $sent = $_GET[$field] ?? '';
    if ($date !== null && (!is_string($sent) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($sent)) !== 1)) {
        throw new UnprocessableException("{$label} must be a date written as YYYY-MM-DD.", ['fields' => [$field => "{$label} must be a date written as YYYY-MM-DD."]]);
    }
    return $date;
}
