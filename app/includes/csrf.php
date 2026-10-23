<?php

declare(strict_types=1);

/** The media type controls which body supplies the API token. */
function api_request_is_json(): bool
{
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    return is_string($type) && strtolower(trim(explode(';', $type, 2)[0])) === 'application/json';
}

/** @param array<int|string, mixed>|null $body */
function api_csrf_sent_token(?array $body): mixed
{
    $input = api_request_is_json() ? ($body ?? req_json()) : $_POST;
    return array_key_exists('csrf', $input) ? $input['csrf'] : ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}

/**
 * Preserve form tokens; JSON accepts a body token or X-CSRF-Token when absent.
 * An explicitly supplied body token always wins, including an invalid one.
 *
 * @param array<int|string, mixed>|null $body Decoded JSON, when already available.
 */
function csrf_verify_api(?array $body = null): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    $sent = api_csrf_sent_token($body);
    $expected = $_SESSION['csrf'] ?? '';
    return is_string($sent) && is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
}
