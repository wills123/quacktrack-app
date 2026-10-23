<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/SnipeTokenManager.php';
require_once __DIR__ . '/input_schemas/tracked_assets.php';
require_once __DIR__ . '/tracked_assets_service.php';

/**
 * api/tracked-assets.php `dispose`. Order of checks: method (POST, 405), assets.manage (403), CSRF (403), input (400
 * wrong type, 422 rule), Snipe-IT switched on (503), then under the receipt's lock: the receipt (404 missing, 409
 * already resolved), the person's Snipe-IT link (401 with snipe_auth_required), Snipe-IT (502 when it refuses or fails;
 * nothing local is changed), the local record (500 when it cannot be saved after Snipe-IT was changed; repeating the
 * request finishes it).
 */

/** Handle `dispose`; every answer ends the request. */
function tracked_assets_dispatch(int $actorId): void
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            throw new ClientErrorException('Method not allowed', 405);
        }
        require_permission('assets.manage');
        if (!csrf_verify_api()) {
            throw new ForbiddenException('Invalid CSRF token');
        }
        $in = tracked_assets_input();
        $service = snipe_catalog_client($actorId);
        $pdo = db();
        $statusSet = tracked_assets_locked($pdo, $in['receipt_id'], static fn(): bool => tracked_assets_dispose($pdo, $service, $in, $actorId));
        echo json_encode([
            'success' => true,
            'message' => 'Asset marked ' . $in['disposition'] . ' and updated in Snipe-IT.',
            'status_updated' => $statusSet,
        ]);
    } catch (SnipeUnavailableException $e) {
        api_error($e->getMessage(), 503);
    } catch (TrackedAssetProviderException $e) {
        Logger::error('tracked_asset_provider_error', $e, ['user_id' => $actorId]);
        api_error($e->getMessage(), 502);
    } catch (TrackedAssetUnsavedException $e) {
        // Logged for reconciliation where it happened.
        api_error($e->getMessage(), 500);
    } catch (Throwable $e) {
        api_fail($e, 'tracked_asset_disposition_api', ['user_id' => $actorId], 'Unable to update tracked asset disposition.');
    }
}

/**
 * The disposal itself, run under the receipt's lock. Returns whether the Snipe-IT status was set.
 *
 * @param array{receipt_id: int, disposition: string, note: string} $in
 * @throws ClientErrorException|TrackedAssetProviderException
 */
function tracked_assets_dispose(PDO $pdo, SnipeItClient $service, array $in, int $actorId): bool
{
    $assetId = tracked_assets_active_asset($pdo, $in['receipt_id']);
    $token = SnipeTokenManager::getValid($actorId);
    if ($token === null) {
        throw new ClientErrorException('Reconnect your Snipe-IT account before changing an asset disposition.', 401, ['snipe_auth_required' => true]);
    }
    $note = $in['note'] !== '' ? $in['note'] : ucfirst($in['disposition']) . ' through Quack Track';
    $statusSet = tracked_assets_update_snipe($service, SnipeItClient::forToken($token, $actorId), $assetId, TRACKED_ASSETS_DISPOSITIONS[$in['disposition']], $note);
    tracked_assets_record($pdo, $in, $actorId, $assetId);
    return $statusSet;
}
