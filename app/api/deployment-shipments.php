<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/kit_shipment_service.php';
require_once __DIR__ . '/../includes/input_schemas/deployment_shipments.php';

require_login();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = strtolower(trim((string)(req_action('list'))));
$userId = (int)($_SESSION['uid'] ?? 0);

try {
    switch ($action) {
        case 'list':
            require_permission('deployment.view');
            deployment_shipment_api_list();
            break;
        case 'get':
            deployment_shipment_api_require_any(['deployment.view', 'kit.shipments.view']);
            deployment_shipment_api_get();
            break;
        case 'packing_list':
            deployment_shipment_api_require_any(['deployment.view', 'kit.shipments.view']);
            deployment_shipment_api_packing_list();
            break;
        case 'templates':
            require_permission('deployment.shipments.request');
            api_success(['templates' => deployment_shipment_templates()]);
            break;
        case 'request':
            require_permission('deployment.shipments.request');
            deployment_shipment_api_csrf();
            deployment_shipment_api_request($userId);
            break;
        case 'approve':
            require_permission('deployment.shipments.approve');
            deployment_shipment_api_csrf();
            deployment_shipment_api_approve($userId);
            break;
        case 'decline':
            require_permission('deployment.shipments.approve');
            deployment_shipment_api_csrf();
            deployment_shipment_api_decline($userId);
            break;
        case 'dispatch':
            require_permission('kit.shipments.create');
            deployment_shipment_api_csrf();
            deployment_shipment_api_dispatch($userId);
            break;
        case 'receive':
            deployment_shipment_api_require_any(['deployment.shipments.receive', 'kit.shipments.manage']);
            deployment_shipment_api_csrf();
            deployment_shipment_api_receive($userId);
            break;
        case 'cancel':
            deployment_shipment_api_require_any(['deployment.shipments.request', 'kit.shipments.manage']);
            deployment_shipment_api_csrf();
            deployment_shipment_api_cancel($userId);
            break;
        default:
            api_error('Unsupported action.', 400);
    }
} catch (Throwable $e) {
    // Typed client errors from the shipment service answer with their own 4xx (404/409/422);
    // anything else is logged and answers 500. Built-in exception classes are never client errors.
    api_fail($e, 'deployment_shipment_api_error', [
        'action' => $action,
        'user_id' => $userId,
    ], 'Unable to complete shipment request.');
}

function deployment_shipment_api_require_any(array $permissions): void
{
    foreach ($permissions as $permission) {
        if (has_permission($permission)) {
            return;
        }
    }
    api_error('You do not have permission to perform this action.', 403);
}

function deployment_shipment_api_csrf(): void
{
    if (!csrf_verify_api()) {
        api_error('Invalid or missing CSRF token. Please refresh and try again.', 419);
    }
}

function deployment_shipment_api_list(): void
{
    $in = deployment_shipment_input('list');
    $shipments = deployment_shipments_list($in['deployment_id'], [
        'status' => $in['status'] ?? '',
        'limit' => $in['limit'],
    ]);
    api_success(['shipments' => $shipments]);
}

function deployment_shipment_api_get(): void
{
    $shipment = deployment_shipment_get(deployment_shipment_input('get')['shipment_id']);
    if (!$shipment) {
        api_error('Shipment not found.', 404);
    }
    api_success(['shipment' => $shipment]);
}

/**
 * Full packing list: this shipment's line items plus live Acumatica on-hand
 * quantities and shortfall, so someone can see what's actually available
 * before the kit ships. Read-only; safe to call even if Acumatica isn't
 * configured (returns the plain items with enabled=false).
 */
function deployment_shipment_api_packing_list(): void
{
    api_success(deployment_shipment_packing_list(deployment_shipment_input('packing_list')['shipment_id']));
}

function deployment_shipment_api_request(int $userId): void
{
    $in = deployment_shipment_input('request');
    $shipment = deployment_shipment_request($in + ['quantities' => []], $userId);
    $message = $shipment['approval_status'] === 'pending'
        ? 'Shipment request submitted for approval.'
        : 'Shipment request sent directly to preparation.';
    api_success(['shipment' => $shipment], $message);
}

function deployment_shipment_api_approve(int $userId): void
{
    $in = deployment_shipment_input('approve');
    $shipment = deployment_shipment_approve($in['shipment_id'], $userId, $in['reason'] ?? '');
    api_success(['shipment' => $shipment], 'Shipment request approved.');
}

function deployment_shipment_api_decline(int $userId): void
{
    $in = deployment_shipment_input('decline');
    $shipment = deployment_shipment_decline($in['shipment_id'], $userId, $in['reason'] ?? '');
    api_success(['shipment' => $shipment], 'Shipment request declined.');
}

function deployment_shipment_api_dispatch(int $userId): void
{
    $in = deployment_shipment_input('dispatch');
    $shipment = deployment_shipment_dispatch(
        $in['shipment_id'],
        $userId,
        $in['serials'] ?? [],
        $in['carrier'] ?? '',
        $in['tracking_number'] ?? ''
    );
    api_success(['shipment' => $shipment], 'Shipment dispatched.');
}

function deployment_shipment_api_receive(int $userId): void
{
    $in = deployment_shipment_input('receive');
    $shipment = deployment_shipment_receive($in['shipment_id'], $userId, $in['received_quantities'] ?? [], $in['notes'] ?? '');
    api_success(['shipment' => $shipment], 'Shipment receipt recorded.');
}

function deployment_shipment_api_cancel(int $userId): void
{
    $in = deployment_shipment_input('cancel');
    $shipment = deployment_shipment_get($in['shipment_id']);
    if (!$shipment) {
        api_error('Shipment not found.', 404);
    }
    $canManage = has_permission('kit.shipments.manage') || has_permission('deployment.shipments.approve');
    if (!$canManage && (int)($shipment['requested_by'] ?? 0) !== $userId) {
        api_error('You may only cancel your own shipment requests.', 403);
    }
    $updated = deployment_shipment_cancel($in['shipment_id'], $userId, $in['reason'] ?? '');
    api_success(['shipment' => $updated], 'Shipment request cancelled.');
}
