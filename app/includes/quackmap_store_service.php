<?php

/**
 * Key checks and the directory read behind api/quackmap-stores.php.
 *
 * A key is accepted only when it exists, is active (not 0), has not expired and carries the exact scope in its
 * comma-separated list (entries are trimmed, nothing else is normalised). Every way of failing that is
 * one answer to the caller, so a response never says which part was wrong.
 */

declare(strict_types=1);

const QUACKMAP_STORES_SCOPE = 'quackmap:read';

/**
 * The stored record of the key with this secret, or null.
 *
 * @return array{id: int|string, scopes: string|null, is_active: int|string, expires_at: string|null}|null
 */
function quackmap_stores_find_key(PDO $pdo, string $apiKey): ?array
{
    $stmt = $pdo->prepare('SELECT id, scopes, is_active, expires_at FROM api_keys WHERE key_hash = ?');
    $stmt->execute([hash('sha256', $apiKey)]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    return $record === false ? null : $record;
}

/** Whether the scope list holds exactly $scope as one of its comma-separated, trimmed entries. */
function quackmap_stores_has_scope(?string $scopes, string $scope): bool
{
    $entries = array_values(array_filter(array_map('trim', explode(',', (string)$scopes))));
    return in_array($scope, $entries, true);
}

/**
 * Whether the record may use the directory at $now: active, unexpired (no expiry means none; an
 * unreadable expiry counts as expired) and scoped to quackmap:read.
 *
 * @param array<string, mixed> $record
 */
function quackmap_stores_key_allows(array $record, int $now): bool
{
    if ((int)$record['is_active'] === 0) {
        return false;
    }
    if ($record['expires_at'] !== null && (strtotime((string)$record['expires_at']) ?: 0) < $now) {
        return false;
    }
    return quackmap_stores_has_scope($record['scopes'] === null ? null : (string)$record['scopes'], QUACKMAP_STORES_SCOPE);
}

/**
 * The id of the key that may read the directory, or null for every kind of refusal.
 */
function quackmap_stores_authenticate(PDO $pdo, string $apiKey, int $now): ?int
{
    if ($apiKey === '') {
        return null;
    }
    $record = quackmap_stores_find_key($pdo, $apiKey);
    return $record !== null && quackmap_stores_key_allows($record, $now) ? (int)$record['id'] : null;
}

/** Count one authenticated request against its key: the request count, last-used time and address. Nothing else changes. */
function quackmap_stores_record_use(PDO $pdo, int $keyId, string $address): void
{
    $stmt = $pdo->prepare(
        'UPDATE api_keys SET last_used_at = NOW(), last_used_ip = ?, request_count = request_count + 1 WHERE id = ?'
    );
    $stmt->execute([$address, $keyId]);
}

/**
 * Every store that has a store number, as stored (leading zeros and empty strings are kept).
 *
 * @return list<array{id: int, store_number: string|null}>
 */
function quackmap_stores_directory(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id, store_number FROM stores WHERE store_number IS NOT NULL');
    $stmt->execute();
    return array_map(static fn(array $store): array => [
        'id' => (int)$store['id'],
        'store_number' => $store['store_number'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}
