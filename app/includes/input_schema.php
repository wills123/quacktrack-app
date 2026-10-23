<?php

declare(strict_types=1);

/**
 * Declarative request input: describe an action's fields once, parse the request against it.
 *
 *     $in = input_parse([
 *         'deployment_id' => in_int(min: 1, required: true),
 *         'title'         => in_string(max: 160, required: true, column: 'deployment_quests.title'),
 *         'status'        => in_enum(['open', 'closed'], default: 'open'),
 *         'tags'          => in_list(in_string(max: 100), max: 50, csv: true, json: true),
 *     ], $_POST);
 *
 * Rules every field type follows:
 *
 *  - A supplied value is never replaced by a default or silently dropped. A default applies only
 *    to a field that was not sent. Empty text is a value; an empty string for any other type
 *    (what a blank form control sends) counts as "not sent".
 *  - A field that was not sent and has no default is absent from the result, so callers can tell
 *    "not sent" from "sent empty" with array_key_exists().
 *  - All problems are collected and reported together. A value of the wrong shape or type (an array
 *    where text is expected, non-numeric text for a number, a missing required field) makes the whole
 *    request a 400 BadRequestException; a well-formed value that breaks a rule (too long, out of
 *    range, blank but required, not an allowed word) is a 422 UnprocessableException. If both kinds
 *    occur the request is a 400. The exception's details carry {"fields": {name: message}}.
 *  - Keys the schema does not name are ignored.
 *
 * `column: 'table.column'` ties a field to the database column that stores it; the schema drift test
 * compares the declared limits with the real column definitions.
 */

/** A problem with one field. $malformed distinguishes a 400 (wrong shape/type) from a 422 (rule). */
final class InputFieldError extends Exception
{
    public function __construct(string $message, public readonly bool $malformed = false)
    {
        parent::__construct($message);
    }
}

// --- field declarations ------------------------------------------------------------------------

/**
 * qm-ok: too-many-params - field constructors take named arguments and every option defaults to off
 *
 * @return array<string, mixed>
 */
function in_int(?int $min = null, ?int $max = null, bool $required = false, ?int $default = null, bool $clamp = false, ?string $label = null, ?string $column = null): array
{
    return compact('min', 'max', 'required', 'default', 'clamp', 'label', 'column') + ['type' => 'int'];
}

/**
 * qm-ok: too-many-params - field constructors take named arguments and every option defaults to off
 *
 * @return array<string, mixed>
 */
function in_float(?float $min = null, ?float $max = null, bool $required = false, ?float $default = null, ?string $label = null, ?string $column = null): array
{
    return compact('min', 'max', 'required', 'default', 'label', 'column') + ['type' => 'float'];
}

/** Trimmed text of at most $max characters (a VARCHAR column). @return array<string, mixed> */
function in_string(?int $max = null, bool $required = false, ?string $default = null, ?string $label = null, ?string $column = null): array
{
    return compact('max', 'required', 'default', 'label', 'column') + ['type' => 'string'];
}

/** Exact, untrimmed UTF-8 text; an optional limit counts bytes. @return array<string, mixed> */
function in_raw_string(?int $max = null, bool $required = false, ?string $label = null): array
{
    return in_string(max: $max, required: $required, label: $label) + ['raw' => true];
}

/** Trimmed text of at most 65,535 bytes (a TEXT column). @return array<string, mixed> */
function in_text(bool $required = false, ?string $default = null, ?string $label = null, ?string $column = null): array
{
    return compact('required', 'default', 'label', 'column') + ['type' => 'text'];
}

/**
 * One of $values, compared case-insensitively; the result is the canonical (listed) spelling.
 *
 * @param list<string> $values
 * @return array<string, mixed>
 */
function in_enum(array $values, bool $required = false, ?string $default = null, ?string $label = null): array
{
    return compact('values', 'required', 'default', 'label') + ['type' => 'enum'];
}

/** true/false, 1/0, on/off, yes/no (any case). Anything else is rejected, never read as false. @return array<string, mixed> */
function in_flag(?bool $default = null, ?string $label = null): array
{
    return compact('default', 'label') + ['type' => 'flag', 'required' => false];
}

/** A calendar date, returned as Y-m-d. @return array<string, mixed> */
function in_date(bool $required = false, ?string $default = null, ?string $label = null): array
{
    return compact('required', 'default', 'label') + ['type' => 'date'];
}

/** A date and time, returned as Y-m-d H:i:s. @return array<string, mixed> */
function in_datetime(bool $required = false, ?string $default = null, ?string $label = null): array
{
    return compact('required', 'default', 'label') + ['type' => 'datetime'];
}

/**
 * A list of values described by $item. Accepts an array; with $json also a JSON-encoded list;
 * with $csv also comma-separated text; with $single also one bare value. Blank entries are
 * dropped, $unique removes repeats (first one wins), more than $max entries is a 422.
 * qm-ok: too-many-params - field constructors take named arguments and every option defaults to off
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function in_list(array $item, int $max = 200, bool $required = false, bool $unique = false, bool $single = false, bool $csv = false, bool $json = false, ?string $label = null): array
{
    return compact('item', 'max', 'required', 'unique', 'single', 'csv', 'json', 'label') + ['type' => 'list'];
}

/**
 * Records with the fields in $fields, sent as an array of arrays (a list or an object, always
 * re-indexed from 0) or as JSON text of one. A record whose $skipBlank field is empty is dropped
 * (the blank rows of a form), but a non-empty input that leaves no record is a 422: a replacing
 * update would otherwise erase everything stored.
 *
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, mixed>
 */
function in_records(array $fields, int $max = 100, bool $required = false, ?string $skipBlank = null, ?string $label = null): array
{
    return compact('fields', 'max', 'required', 'skipBlank', 'label') + ['type' => 'records'];
}

/**
 * Whole-number keys (record ids) mapped to values described by $item, sent as an array or as a
 * JSON object. Result keys are ints.
 *
 * @param array<string, mixed> $item
 * @return array<string, mixed>
 */
function in_map(array $item, int $max = 200, bool $required = false, ?string $label = null): array
{
    return compact('item', 'max', 'required', 'label') + ['type' => 'map'];
}

// --- parsing -----------------------------------------------------------------------------------

/**
 * Parse $source against $schema (field name => declaration) and return the typed values.
 *
 * @param array<string, array<string, mixed>> $schema
 * @param array<int|string, mixed> $source
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function input_parse(array $schema, array $source): array
{
    $errors = [];
    $values = input_parse_fields($schema, $source, '', $errors);
    if ($errors === []) {
        return $values;
    }

    $malformed = false;
    $fields = [];
    foreach ($errors as $path => $error) {
        $malformed = $malformed || $error->malformed;
        $fields[$path] = $error->getMessage();
    }
    $messages = array_values($fields);
    $message = implode(' ', array_slice($messages, 0, 3));
    if (count($messages) > 3) {
        $message .= ' (and ' . (count($messages) - 3) . ' more problems)';
    }
    $details = ['fields' => $fields];
    throw $malformed ? new BadRequestException($message, $details) : new UnprocessableException($message, $details);
}

/**
 * @param array<string, array<string, mixed>> $schema
 * @param array<int|string, mixed> $source
 * @param array<string, InputFieldError> $errors collected by reference, keyed by field path
 * @param string $labelPrefix names the parent in messages about a record's fields ("Objectives label ...")
 * @return array<string, mixed>
 */
function input_parse_fields(array $schema, array $source, string $prefix, array &$errors, string $labelPrefix = ''): array
{
    $values = [];
    foreach ($schema as $name => $spec) {
        $path = $prefix === '' ? $name : "{$prefix}.{$name}";
        try {
            [$present, $value] = input_parse_field($spec, $source[$name] ?? null, input_label($spec, $name, $labelPrefix), $path, $errors);
        } catch (InputFieldError $e) {
            $errors[$path] = $e;
            continue;
        }
        if ($present) {
            $values[$name] = $value;
        }
    }
    return $values;
}

/**
 * One field: its parsed value, its default when it was not sent (an empty string counts as not
 * sent, except for text), or nothing. A required field that was not sent throws.
 *
 * @param array<string, mixed> $spec
 * @param array<string, InputFieldError> $errors
 * @return array{0: bool, 1: mixed} whether the field has a value, and the value
 */
function input_parse_field(array $spec, mixed $raw, string $label, string $path, array &$errors): array
{
    $notSent = $raw === null || ($raw === '' && !in_array($spec['type'], ['string', 'text'], true));
    if (!$notSent) {
        return [true, input_parse_value($spec, $raw, $label, $path, $errors)];
    }
    if (!empty($spec['required'])) {
        throw new InputFieldError("{$label} is required.", true);
    }
    $default = $spec['default'] ?? null;
    return [$default !== null, $default];
}

/** @param array<string, mixed> $spec */
function input_label(array $spec, string $name, string $parent = ''): string
{
    $words = isset($spec['label']) && is_string($spec['label'])
        ? $spec['label']
        : str_replace('_', ' ', (string)preg_replace('/_id$/', ' ID', $name));
    return $parent === '' ? ucfirst($words) : $parent . ' ' . lcfirst($words);
}

/**
 * Parse one present value. Nested problems of list/record/map items are added to $errors under
 * their own path; a problem with the value itself is thrown.
 * qm-ok: cyclomatic - a flat dispatch on the field type: one arm per type, each arm a single call
 *
 * @param array<string, mixed> $spec
 * @param array<string, InputFieldError> $errors
 */
function input_parse_value(array $spec, mixed $raw, string $label, string $path, array &$errors): mixed
{
    return match ($spec['type']) {
        'int' => input_value_int($spec, $raw, $label),
        'float' => input_value_float($spec, $raw, $label),
        'string' => input_value_string($spec, $raw, $label),
        'text' => input_value_text($spec, $raw, $label),
        'enum' => input_value_enum($spec, $raw, $label),
        'flag' => input_value_flag($raw, $label),
        'date' => input_value_date($raw, $label),
        'datetime' => input_value_datetime($raw, $label),
        'list' => input_value_list($spec, $raw, $label, $path, $errors),
        'records' => input_value_records($spec, $raw, $label, $path, $errors),
        'map' => input_value_map($spec, $raw, $label, $path, $errors),
        default => throw new LogicException("Unknown input type '{$spec['type']}'."),
    };
}

/** @param array<string, mixed> $spec */
function input_value_int(array $spec, mixed $raw, string $label): int
{
    $int = is_int($raw) || is_string($raw) ? qt_parse_int($raw) : null;
    if ($int === null) {
        throw new InputFieldError("{$label} must be a whole number.", true);
    }
    if (!empty($spec['clamp'])) {
        return max($spec['min'] ?? PHP_INT_MIN, min($spec['max'] ?? PHP_INT_MAX, $int));
    }
    input_check_range($int, $spec['min'], $spec['max'], $label);
    return $int;
}

/** @throws InputFieldError when $value is outside the bounds that are set */
function input_check_range(int|float $value, int|float|null $min, int|float|null $max, string $label): void
{
    if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
        throw new InputFieldError(input_range_message($label, $min, $max));
    }
}

/** @param array<string, mixed> $spec */
function input_value_float(array $spec, mixed $raw, string $label): float
{
    $float = is_int($raw) || is_float($raw) || is_string($raw) ? qt_parse_float($raw) : null;
    if ($float === null) {
        throw new InputFieldError("{$label} must be a number.", true);
    }
    input_check_range($float, $spec['min'], $spec['max'], $label);
    return $float;
}

function input_range_message(string $label, int|float|null $min, int|float|null $max): string
{
    if ($min !== null && $max !== null) {
        return "{$label} must be between {$min} and {$max}.";
    }
    return $min !== null ? "{$label} must be at least {$min}." : "{$label} must be at most {$max}.";
}

/** Text that is a string, valid UTF-8 and trimmed. @throws InputFieldError */
function input_clean_text(mixed $raw, string $label): string
{
    return trim(input_raw_text($raw, $label));
}

/** Valid UTF-8 without NUL, preserving the exact input bytes. @throws InputFieldError */
function input_raw_text(mixed $raw, string $label): string
{
    if (!is_string($raw)) {
        throw new InputFieldError("{$label} must be text.", true);
    }
    // The global input guard refuses NUL bytes in the request itself, but JSON text sent in a field
    // ("\u0000" inside a records or list value) only becomes a NUL once it has been decoded.
    if (!mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
        throw new InputFieldError("{$label} contains invalid characters.", true);
    }
    return $raw;
}

/** @param array<string, mixed> $spec */
function input_value_string(array $spec, mixed $raw, string $label): string
{
    $rawText = !empty($spec['raw']);
    $text = $rawText ? input_raw_text($raw, $label) : input_clean_text($raw, $label);
    if ($text === '' && !empty($spec['required'])) {
        throw new InputFieldError("{$label} is required.");
    }
    if ($spec['max'] !== null && ($rawText ? strlen($text) : mb_strlen($text)) > $spec['max']) {
        $unit = $rawText ? 'bytes' : 'characters';
        throw new InputFieldError("{$label} must be {$spec['max']} {$unit} or fewer.");
    }
    return $text;
}

/** @param array<string, mixed> $spec */
function input_value_text(array $spec, mixed $raw, string $label): string
{
    $text = input_clean_text($raw, $label);
    if ($text === '' && !empty($spec['required'])) {
        throw new InputFieldError("{$label} is required.");
    }
    if (strlen($text) > 65535) {
        throw new InputFieldError("{$label} is too long.");
    }
    return $text;
}

/** @param array<string, mixed> $spec */
function input_value_enum(array $spec, mixed $raw, string $label): string
{
    $word = strtolower(input_clean_text($raw, $label));
    foreach ($spec['values'] as $allowed) {
        if (strtolower($allowed) === $word) {
            return $allowed;
        }
    }
    throw new InputFieldError("{$label} must be one of: " . implode(', ', $spec['values']) . '.');
}

function input_value_flag(mixed $raw, string $label): bool
{
    if (is_bool($raw)) {
        return $raw;
    }
    $word = is_int($raw) ? (string)$raw : (is_string($raw) ? strtolower(trim($raw)) : null);
    if (in_array($word, ['1', 'true', 'on', 'yes'], true)) {
        return true;
    }
    if (in_array($word, ['0', 'false', 'off', 'no'], true)) {
        return false;
    }
    throw new InputFieldError("{$label} must be true or false.", true);
}

function input_value_date(mixed $raw, string $label): string
{
    return qt_parse_date(input_clean_text($raw, $label)) ?? throw new InputFieldError("{$label} must be a valid date.");
}

function input_value_datetime(mixed $raw, string $label): string
{
    return qt_parse_datetime(input_clean_text($raw, $label)) ?? throw new InputFieldError("{$label} must be a valid date and time.");
}

/**
 * Turn a list-like input into an array of raw items, or fail. Strings are split according to the
 * options of $spec (JSON, comma-separated, or a single value).
 *
 * @param array<string, mixed> $spec
 * @return array<int|string, mixed>
 */
function input_list_items(array $spec, mixed $raw, string $label): array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw)) {
        throw new InputFieldError("{$label} must be a list.", true);
    }
    $text = trim($raw);
    if ($text === '') {
        return [];
    }
    if (!empty($spec['json']) && ($text[0] === '[')) {
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new InputFieldError("{$label} must be a list.", true);
        }
        return $decoded;
    }
    if (!empty($spec['csv'])) {
        return explode(',', $text);
    }
    if (!empty($spec['single'])) {
        return [$text];
    }
    throw new InputFieldError("{$label} must be a list.", true);
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, InputFieldError> $errors
 * @return list<mixed>
 */
function input_value_list(array $spec, mixed $raw, string $label, string $path, array &$errors): array
{
    $items = [];
    foreach (input_list_items($spec, $raw, $label) as $index => $rawItem) {
        if ($rawItem === null || (is_string($rawItem) && trim($rawItem) === '')) {
            continue; // a blank entry (an empty form row) is not an entry
        }
        try {
            $items[] = input_parse_value($spec['item'], $rawItem, $label . ' entry', "{$path}[{$index}]", $errors);
        } catch (InputFieldError $e) {
            $errors["{$path}[{$index}]"] = $e;
        }
    }
    if (!empty($spec['unique'])) {
        $items = input_unique_items($items);
    }
    input_check_count($items, $spec, $label);
    return $items;
}

/**
 * $items without repeats, first occurrence kept; text compares case-insensitively.
 *
 * @param list<mixed> $items
 * @return list<mixed>
 */
function input_unique_items(array $items): array
{
    $unique = [];
    foreach ($items as $value) {
        $key = is_string($value) ? 's:' . mb_strtolower($value) : serialize($value);
        $unique[$key] ??= $value;
    }
    return array_values($unique);
}

/**
 * The entry-count rules shared by lists, records and maps: at most $spec['max'] entries, and at
 * least one when the field is required.
 *
 * @param array<int|string, mixed> $items
 * @param array<string, mixed> $spec
 */
function input_check_count(array $items, array $spec, string $label): void
{
    if (count($items) > $spec['max']) {
        throw new InputFieldError("{$label} can have at most {$spec['max']} entries.");
    }
    if ($items === [] && !empty($spec['required'])) {
        throw new InputFieldError("{$label} is required.");
    }
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, InputFieldError> $errors
 * @return list<array<string, mixed>>
 */
function input_value_records(array $spec, mixed $raw, string $label, string $path, array &$errors): array
{
    $rawRecords = input_records_source($raw, $label);
    $records = [];
    $errorsBefore = count($errors);
    foreach (array_values($rawRecords) as $index => $rawRecord) {
        $record = input_parse_record($spec, $rawRecord, $label, "{$path}[{$index}]", $errors);
        if ($record !== null) {
            $records[] = $record;
        }
    }
    $noUsableRecord = $rawRecords !== [] && $records === [] && $spec['skipBlank'] !== null && count($errors) === $errorsBefore;
    if ($noUsableRecord) {
        throw new InputFieldError("{$label} needs at least one entry with a " . str_replace('_', ' ', $spec['skipBlank']) . '.');
    }
    input_check_count($records, $spec, $label);
    return $records;
}

/**
 * The raw records of a records field: an array as sent, or decoded from JSON text; blank text is
 * no records.
 *
 * @return array<int|string, mixed>
 */
function input_records_source(mixed $raw, string $label): array
{
    if (is_string($raw) && trim($raw) === '') {
        return [];
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) {
        throw new InputFieldError("{$label} must be a list of entries.", true);
    }
    return $decoded;
}

/**
 * One record, or null when it is not usable: not an object (recorded in $errors) or blank in its
 * skipBlank field (an empty form row).
 *
 * @param array<string, mixed> $spec
 * @param array<string, InputFieldError> $errors
 * @return array<string, mixed>|null
 */
function input_parse_record(array $spec, mixed $rawRecord, string $label, string $recordPath, array &$errors): ?array
{
    if (!is_array($rawRecord)) {
        $errors[$recordPath] = new InputFieldError("{$label} entry must be an object.", true);
        return null;
    }
    $record = input_parse_fields($spec['fields'], $rawRecord, $recordPath, $errors, $label);
    $skip = $spec['skipBlank'];
    if ($skip !== null && trim((string)($record[$skip] ?? '')) === '') {
        return null;
    }
    return $record;
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, InputFieldError> $errors
 * @return array<int, mixed>
 */
function input_value_map(array $spec, mixed $raw, string $label, string $path, array &$errors): array
{
    if (is_string($raw)) {
        if (trim($raw) === '') {
            return [];
        }
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        throw new InputFieldError("{$label} must be an object keyed by id.", true);
    }
    if (count($raw) > $spec['max']) {
        throw new InputFieldError("{$label} can have at most {$spec['max']} entries.");
    }
    $map = [];
    foreach ($raw as $key => $rawValue) {
        $id = qt_parse_int((string)$key);
        if ($id === null || $id <= 0) {
            throw new InputFieldError("{$label} must be keyed by id.", true);
        }
        try {
            $map[$id] = input_parse_value($spec['item'], $rawValue, "{$label} entry {$id}", "{$path}[{$id}]", $errors);
        } catch (InputFieldError $e) {
            $errors["{$path}[{$id}]"] = $e;
        }
    }
    return $map;
}

// --- dates (shared with the services) ------------------------------------------------------------

/**
 * PHP's date parser accepts far more than a form sends: it reads "10000-01-01" as a different date
 * entirely and ignores some control characters. Refuse those before parsing so a typo is never
 * stored as another date.
 */
function qt_date_text_is_plausible(string $text): bool
{
    return preg_match('/[\x00-\x1F\x7F]/', $text) !== 1
        && preg_match('/\d{5,}/', $text) !== 1
        && !str_starts_with($text, '-');
}

/** MySQL DATE/DATETIME hold years 1000-9999; PHP also parses year 0, negative and 5-digit years. */
function qt_year_is_storable(DateTimeImmutable $date): bool
{
    $year = (int)$date->format('Y');
    return $year >= 1000 && $year <= 9999;
}

function qt_parse_datetime_text(string $text): ?DateTimeImmutable
{
    if (!qt_date_text_is_plausible($text)) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($text);
    } catch (Throwable $e) {
        return null;
    }
    // "2026-02-31" parses, with a warning, as 2026-03-03: a typo must be refused, not stored as another day.
    $problems = DateTimeImmutable::getLastErrors();
    if ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) {
        return null;
    }
    return qt_year_is_storable($date) ? $date : null;
}

/** "Y-m-d" for text that is a plausible, storable date; null otherwise. */
function qt_parse_date(string $text): ?string
{
    return qt_parse_datetime_text(trim($text))?->format('Y-m-d');
}

/** "Y-m-d H:i:s" for text that is a plausible, storable date and time; null otherwise. */
function qt_parse_datetime(string $text): ?string
{
    return qt_parse_datetime_text(trim($text))?->format('Y-m-d H:i:s');
}

// --- introspection (schema drift test, fuzzer) -----------------------------------------------------

/**
 * The description of one field (see input_schema_describe()).
 *
 * @param array<string, mixed> $spec
 * @return array<string, mixed>
 */
function input_describe_field(string $path, array $spec): array
{
    return [
        'path' => $path,
        'type' => $spec['type'],
        'required' => !empty($spec['required']),
        'min' => $spec['min'] ?? null,
        'max' => $spec['max'] ?? ($spec['type'] === 'text' ? 65535 : null),
        'column' => $spec['column'] ?? null,
        'clamp' => !empty($spec['clamp']),
        'csv' => !empty($spec['csv']),
        'json' => !empty($spec['json']),
        'values' => $spec['values'] ?? null,
        'item' => $spec['item']['type'] ?? null,
    ] + (!empty($spec['raw']) ? ['raw' => true] : []);
}

/**
 * Flatten a schema into one description per field, including nested record fields and list items:
 * [{path, type, required, min, max, column, clamp, csv, json, values, item}, ...] (`values` lists an enum's words, `item` names
 * the type of a list's or map's entries). Used by tests and tooling that need to know what
 * a schema promises without parsing a request.
 *
 * @param array<string, array<string, mixed>> $schema
 * @return list<array<string, mixed>>
 */
function input_schema_describe(array $schema, string $prefix = ''): array
{
    $rows = [];
    foreach ($schema as $name => $spec) {
        $path = $prefix === '' ? $name : "{$prefix}.{$name}";
        $rows[] = input_describe_field($path, $spec);
        if ($spec['type'] === 'records') {
            array_push($rows, ...input_schema_describe($spec['fields'], $path));
        } elseif (isset($spec['item'])) {
            array_push($rows, ...input_schema_describe(['*' => $spec['item']], $path));
        }
    }
    return $rows;
}
