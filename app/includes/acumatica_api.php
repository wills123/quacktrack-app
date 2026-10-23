<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/acumatica.php';
require_once __DIR__ . '/acumatica_service.php';

/**
 * api/acumatica.php. Order of checks: method (405), permission (403), CSRF on create_po (403), Acumatica switched on
 * (503), input (400 wrong type, 422 rule), then Acumatica's answer: not found (404), a business-rule refusal of a
 * purchase order (422), anything else it does wrong or does not answer (502). Answers keep their top-level shape.
 */

/** The permission an action needs. */
function acumatica_permission(string $action): string
{
    return in_array($action, ACUMATICA_READ_ACTIONS, true) ? 'acumatica.inventory.view' : 'acumatica.po.create';
}

/** Handle one action; every answer ends the request. */
function acumatica_dispatch(string $action, int $userId): void
{
    try {
        acumatica_guard($action);
        echo json_encode(['success' => true] + acumatica_answer($action, $userId));
    } catch (AcumaticaUnavailableException $e) {
        api_error($e->getMessage(), 503);
    } catch (AcumaticaProviderException $e) {
        acumatica_provider_failure($e, $action, $userId);
    } catch (Throwable $e) {
        api_fail($e, 'acumatica_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}

/**
 * Method, permission and CSRF.
 *
 * @throws ClientErrorException
 */
function acumatica_guard(string $action): void
{
    $reads = in_array($action, ACUMATICA_READ_ACTIONS, true);
    $allowed = $reads ? ['GET', 'HEAD'] : ['POST'];
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ClientErrorException('Method not allowed', 405);
    }
    require_permission(acumatica_permission($action));
}

/**
 * Run the action and return the fields of its answer.
 *
 * @return array<string, mixed>
 */
function acumatica_answer(string $action, int $userId): array
{
    $body = [];
    if (!in_array($action, ACUMATICA_READ_ACTIONS, true)) {
        $body = req_json(true);
        if (!csrf_verify_api($body)) {
            throw new ForbiddenException('Invalid or missing CSRF token. Please refresh and try again.');
        }
    }
    $client = acumatica_client($userId);
    $in = acumatica_input($action, $body);
    return match ($action) {
        'stock_levels' => acumatica_stock_levels($client, $in['inventory_ids']),
        'stock_item' => ['item' => acumatica_found($client->getStockItem($in['inventory_id']), 'Stock item')],
        'shipment_tracking' => ['shipment' => acumatica_found($client->getShipment($in['shipment_nbr']), 'Shipment')],
        'po_status' => ['order' => acumatica_found($client->getPurchaseOrder($in['order_nbr']), 'Purchase order')],
        default => acumatica_po_answer($client, $userId, $in),
    };
}

/**
 * Create the purchase order and describe it.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function acumatica_po_answer(AcumaticaClient $client, int $userId, array $in): array
{
    $created = acumatica_create_po($client, db(), $userId, $in);
    $nbr = $created['order_nbr'];
    $message = "Purchase order {$nbr} created in Acumatica on Hold -- it will not be sent to the vendor until someone reviews and releases it there.";
    if (!$created['recorded']) {
        $message .= ' It could not be saved to the Quack Track history; do not submit the request again.';
    }
    return ['order_nbr' => $nbr, 'status' => $created['status'], 'recorded' => $created['recorded'], 'message' => $message];
}

/** A failure on Acumatica's side: a refusal of the purchase order is the caller's to fix, anything else is a 502. */
function acumatica_provider_failure(AcumaticaProviderException $e, string $action, int $userId): void
{
    $context = ['action' => $action, 'user_id' => $userId, 'provider_status' => $e->getProviderStatus()];
    if ($action === 'create_po' && $e->isRefusal()) {
        Logger::warning('acumatica_po_refused', $e->getMessage(), $context);
        api_error('Acumatica refused the purchase order: ' . acumatica_refusal_text($e->getMessage()), 422);
    }
    Logger::error('acumatica_provider_error', $e, $context);
    api_error('Acumatica did not answer correctly. Try again later.', 502);
}

/** The reason Acumatica gave, without the client's own prefix and cut to a readable length. */
function acumatica_refusal_text(string $message): string
{
    $reason = trim((string)preg_replace('/^Acumatica createPurchaseOrder failed:\s*/', '', $message));
    return mb_substr($reason, 0, 300);
}
