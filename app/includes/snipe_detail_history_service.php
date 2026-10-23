<?php

/**
 * Asset detail, activity history and asset counts for api/snipe-it-assets.php, read with the shared service-account
 * client. Only reads are sent to Snipe-IT, and only the public projections of includes/snipe_response.php leave the
 * server. An answer Snipe-IT does not give, or gives in an unusable shape, is a SnipeProviderException (502), never an
 * empty result.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/snipe_response.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/**
 * One asset, projected. Snipe-IT reports a missing asset either as a 404 or as a 200 whose body says
 * {"status": "error"}; both are a 404 here. Anything else that is not the asked-for asset is a provider failure.
 *
 * @return array<string, mixed>
 * @throws NotFoundException|SnipeProviderException
 */
function snipe_detail_history_asset(SnipeItClient $client, int $id): array
{
    $result = $client->request('GET', '/hardware/' . $id);
    if (snipe_detail_history_says_missing($result)) {
        throw new NotFoundException('Asset not found.');
    }
    $data = $result['data'];
    if ($result['error'] !== null || !is_array($data) || ($data['id'] ?? null) !== $id) {
        throw new SnipeProviderException('Snipe-IT did not return asset ' . $id . ': ' . ($result['error'] ?? 'unusable answer'));
    }
    return snipe_public_asset($data);
}

/**
 * Whether Snipe-IT's answer says the asset does not exist: a 404, or a successful reply whose body has an error status.
 *
 * @param array<string, mixed> $result SnipeItClient::request() result: status, data, error
 */
function snipe_detail_history_says_missing(array $result): bool
{
    if (($result['status'] ?? null) === 404) {
        return true;
    }
    return ($result['error'] ?? null) === null && is_array($result['data'] ?? null) && ($result['data']['status'] ?? null) === 'error';
}

/**
 * A page of the asset's activity (newest first, as Snipe-IT orders it) and the total, projected.
 *
 * @return array{activity: list<array<string, mixed>>, total: int}
 * @throws SnipeProviderException
 */
function snipe_detail_history_activity(SnipeItClient $client, int $id, int $limit, int $offset): array
{
    $answer = $client->listActivities(['item_id' => $id, 'item_type' => 'asset', 'limit' => $limit, 'offset' => $offset]);
    $rows = snipe_detail_history_rows($answer, 'activity of asset ' . $id);
    return ['activity' => array_map('snipe_public_activity', $rows), 'total' => $answer['total']];
}

/**
 * The record rows of a list answer, which must have a list of objects in `rows` and a whole `total`.
 *
 * @param array<string, mixed>|null $answer
 * @return list<array<string, mixed>>
 * @throws SnipeProviderException
 */
function snipe_detail_history_rows(?array $answer, string $what): array
{
    $rows = $answer['rows'] ?? null;
    $isRecord = static fn(mixed $row): bool => is_array($row) && !array_is_list($row);
    $usable = is_array($rows) && array_is_list($rows) && count(array_filter($rows, $isRecord)) === count($rows);
    if (!$usable || !is_int($answer['total'] ?? null) || $answer['total'] < 0) {
        throw new SnipeProviderException('Snipe-IT did not return the ' . $what . '.');
    }
    return $rows;
}

/**
 * Total, deployed and available asset counts. They are the same for every viewer, so they are cached for two minutes
 * (and dropped by the client after any write); a failed read is never cached.
 *
 * @return array{total: int, deployed: int, available: int}
 * @throws SnipeProviderException
 */
function snipe_detail_history_stats(SnipeItClient $client): array
{
    $stats = $client->cachedGet(SnipeItClient::STATS_CACHE_KEY, static fn(): ?array => snipe_detail_history_count_assets($client), 120);
    if ($stats === null || !is_int($stats['total'] ?? null) || !is_int($stats['deployed'] ?? null) || !is_int($stats['available'] ?? null)) {
        throw new SnipeProviderException('Snipe-IT did not return the asset counts.');
    }
    return ['total' => $stats['total'], 'deployed' => $stats['deployed'], 'available' => $stats['available']];
}

/**
 * Count the assets: the whole list, the deployed ones (assigned) and the ready-to-deploy ones, each with Snipe-IT's own
 * hardware status filter and limit=1. A status label's type only says whether assets with it may be deployed, not
 * whether they are, so labels are not used. Null when any read fails.
 *
 * @return array{total: int, deployed: int, available: int}|null
 */
function snipe_detail_history_count_assets(SnipeItClient $client): ?array
{
    $count = static fn(array $filter): int => snipe_detail_history_total($client->listAssets(['limit' => 1, 'offset' => 0] + $filter));
    try {
        return ['total' => $count([]), 'deployed' => $count(['status' => 'Deployed']), 'available' => $count(['status' => 'RTD'])];
    } catch (SnipeProviderException $e) {
        Logger::error('snipe_stats_read_failed', $e);
        return null;
    }
}

/**
 * The `total` of a list answer.
 *
 * @param array<string, mixed>|null $answer
 * @throws SnipeProviderException
 */
function snipe_detail_history_total(?array $answer): int
{
    $total = $answer['total'] ?? null;
    if (!is_int($total) || $total < 0) {
        throw new SnipeProviderException('Snipe-IT did not return an asset count.');
    }
    return $total;
}
