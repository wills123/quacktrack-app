<?php

declare(strict_types=1);

require_once __DIR__ . '/snipe_api_errors.php';
require_once __DIR__ . '/input_schemas/snipe_catalog.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/**
 * The catalog actions of api/snipe-it-assets.php. Order of checks: method (GET, 405), permission (assets.view, or
 * assets.checkout for the user picker; 403), Snipe-IT switched on (503), input (400 wrong type, 422 rule), then
 * Snipe-IT's answer (502 when it does not answer usably). Answers keep their {success, data, message} shape.
 */

/** The actions this file answers. */
const SNIPE_CATALOG_ACTIONS = ['list', 'categories', 'models', 'locations', 'statuses', 'manufacturers', 'suppliers', 'users'];

/** Handle one catalog action; every answer ends the request. */
function snipe_catalog_dispatch(string $action, int $userId): void
{
    try {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            throw new ClientErrorException('Method not allowed', 405);
        }
        require_permission($action === 'users' ? 'assets.checkout' : 'assets.view');
        $client = snipe_catalog_client($userId);
        [$data, $message] = snipe_catalog_answer($action, $client, snipe_catalog_input($action));
        api_success($data, $message);
    } catch (Throwable $e) {
        snipe_api_fail($e, 'snipe_catalog_provider_error', 'snipe_it_assets_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}

/**
 * Run the action and return its data and message.
 *
 * @param array<string, mixed> $in
 * @return array{0: array<string, mixed>, 1: string}
 */
function snipe_catalog_answer(string $action, SnipeItClient $client, array $in): array
{
    if ($action === 'list') {
        return [snipe_catalog_list($client, $in), 'Assets retrieved successfully.'];
    }
    if ($action === 'users') {
        return [snipe_catalog_users($client, $in), 'Users retrieved successfully.'];
    }
    $kind = SNIPE_CATALOG_REFERENCES[$action];
    $list = snipe_catalog_reference($client, $kind);
    $label = $action === 'statuses' ? 'Status labels' : ucfirst($action);
    return [[$action => $list['rows'], 'total' => $list['total']], $label . ' retrieved successfully.'];
}
