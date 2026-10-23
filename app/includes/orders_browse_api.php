<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/orders_browse.php';
require_once __DIR__ . '/orders_browse_service.php';

/**
 * The order browsing actions of api/orders.php: list, get, reorder and cancel. Order of checks: method (405),
 * CSRF on changes (403), input (400 wrong type, 422 rule), then rows (404, 409). Any signed-in user may call
 * them; what they see or change is decided by ownership and the orders.* permissions. Answers keep their
 * original top-level shape ({"success": true, ...fields}).
 */

/** Handle one of the four actions; every answer ends the request. */
function orders_browse_dispatch(string $action, PDO $pdo, int $userId): void
{
    try {
        $reads = in_array($action, ORDERS_BROWSE_READ_ACTIONS, true);
        $allowed = $reads ? ['GET', 'HEAD'] : ['POST'];
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ClientErrorException('Method not allowed', 405);
        }
        if (!$reads && !csrf_verify_api()) {
            throw new ForbiddenException('Invalid CSRF token');
        }
        $viewer = new OrdersViewer(
            $userId,
            (int)($_SESSION['region_id'] ?? 0),
            has_permission('orders.view_all'),
            has_permission('orders.view_region'),
            has_permission('orders.manage')
        );
        echo json_encode(['success' => true] + orders_browse_answer($action, $pdo, $viewer, orders_browse_input($action)));
    } catch (Throwable $e) {
        api_fail($e, 'orders_api_error', ['action' => $action, 'user_id' => $userId], 'An error occurred processing your request.');
    }
}

/**
 * Run the action and return the fields of its answer.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function orders_browse_answer(string $action, PDO $pdo, OrdersViewer $viewer, array $in): array
{
    return match ($action) {
        'list' => orders_browse_list($pdo, $viewer, $in),
        'get' => orders_browse_get($pdo, $viewer, $in['order_id']),
        'reorder' => [
            'message' => 'Items added to cart from previous order',
            'items_added' => orders_browse_reorder($pdo, $viewer->userId, $in['order_id']),
        ],
        default => orders_browse_cancelled($pdo, $viewer, $in['order_id']),
    };
}

/**
 * Cancel an order and describe it.
 *
 * @return array<string, mixed>
 */
function orders_browse_cancelled(PDO $pdo, OrdersViewer $viewer, int $orderId): array
{
    orders_browse_cancel($pdo, $viewer, $orderId);
    return ['message' => 'Order cancelled successfully', 'order_id' => $orderId];
}
