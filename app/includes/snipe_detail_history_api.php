<?php

declare(strict_types=1);

require_once __DIR__ . '/snipe_api_errors.php';
require_once __DIR__ . '/input_schemas/snipe_detail_history.php';
require_once __DIR__ . '/snipe_detail_history_service.php';

/**
 * The asset detail, activity and statistics reads of api/snipe-it-assets.php. Order of checks: method (GET, 405),
 * permission (assets.view, 403), Snipe-IT switched on (503), input (400 wrong type, 422 rule), then Snipe-IT's answer:
 * a missing asset is 404, an answer it does not give usably is 502. Answers keep their {success, data, message} shape.
 */

/** Handle one read; every answer ends the request. */
function snipe_detail_history_dispatch(string $action, int $userId): void
{
    try {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            throw new ClientErrorException('Method not allowed', 405);
        }
        require_permission('assets.view');
        $client = snipe_catalog_client($userId);
        $in = snipe_detail_history_input($action);
        match ($action) {
            'get' => api_success(['asset' => snipe_detail_history_asset($client, $in['id'])], 'Asset retrieved successfully.'),
            'activity' => api_success(snipe_detail_history_activity($client, $in['id'], $in['limit'], $in['offset']), 'Activity retrieved successfully.'),
            default => api_success(snipe_detail_history_stats($client), 'Stats retrieved.'),
        };
    } catch (Throwable $e) {
        snipe_api_fail($e, 'snipe_detail_history_provider_error', 'snipe_it_assets_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}
