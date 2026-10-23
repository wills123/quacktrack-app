<?php

declare(strict_types=1);

require_once __DIR__ . '/snipe_api_errors.php';
require_once __DIR__ . '/input_schemas/kit_templates.php';
require_once __DIR__ . '/kit_templates_service.php';

/**
 * The actions of api/kit-templates.php (kit.view is checked by the endpoint). Order of checks: method (GET or HEAD for
 * a lookup, POST for a change; 405), kit.manage and CSRF for a change (403), Snipe-IT switched on for lookup_serial
 * (503), input (400 wrong type or missing, 422 rule), the kit, item or product (404), then Snipe-IT's answer (502 when
 * it does not answer usably). Answers keep the endpoint's flat {success, ...fields} shape.
 */

/** Answer success with $data beside the flag. @param array<string, mixed> $data */
function kit_templates_ok(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    // arch-ok: exit-in-library - this is the response boundary: the answer has been sent
    exit;
}

/**
 * Refuse a request whose method the action does not take, or that may not change kit items.
 *
 * @throws ClientErrorException|ForbiddenException
 */
function kit_templates_guard(string $action): void
{
    $write = in_array($action, KIT_TEMPLATES_WRITES, true);
    $allowed = $write ? ['POST'] : ['GET', 'HEAD'];
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ClientErrorException('Method not allowed', 405);
    }
    if (!$write) {
        return;
    }
    if (!has_permission('kit.manage')) {
        throw new ForbiddenException('Permission denied');
    }
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

/** Handle one action; every answer ends the request. */
function kit_templates_dispatch(string $action, int $userId): void
{
    try {
        kit_templates_guard($action);
        kit_templates_ok(kit_templates_answer($action, $userId));
    } catch (Throwable $e) {
        snipe_api_fail($e, 'kit_templates_provider_error', 'kit_templates_api', ['action' => $action, 'user_id' => $userId], 'An unexpected error occurred.');
    }
}

/**
 * Run the action and return the fields of its answer.
 *
 * @return array<string, mixed>
 */
function kit_templates_answer(string $action, int $userId): array
{
    if ($action === 'lookup_serial') {
        $client = snipe_catalog_client($userId);
        $in = kit_templates_input($action);
        return kit_templates_lookup($client, $in['serial'], $in['snipe_model_id']);
    }
    $in = kit_templates_input($action);
    $pdo = db();
    return match ($action) {
        'get_kit_snipe_items' => ['items' => kit_templates_snipe_items($pdo, $in['kit_template_id'])],
        'search_items' => kit_templates_search($pdo, kit_templates_search_client($userId), $in['q']),
        'add_item' => ['item' => kit_templates_add_item($pdo, $in)],
        'remove_item' => kit_templates_removed($pdo, $in['item_id']),
        'update_item_quantity' => kit_templates_resized($pdo, $in['item_id'], $in['quantity']),
        default => throw new UnexpectedValueException('Unknown kit template action ' . $action . '.'),
    };
}

/** The Snipe-IT client for the search, or null when the integration is off or not configured. */
function kit_templates_search_client(int $userId): ?SnipeItClient
{
    try {
        return snipe_catalog_client($userId);
    } catch (SnipeUnavailableException) {
        return null;
    }
}

/** @return array<string, never> */
function kit_templates_removed(PDO $pdo, int $itemId): array
{
    kit_templates_remove_item($pdo, $itemId);
    return [];
}

/** @return array{quantity: int} */
function kit_templates_resized(PDO $pdo, int $itemId, int $quantity): array
{
    kit_templates_update_quantity($pdo, $itemId, $quantity);
    return ['quantity' => $quantity];
}
