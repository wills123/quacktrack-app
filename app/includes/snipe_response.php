<?php

declare(strict_types=1);

/**
 * Helpers for reading Snipe-IT API write responses.
 *
 * Snipe-IT reports a refused write (validation failure) with HTTP 200 and
 * {"status":"error","messages":{field:[...]}}, and wraps a created or updated
 * record as {"status":"success","messages":"...","payload":{...record...}}.
 * Code that only looks at the HTTP status, or reads `id` from the top level,
 * mistakes failures for success and successes for failures.
 */

/**
 * Flatten Snipe-IT's "messages" (a string, or field => [messages]) into one readable line.
 *
 * @param mixed $messages
 */
function snipe_messages_to_string($messages): string
{
    if (is_string($messages)) {
        return trim($messages);
    }
    if (!is_array($messages)) {
        return '';
    }
    $parts = [];
    array_walk_recursive($messages, static function ($value) use (&$parts): void {
        if (is_string($value) && trim($value) !== '') {
            $parts[] = trim($value);
        }
    });
    return implode(' ', array_unique($parts));
}

/**
 * Interpret the result of SnipeItClient::request() for a create/update call.
 *
 * @param array<string, mixed> $result The array returned by SnipeItClient::request()
 * @return array{ok: bool, record: array<string, mixed>, error: string}
 */
function snipe_parse_write_result(array $result): array
{
    $body = is_array($result['data'] ?? null) ? $result['data'] : [];
    $transportError = $result['error'] ?? null;
    if ($transportError !== null || strtolower((string)($body['status'] ?? '')) === 'error') {
        $detail = snipe_messages_to_string($body['messages'] ?? $body['message'] ?? $transportError ?? '');
        return ['ok' => false, 'record' => [], 'error' => $detail];
    }
    $record = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
    return ['ok' => true, 'record' => $record, 'error' => ''];
}

/**
 * Pick the Snipe-IT user whose email exactly matches (case-insensitive), from a
 * /users search result's rows. Returns the Snipe-IT user id, or 0 if there is no
 * single exact match (a search is fuzzy, so never trust the first row).
 *
 * @param array<int, mixed> $rows
 */
function snipe_pick_user_id_by_email(array $rows, string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return 0;
    }
    $matches = [];
    foreach ($rows as $row) {
        if (is_array($row) && strtolower(trim((string)($row['email'] ?? ''))) === $email && (int)($row['id'] ?? 0) > 0) {
            $matches[(int)$row['id']] = true;
        }
    }
    return count($matches) === 1 ? (int)array_key_first($matches) : 0;
}

/**
 * Reduce a Snipe-IT hardware row to the fields Quack Track's UI uses before it is
 * sent to the browser. Snipe-IT rows carry much more (creator, internal URLs,
 * per-user capability flags, company, cost centres); none of it should leave the server.
 *
 * @param array<string, mixed> $asset
 * @return array<string, mixed>
 */
function snipe_public_asset(array $asset): array
{
    $scalar = [
        'id', 'name', 'asset_tag', 'serial', 'model_number', 'notes', 'order_number',
        'purchase_date', 'purchase_cost', 'warranty_months', 'expected_checkin',
        'last_checkout', 'custom_fields', 'created_at', 'updated_at',
    ];
    $out = array_intersect_key($asset, array_flip($scalar));

    $refs = [
        'model' => ['id', 'name'],
        'category' => ['id', 'name'],
        'manufacturer' => ['id', 'name'],
        'supplier' => ['id', 'name'],
        'location' => ['id', 'name'],
        'status_label' => ['id', 'name', 'status_type', 'status_meta'],
        'assigned_to' => ['id', 'name', 'username', 'first_name', 'last_name', 'type'],
    ];
    foreach ($refs as $field => $keys) {
        if (isset($asset[$field]) && is_array($asset[$field])) {
            $out[$field] = array_intersect_key($asset[$field], array_flip($keys));
        } elseif (array_key_exists($field, $asset)) {
            $out[$field] = null;
        }
    }
    return $out;
}

/**
 * Same idea for an activity-log row: the UI shows who did what and when.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function snipe_public_activity(array $entry): array
{
    $out = array_intersect_key($entry, array_flip(['id', 'action_type', 'created_at']));
    foreach (['user', 'admin', 'target'] as $field) {
        if (isset($entry[$field]) && is_array($entry[$field])) {
            $out[$field] = array_intersect_key($entry[$field], array_flip(['id', 'name']));
        }
    }
    return $out;
}

/**
 * Read a Snipe-IT /users/me response into the profile and permission set Quack Track
 * keeps. Returns null when the response has no usable user id.
 *
 * @param array<string, mixed> $response Decoded JSON (the user may be wrapped in "data")
 * @return array{id: int, name: string, email: string, first_name: string, last_name: string,
 *               department: string, company: string, location_id: int, permissions: array<string, mixed>}|null
 */
function snipe_parse_current_user(array $response): ?array
{
    $user = is_array($response['data'] ?? null) ? $response['data'] : $response;
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $permissions = $user['permissions'] ?? [];

    return [
        'id'          => $id,
        'name'        => (string)($user['name'] ?? $user['full_name'] ?? ''),
        'email'       => trim((string)($user['email'] ?? '')),
        'first_name'  => (string)($user['first_name'] ?? ''),
        'last_name'   => (string)($user['last_name'] ?? ''),
        'department'  => (string)($user['department']['name'] ?? 'Field Techs'),
        'company'     => (string)($user['company']['name'] ?? ''),
        'location_id' => (int)($user['location']['id'] ?? 0),
        'permissions' => is_array($permissions) ? $permissions : [],
    ];
}
