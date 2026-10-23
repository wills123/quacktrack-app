<?php

declare(strict_types=1);

require_once __DIR__ . '/SnipeTokenManager.php';
require_once __DIR__ . '/snipe_response.php';

/**
 * The Snipe-IT user id for a Quack Track user. Normally stored when the person
 * signs in through Snipe-IT; if it isn't, find them in Snipe-IT by exact email
 * and remember it. Returns 0 when there is no unambiguous match, or when that
 * Snipe-IT account is already linked to a different Quack Track user.
 */
function resolve_snipe_user_id(PDO $pdo, int $qt_user_id): int
{
    if ($qt_user_id <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT snipe_user_id, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$qt_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    if ((int)($row['snipe_user_id'] ?? 0) > 0) {
        return (int)$row['snipe_user_id'];
    }
    $email = trim((string)($row['email'] ?? ''));
    if ($email === '') {
        return 0;
    }
    try {
        $found = SnipeItClient::getInstance()->listUsers(['search' => $email, 'limit' => 25]);
        $snipe_id = snipe_pick_user_id_by_email((array)($found['rows'] ?? []), $email);
        if ($snipe_id <= 0) {
            return 0;
        }
        $taken = $pdo->prepare('SELECT id FROM users WHERE snipe_user_id = ? AND id <> ? LIMIT 1');
        $taken->execute([$snipe_id, $qt_user_id]);
        if ($taken->fetchColumn()) {
            return 0;
        }
        $pdo->prepare('UPDATE users SET snipe_user_id = ? WHERE id = ? AND (snipe_user_id IS NULL OR snipe_user_id = 0)')
            ->execute([$snipe_id, $qt_user_id]);
        Logger::info('snipe_user_autolinked', 'Linked Quack Track user to Snipe-IT user by email', ['user_id' => $qt_user_id, 'snipe_user_id' => $snipe_id]);
        return $snipe_id;
    } catch (Throwable $e) {
        Logger::error('snipe_user_autolink_failed', $e, ['user_id' => $qt_user_id]);
        return 0;
    }
}

/**
 * Keep a received asset's Snipe-IT assignment aligned with its Quack Track destination.
 * Failure is reported to the caller but never rolls back the local receipt.
 */
function sync_received_asset_destination(PDO $pdo, int $asset_id, array $destination, int $actor_id, ?SnipeItClient $client = null): array
{
    $token = SnipeTokenManager::getValid($actor_id);
    if ($token === null) {
        return ['synced' => false, 'code' => 'reauth_required', 'message' => 'Snipe-IT account is not connected; local receipt was saved.'];
    }

    $checkout = null;
    if (($destination['destination'] ?? '') === 'van') {
        $snipe_user_id = resolve_snipe_user_id($pdo, (int)($destination['target_user_id'] ?? 0));
        if ($snipe_user_id <= 0) {
            return ['synced' => false, 'code' => 'missing_snipe_user', 'message' => 'Technician could not be matched to a Snipe-IT user by email; local receipt was saved. Check the asset out to them in Snipe-IT.'];
        }
        $checkout = ['checkout_to_type' => 'user', 'assigned_user' => $snipe_user_id];
    } else {
        $store_id = (int)($destination['store_id'] ?? 0);
        $region_id = (int)($destination['target_region_id'] ?? 0);
        $location_id = 0;
        if ($store_id > 0) {
            $stmt = $pdo->prepare('SELECT snipe_location_id FROM stores WHERE id = ? LIMIT 1');
            $stmt->execute([$store_id]);
            $location_id = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($location_id <= 0 && $region_id > 0) {
            $stmt = $pdo->prepare('SELECT snipe_location_id FROM snipe_it_location_mappings WHERE region_id = ? LIMIT 1');
            $stmt->execute([$region_id]);
            $location_id = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($location_id <= 0) {
            return ['synced' => false, 'code' => 'missing_snipe_location', 'message' => 'No Snipe-IT location is mapped for this destination; local receipt was saved.'];
        }
        $checkout = ['checkout_to_type' => 'location', 'assigned_location' => $location_id];
    }

    $checkout['note'] = trim((string)($destination['note'] ?? 'Received through Quack Track'));
    $write_client = $client ?? SnipeItClient::forToken($token, $actor_id);
    $result = $write_client->checkoutAsset($asset_id, $checkout);
    $success = is_array($result) && ($result['status'] ?? null) === 'success';
    if (!$success) {
        Logger::info('snipe_asset_destination_sync_failed', 'Snipe-IT destination sync failed after local receipt.', [
            'asset_id' => $asset_id,
            'actor_id' => $actor_id,
            'snipe_status' => is_string($result['status'] ?? null) ? $result['status'] : 'invalid response',
        ]);
        return ['synced' => false, 'code' => 'snipe_sync_failed', 'message' => 'Snipe-IT assignment failed; local receipt was saved.'];
    }

    return ['synced' => true, 'code' => 'synced', 'message' => 'Snipe-IT assignment updated.'];
}
