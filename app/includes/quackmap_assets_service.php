<?php

/**
 * The store lookup and Snipe-IT read behind api/quackmap-assets.php. A store's Snipe-IT location is its own
 * snipe_location_id, else the location mapped to its region; a store with neither has no assets to show. Only reads
 * are sent to Snipe-IT, only the five public asset fields leave the server, and an answer Snipe-IT does not give
 * usably is a SnipeProviderException (502), never an empty list.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/**
 * The Snipe-IT location of a Quack Track store, or null when it has none.
 *
 * @throws NotFoundException when there is no such store
 */
function quackmap_assets_location(PDO $pdo, int $storeId): ?int
{
    $store = $pdo->prepare(
        'SELECT s.snipe_location_id, m.snipe_location_id AS region_location
           FROM stores s
           LEFT JOIN snipe_it_location_mappings m ON m.region_id = s.region_id
          WHERE s.id = ?
          ORDER BY m.id
          LIMIT 1'
    );
    $store->execute([$storeId]);
    $row = $store->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new NotFoundException('Store not found.');
    }
    $location = $row['snipe_location_id'] ?? $row['region_location'];
    return $location === null ? null : (int)$location;
}

/**
 * One page of the assets Snipe-IT holds at $location, projected, and how many there are in all.
 *
 * @return array{assets: list<array{asset_tag: ?string, name: ?string, model: ?string, status: ?string, serial: ?string}>, total: int}
 * @throws SnipeProviderException
 */
function quackmap_assets_page(SnipeItClient $client, int $location, int $limit, int $offset): array
{
    $answer = $client->listAssets(['location_id' => $location, 'limit' => $limit, 'offset' => $offset]);
    if ($answer === null || !is_int($answer['total'] ?? null)) {
        throw new SnipeProviderException('Snipe-IT did not return the assets of location ' . $location . '.');
    }
    return ['assets' => array_map('quackmap_assets_public', snipe_catalog_rows($answer)), 'total' => $answer['total']];
}

/**
 * The fields Quack-Map may see; anything that is not text or a number is null.
 *
 * @param array<mixed> $asset
 * @return array{asset_tag: ?string, name: ?string, model: ?string, status: ?string, serial: ?string}
 */
function quackmap_assets_public(array $asset): array
{
    $text = static fn(mixed $value): ?string => is_string($value) || is_int($value) || is_float($value) ? (string)$value : null;
    return [
        'asset_tag' => $text($asset['asset_tag'] ?? null),
        'name' => $text($asset['name'] ?? null),
        'model' => $text(is_array($asset['model'] ?? null) ? ($asset['model']['name'] ?? null) : null),
        'status' => $text(is_array($asset['status_label'] ?? null) ? ($asset['status_label']['name'] ?? null) : null),
        'serial' => $text($asset['serial'] ?? null),
    ];
}
