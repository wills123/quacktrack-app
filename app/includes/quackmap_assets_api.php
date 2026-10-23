<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/quackmap_store_service.php';
require_once __DIR__ . '/input_schemas/quackmap_assets.php';
require_once __DIR__ . '/quackmap_assets_service.php';

/**
 * api/quackmap-assets.php: stateless, X-Api-Key only, with the key rules of the store directory
 * (includes/quackmap_store_service.php). Order of checks: method (405; the key is not used), key (401 for every kind
 * of refusal), usage bookkeeping, query (400 malformed, 422 out of range), store (404), its Snipe-IT location (none:
 * 200 with mapped=false, no Snipe-IT call), Snipe-IT switched on (503), then Snipe-IT's answer (502 when it does not
 * answer usably, which is never reported as a key failure). Database faults are a 500 without SQL text.
 */

/** Answer the request; every answer ends it. */
function quackmap_assets_dispatch(): void
{
    $keyId = null;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            throw new ClientErrorException('Only GET requests are allowed.', 405);
        }
        $pdo = db();
        $keyId = quackmap_stores_authenticate($pdo, (string)($_SERVER['HTTP_X_API_KEY'] ?? ''), time());
        if ($keyId === null) {
            throw new ClientErrorException('Unauthorized.', 401);
        }
        quackmap_stores_record_use($pdo, $keyId, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        echo json_encode(quackmap_assets_answer($pdo, quackmap_assets_input()));
    } catch (SnipeUnavailableException $e) {
        api_error($e->getMessage(), 503);
    } catch (SnipeProviderException $e) {
        Logger::error('quackmap_assets_provider_error', $e, ['key_id' => $keyId]);
        api_error('Snipe-IT did not answer correctly. Try again later.', 502);
    } catch (ClientError $e) {
        api_fail($e, 'quackmap_assets_api_error', ['key_id' => $keyId]);
    } catch (Throwable $e) {
        // Quack-Map reads this answer: it never gets the database's message, in any environment.
        $errorId = uniqid('ERR-', true);
        Logger::error('quackmap_assets_api_error', $e, ['key_id' => $keyId, 'error_id' => $errorId]);
        api_error('Internal server error.', 500, ['error_id' => $errorId]);
    }
}

/**
 * The answer for an authenticated request: no location is mapped=false (Snipe-IT is not asked), otherwise a page of the
 * location's assets.
 *
 * @param array{store_id: int, limit: int, offset: int} $in
 * @return array<string, mixed>
 */
function quackmap_assets_answer(PDO $pdo, array $in): array
{
    $location = quackmap_assets_location($pdo, $in['store_id']);
    if ($location === null) {
        return ['success' => true, 'mapped' => false, 'assets' => [], 'total' => 0];
    }
    return ['success' => true, 'mapped' => true] + quackmap_assets_page(snipe_catalog_client(0), $location, $in['limit'], $in['offset']);
}
