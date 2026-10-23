<?php

declare(strict_types=1);

require_once __DIR__ . '/snipe_api_errors.php';
require_once __DIR__ . '/input_schemas/snipe_writes.php';
require_once __DIR__ . '/snipe_writes_service.php';

/**
 * The write actions of api/snipe-it-assets.php. Order of checks: method (POST, 405), permission (assets.checkout,
 * assets.checkin or assets.manage; 403), CSRF (403), Snipe-IT switched on (503), input (400 wrong type or missing, 422
 * rule), the caller's personal token (401 with snipe_auth_required), then Snipe-IT's answer: its refusal (403, 404, 409,
 * 422 or 429 with its message) or a failure (502). Answers keep their {success, data, message} shape.
 */

/** The permission each write needs. */
const SNIPE_WRITES_PERMISSIONS = ['checkout' => 'assets.checkout', 'checkin' => 'assets.checkin', 'update' => 'assets.manage'];

/** Handle one write; every answer ends the request. */
function snipe_writes_dispatch(string $action, int $userId): void
{
    try {
        snipe_writes_guard($action);
        snipe_catalog_client($userId);
        $in = snipe_writes_input($action);
        $writer = snipe_writes_client($userId);
        [$data, $message] = match ($action) {
            'checkout' => [snipe_writes_checkout($writer, $in), 'Asset checked out successfully.'],
            'checkin' => [snipe_writes_checkin($writer, $in), 'Asset checked in successfully.'],
            default => [snipe_writes_update($writer, $in), 'Asset updated successfully.'],
        };
        api_success($data, $message);
    } catch (SnipeReconnectException $e) {
        snipe_writes_reconnect($e->getMessage());
    } catch (Throwable $e) {
        snipe_api_fail($e, 'snipe_write_provider_error', 'snipe_it_assets_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}

/**
 * Refuse a request that is not a POST, lacks the action's permission or its CSRF token.
 *
 * @throws ClientErrorException|ForbiddenException
 */
function snipe_writes_guard(string $action): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        throw new ClientErrorException('Method not allowed', 405);
    }
    if (!has_permission(SNIPE_WRITES_PERMISSIONS[$action])) {
        throw new ForbiddenException('Access denied. Insufficient permissions.');
    }
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

/** Ask the person to reconnect Snipe-IT: the assets page looks for snipe_auth_required at the top of the answer. */
function snipe_writes_reconnect(string $message): never
{
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $message, 'code' => 401, 'details' => ['snipe_auth_required' => true], 'snipe_auth_required' => true]);
    // arch-ok: exit-in-library - this is the response boundary: the answer has been sent
    exit;
}
