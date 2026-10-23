<?php

declare(strict_types=1);

// BadRequestException (and the other typed client errors) live in api_errors.php.
require_once __DIR__ . '/api_errors.php';

/**
 * Safe reading of request input, and the "bad request" safety net.
 *
 * Why this exists: $_GET / $_POST values are string|array. `?name[]=x` makes
 * $_POST['name'] an array, and `??` only guards "missing", not "wrong type", so
 * `trim($_POST['name'] ?? '')` throws a TypeError (an Error, which `catch (Exception)`
 * does not catch) and the visitor gets a 500. Casting with (int) is no better:
 * (int)['x'] is 1 and (int)"12abc" is 12.
 *
 * Use these helpers instead of reading and casting request values by hand. They never
 * throw: a missing OR wrongly-typed value gives the default.
 *
 *   $name   = req_string($_POST, 'name', '', 120);
 *   $id     = req_int($_GET, 'id', 0, 1);
 *   $status = req_enum($_POST, 'status', ['open', 'closed'], 'open');
 *
 * JSON bodies and nested data follow the same rule: decode once with req_json() and read
 * every field with req_*(). For lists use req_string_list()/req_int_list() (elements of
 * the wrong type are dropped) or req_record_list() (only array elements are kept) and read
 * each record's fields with req_*() again, so nested arrays never reach a scalar function.
 */

/** Trimmed string; $default when missing or not a string; cut to $max characters. */
function req_string(array $source, string $key, string $default = '', ?int $max = null): string
{
    $value = $source[$key] ?? null;
    if (!is_string($value)) {
        return $default;
    }
    $value = trim($value);
    return $max !== null && mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
}

/** String exactly as sent (no trimming), for passwords and tokens; $default when missing or not a string. */
function req_raw_string(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? null;
    return is_string($value) ? $value : $default;
}

/**
 * Parse a whole number from an int or a string of digits with an optional sign and optional
 * surrounding whitespace ("007" is 7, as (int) always read it). Returns null for anything
 * else: "12abc", "1.5", arrays, and values outside the native integer range
 * (PHP_INT_MIN..PHP_INT_MAX for the platform; on 64-bit builds that includes 19-digit BIGINT ids).
 */
function qt_parse_int(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (!is_string($value) || preg_match('/^([+-]?)0*(\d+)$/', trim($value), $m) !== 1) {
        return null;
    }
    // Compare as digit strings against the platform limits; never cast an out-of-range value.
    $negative = $m[1] === '-';
    $digits = ltrim($m[2], '0') === '' ? '0' : ltrim($m[2], '0');
    $limit = $negative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
    if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        return null;
    }
    return $negative ? (int)('-' . $digits) : (int)$digits;
}

/** Deepest nesting accepted in $_GET/$_POST: `rows[0][items][2][name]` is 4 levels. */
const QT_MAX_INPUT_DEPTH = 16;

/**
 * Describe the first thing wrong with a request array, or null when it is clean. A request value
 * is refused when it is not valid UTF-8 (the database rejects it with an error), contains a NUL
 * byte, or is nested deeper than QT_MAX_INPUT_DEPTH. Keys are checked as well as values. The
 * returned text never contains the offending input.
 *
 * @param array<int|string, mixed> $source
 */
function qt_find_bad_input(array $source, int $depth = 1): ?string
{
    if ($depth > QT_MAX_INPUT_DEPTH) {
        return 'Request data is nested too deeply.';
    }
    foreach ($source as $key => $value) {
        if (qt_text_is_malformed((string)$key) || (is_string($value) && qt_text_is_malformed($value))) {
            return 'Request contains invalid characters.';
        }
        $problem = is_array($value) ? qt_find_bad_input($value, $depth + 1) : null;
        if ($problem !== null) {
            return $problem;
        }
    }
    return null;
}

/** Text the database would refuse or truncate: invalid UTF-8, or a NUL byte. */
function qt_text_is_malformed(string $text): bool
{
    return str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8');
}

/**
 * Refuse a request whose query string or form body is malformed (see qt_find_bad_input()) with a
 * 400 before any handler runs, so no page or API has to defend against invalid UTF-8 or NUL bytes
 * itself. Called once from config.php.
 *
 * @param array<int|string, mixed> $get
 * @param array<int|string, mixed> $post
 */
function qt_assert_clean_request_input(array $get, array $post): void
{
    $problem = qt_find_bad_input($get) ?? qt_find_bad_input($post);
    if ($problem !== null) {
        throw new BadRequestException($problem);
    }
}

/** Whole number; $default when missing or not an integer string (no "12abc", no arrays). */
function req_int(array $source, string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $int = qt_parse_int($source[$key] ?? null);
    if ($int === null || ($min !== null && $int < $min) || ($max !== null && $int > $max)) {
        return $default;
    }
    return $int;
}

/**
 * Whole number that must be present and within [$min, $max]; anything else throws
 * BadRequestException (a 400). For values that drive a destructive action or are written back,
 * where silently using a default (req_int) would do something the caller did not ask for.
 */
function req_int_strict(array $source, string $key, int $min, int $max): int
{
    $int = qt_parse_int($source[$key] ?? null);
    if ($int === null || $int < $min || $int > $max) {
        throw new BadRequestException("Field '{$key}' must be a whole number between {$min} and {$max}.");
    }
    return $int;
}

/**
 * Parse a finite decimal number from an int, float or numeric string. Returns null for
 * anything else: arrays, non-numeric text, and values outside the float range ("1e999"),
 * so callers can reject them instead of falling back to a default that looks like data.
 */
function qt_parse_float(mixed $value): ?float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
        return null;
    }
    $float = (float)$value;
    return is_finite($float) ? $float : null;
}

/** Decimal number; $default when missing, not numeric, not finite, or an array. */
function req_float(array $source, string $key, float $default = 0.0): float
{
    return qt_parse_float($source[$key] ?? null) ?? $default;
}

/**
 * The requested `action`: the POST field when one was posted, otherwise the GET parameter.
 * Always a string ($default when missing or wrong-typed).
 */
function req_action(string $default = ''): string
{
    return isset($_POST['action']) ? req_raw_string($_POST, 'action', $default) : req_raw_string($_GET, 'action', $default);
}

/** Checkbox/flag style value: 1, true, on, yes (case-insensitive) is true; anything else false. */
function req_bool(array $source, string $key, bool $default = false): bool
{
    $value = $source[$key] ?? null;
    if (is_bool($value)) {
        return $value;
    }
    if (!is_string($value) && !is_int($value)) {
        return $default;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true);
}

/** One of $allowed (strict string match); otherwise $default. */
function req_enum(array $source, string $key, array $allowed, string $default = ''): string
{
    $value = $source[$key] ?? null;
    return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Shared list reader. $convert returns the cleaned element, or null for an invalid one.
 *
 * Lenient (default): a missing or non-array field is [], invalid elements are dropped and the
 * list is cut at $maxItems. Use it for filters, search boxes and other read-only input.
 *
 * Strict: a missing or null field is [] (nothing was submitted), but a non-array field, any
 * invalid element or more than $maxItems entries throws BadRequestException (a 400). Use it
 * whenever the list REPLACES stored data (permissions, role assignments, ...): silently
 * dropping a malformed entry there would delete something the user meant to keep.
 * qm-ok: flag-param - $strict is the documented switch of the req_*_list() family; each caller picks it per field
 *
 * @param callable(mixed): mixed $convert
 * @return list<mixed>
 */
function qt_collect_list(array $source, string $key, int $maxItems, bool $strict, callable $convert): array
{
    $out = [];
    foreach (qt_list_source($source, $key, $maxItems, $strict) as $item) {
        $converted = $convert($item);
        if ($converted === null && $strict) {
            throw new BadRequestException("Field '{$key}' contains an invalid entry.");
        }
        if ($converted === null) {
            continue;
        }
        $out[] = $converted;
        if (count($out) >= $maxItems) {
            break;
        }
    }
    return $out;
}

/**
 * The raw entries qt_collect_list() reads: [] when nothing usable was sent (lenient), and in strict
 * mode a 400 for a non-list field or one with more than $maxItems entries.
 * qm-ok: flag-param - $strict is the documented switch of the req_*_list() family; each caller picks it per field
 *
 * @return array<int|string, mixed>
 */
function qt_list_source(array $source, string $key, int $maxItems, bool $strict): array
{
    $value = $source[$key] ?? null;
    if (!$strict) {
        return $maxItems > 0 && is_array($value) ? $value : [];
    }
    if ($value === null) {
        return [];
    }
    if (!is_array($value)) {
        throw new BadRequestException("Field '{$key}' must be a list.");
    }
    if (count($value) > max(0, $maxItems)) {
        throw new BadRequestException("Too many entries in '{$key}' (maximum " . max(0, $maxItems) . ').');
    }
    return $value;
}

/** A list of trimmed strings (e.g. `ids[]=a&ids[]=b`); see qt_collect_list() for $strict. */
function req_string_list(array $source, string $key, int $maxItems = 200, bool $strict = false): array
{
    return qt_collect_list($source, $key, $maxItems, $strict, static fn (mixed $item): ?string => is_string($item) ? trim($item) : null);
}

/** A list of integers (e.g. `ids[]=1&ids[]=2`); see qt_collect_list() for $strict. */
function req_int_list(array $source, string $key, int $maxItems = 200, bool $strict = false): array
{
    return qt_collect_list($source, $key, $maxItems, $strict, static fn (mixed $item): ?int => qt_parse_int($item));
}

/**
 * A list of records (e.g. JSON `items: [{sku, quantity}, ...]` or `rows[0][name]=..`): only
 * the elements that are themselves arrays are kept (strict: any other element throws).
 * Read each field of every record with req_string()/req_int()/..., never directly, so a
 * nested array or object in the wrong place cannot reach a scalar function.
 */
function req_record_list(array $source, string $key, int $maxItems = 200, bool $strict = false): array
{
    return qt_collect_list($source, $key, $maxItems, $strict, static fn (mixed $item): ?array => is_array($item) ? $item : null);
}

/**
 * The decoded JSON request body as an array. Use it instead of
 * json_decode(file_get_contents('php://input')) and then read fields with req_*().
 * Returns [] for an empty body or JSON that is not an object/array. With $required, an
 * invalid body throws BadRequestException (a 400).
 * qm-ok: flag-param - $required is the documented switch of req_json(); a body is optional for some endpoints
 */
function req_json(bool $required = false): array
{
    static $cache = null;
    if ($cache === null) {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $cache = ['ok' => is_array($decoded), 'data' => is_array($decoded) ? $decoded : []];
    }
    if ($required && !$cache['ok']) {
        throw new BadRequestException('Invalid JSON body.');
    }
    return $cache['data'];
}

/** Does the current request carry any array-valued GET/POST field (`name[]=x`)? */
function qt_request_has_array_input(): bool
{
    foreach ([$_GET, $_POST] as $source) {
        foreach ($source as $value) {
            if (is_array($value)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Does the source line that raised $e read the request directly (`$_GET`/`$_POST`/`$_REQUEST`)?
 * For a TypeError from a user function ("..., called in FILE on line N") the call site is
 * checked instead of the function's own declaration. Multi-line statements and values
 * passed through a variable do not match, which errs on the side of a 500.
 */
function qt_failing_line_reads_request(Throwable $e): bool
{
    static $files = [];
    $file = $e->getFile();
    $line = $e->getLine();
    if (preg_match('/, called in (.+) on line (\d+)$/', $e->getMessage(), $m) === 1) {
        [$file, $line] = [$m[1], (int)$m[2]];
    }
    if (!isset($files[$file])) {
        $files[$file] = is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }
    $text = $files[$file][$line - 1] ?? '';
    return preg_match('/\$_(GET|POST|REQUEST)\b/', $text) === 1;
}

/**
 * Is this throwable a malformed REQUEST (the input itself is wrong)? True for BadRequestException.
 * Business-rule client errors (NotFoundException, ConflictException, UnprocessableException)
 * are not input errors: a page catch that flashes a message for them keeps doing so. As a
 * transitional safety net for request reads not yet converted to the req_* helpers it is
 * also true for an "array given" TypeError when the request carries an array field AND the
 * failing source line itself reads `$_GET`/`$_POST`/`$_REQUEST`; a TypeError from a database
 * value, a decoded response or any other line stays a 500. JSON bodies legitimately contain
 * arrays, so JSON endpoints must use req_json()/req_*(). Remove the TypeError branch once
 * all request reads use the helpers (backlog 1.4/1.7).
 */
function qt_is_bad_input(Throwable $e): bool
{
    if ($e instanceof BadRequestException) {
        return true;
    }
    return $e instanceof TypeError
        && preg_match('/must be of type [^,]+, array given/', $e->getMessage()) === 1
        && qt_request_has_array_input()
        && qt_failing_line_reads_request($e);
}

/**
 * Should the shared boundary (global handler, router) answer this uncaught throwable with a 4xx
 * instead of a 500? True for every ClientError (it carries its own status and safe message) and
 * for malformed input (qt_is_bad_input).
 */
function qt_is_client_error(Throwable $e): bool
{
    return $e instanceof ClientError || qt_is_bad_input($e);
}

/**
 * For use as the first statement of a local `catch (Throwable|Exception|Error $e)` block:
 * if the throwable is the client's fault, rethrow it so the outer boundary answers 400 and
 * logs a warning instead of this catch logging it as a server error.
 */
function qt_rethrow_bad_input(Throwable $e): void
{
    if (qt_is_bad_input($e)) {
        throw $e;
    }
}

/**
 * Should an error for this request be JSON? True for /api/ paths (the path only, so a query
 * string such as `?next=/api/x` cannot change the format), AJAX requests and clients that
 * accept application/json.
 */
function qt_request_wants_json(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    return (is_string($path) && str_starts_with($path, '/api/'))
        || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/**
 * Answer a bad-input throwable with a 400 (JSON for API/AJAX requests, a small page
 * otherwise) and log it at warning level. Exits.
 */
function qt_respond_bad_request(Throwable $e): never
{
    // A ClientError carries its own status, safe message and details; an input TypeError is a 400.
    $status = $e instanceof ClientError ? $e->getHttpStatus() : 400;
    $details = $e instanceof ClientError ? $e->getDetails() : [];
    $message = $e instanceof ClientError ? $e->getMessage() : 'The request contained a field in an unexpected format.';

    qt_log_bad_request($e, $status, $details);
    qt_rollback_after_bad_request();

    if (!headers_sent()) {
        http_response_code($status);
    }
    if (qt_request_wants_json()) {
        qt_send_bad_request_json($message, $status, $details);
    } else {
        qt_send_bad_request_page($message, $status);
    }
    // arch-ok: exit-in-library - this is the response boundary: the answer has been sent
    exit;
}

/** @param array<string, mixed> $details */
function qt_log_bad_request(Throwable $e, int $status, array $details): void
{
    try {
        if (class_exists('Logger', false)) {
            Logger::warning('bad_request', $e->getMessage(), [
                'status' => $status,
                'details' => $details,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'type' => get_class($e),
                // Which fields were arrays, so a wrongly-classified internal bug can be told apart.
                'array_fields' => array_keys(array_filter($_GET + $_POST, 'is_array')),
            ]);
        }
    } catch (Throwable $logError) {
        // Logging must never turn a 400 into a 500; fall back to the PHP error log.
        error_log('bad_request logging failed: ' . $logError->getMessage());
    }
}

/** The aborted request may have been inside a transaction (local catches rethrow first). */
function qt_rollback_after_bad_request(): void
{
    try {
        if (function_exists('db_rollback_if_open')) {
            db_rollback_if_open();
        }
    } catch (Throwable $rollbackError) {
        error_log('bad_request rollback failed: ' . $rollbackError->getMessage());
    }
}

/** @param array<string, mixed> $details */
function qt_send_bad_request_json(string $message, int $status, array $details): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => $message, 'code' => $status, 'details' => $details]);
}

function qt_send_bad_request_page(string $message, int $status): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $headings = [403 => 'Forbidden', 404 => 'Not found', 409 => 'Conflict', 422 => 'Request could not be processed'];
    $heading = $headings[$status] ?? 'Bad request';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $status . ' - ' . $heading . '</title></head><body>'
        . '<h1>' . $heading . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/">Return to the homepage</a></p></body></html>';
}
