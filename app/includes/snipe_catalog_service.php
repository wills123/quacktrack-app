<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/SnipeItClient.php';
require_once __DIR__ . '/snipe_response.php';

/**
 * The catalog reads of api/snipe-it-assets.php: the asset list, the reference lists and the user picker. They use the
 * shared service-account client, so each answer is cut down to the fields the UI shows.
 */

/** Snipe-IT is switched off or not configured: nothing was sent to it. */
final class SnipeUnavailableException extends RuntimeException
{
}

/** Snipe-IT did not answer usably (no connection, a timeout, an error status or a body that is not JSON). */
final class SnipeProviderException extends RuntimeException
{
}

/**
 * The shared client, set to act for $userId.
 *
 * @throws SnipeUnavailableException
 */
function snipe_catalog_client(int $userId): SnipeItClient
{
    if (env('SNIPE_IT_ENABLED', '0') !== '1') {
        throw new SnipeUnavailableException('Snipe-IT integration is not enabled.');
    }
    try {
        $client = SnipeItClient::getInstance();
    } catch (RuntimeException $e) {
        Logger::error('snipe_not_configured', $e);
        throw new SnipeUnavailableException('Snipe-IT integration is not configured.', 0, $e);
    }
    $client->setCurrentUserId($userId);
    return $client;
}

/**
 * The scalar fields $keys of a Snipe-IT row; anything nested or missing is left out.
 *
 * @param array<mixed> $row
 * @param list<string> $keys
 * @return array<string, scalar|null>
 */
function snipe_catalog_row(array $row, array $keys): array
{
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && (is_scalar($row[$key]) || $row[$key] === null)) {
            $out[$key] = $row[$key];
        }
    }
    return $out;
}

/**
 * The rows of a Snipe-IT list answer that are records.
 *
 * @param array<string, mixed> $answer
 * @return list<array<mixed>>
 */
function snipe_catalog_rows(array $answer): array
{
    return array_values(array_filter(is_array($answer['rows'] ?? null) ? $answer['rows'] : [], 'is_array'));
}

/**
 * One page of assets with the paging flags.
 *
 * @param array<string, mixed> $in parsed list input
 * @return array{assets: list<array<string, mixed>>, total: int, pagination: array<string, int|bool>}
 * @throws SnipeProviderException
 */
function snipe_catalog_list(SnipeItClient $client, array $in): array
{
    $params = ['limit' => $in['limit'], 'offset' => $in['offset']];
    foreach (['search', 'status_id', 'category_id', 'model_id', 'location_id', 'manufacturer_id', 'sort', 'order'] as $key) {
        if (isset($in[$key])) {
            $params[$key] = $in[$key];
        }
    }
    $result = $client->listAssets($params);
    if ($result === null) {
        throw new SnipeProviderException('Failed to retrieve assets from Snipe IT.');
    }
    $total = (int)($result['total'] ?? 0);
    $limit = (int)$in['limit'];
    $offset = (int)$in['offset'];
    return [
        'assets' => array_map('snipe_public_asset', snipe_catalog_rows($result)),
        'total' => $total,
        'pagination' => [
            'limit' => $limit, 'offset' => $offset, 'total' => $total,
            'has_next' => ($offset + $limit) < $total, 'has_prev' => $offset > 0,
        ],
    ];
}

/**
 * The fields of each reference list the UI uses.
 *
 * @return list<string>
 */
function snipe_catalog_reference_fields(string $kind): array
{
    return match ($kind) {
        'models' => ['id', 'name', 'model_number'],
        'statuslabels' => ['id', 'name', 'type'],
        'categories' => ['id', 'name', 'category_type'],
        default => ['id', 'name'],
    };
}

/**
 * A whole reference list (cached by the client) cut down to its public fields.
 *
 * @return array{rows: list<array<string, mixed>>, total: int}
 * @throws SnipeProviderException
 */
function snipe_catalog_reference(SnipeItClient $client, string $kind): array
{
    $list = $client->referenceList($kind);
    if ($list === null) {
        throw new SnipeProviderException("Failed to retrieve {$kind} from Snipe IT.");
    }
    $fields = snipe_catalog_reference_fields($kind);
    return [
        'rows' => array_map(static fn(array $row): array => snipe_catalog_row($row, $fields), snipe_catalog_rows($list)),
        'total' => (int)($list['total'] ?? 0),
    ];
}

/**
 * Snipe-IT users for the checkout picker (at most 100, by name).
 *
 * @param array<string, mixed> $in
 * @return array{users: list<array<string, mixed>>, total: int}
 * @throws SnipeProviderException
 */
function snipe_catalog_users(SnipeItClient $client, array $in): array
{
    $params = ['limit' => 100, 'offset' => 0, 'sort' => 'name', 'order' => 'asc'];
    if (isset($in['search'])) {
        $params['search'] = $in['search'];
    }
    $result = $client->listUsers($params);
    if ($result === null) {
        throw new SnipeProviderException('Failed to retrieve users from Snipe IT.');
    }
    $users = array_map(static fn(array $u): array => [
        'id' => $u['id'] ?? null,
        'name' => trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? '')),
        'username' => (string)($u['username'] ?? ''),
        'email' => (string)($u['email'] ?? ''),
    ], snipe_catalog_rows($result));
    return ['users' => $users, 'total' => (int)($result['total'] ?? count($users))];
}
