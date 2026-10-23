<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/transactions.php';
require_once __DIR__ . '/transactions_service.php';

/**
 * api/transactions.php. Order of checks: action (400), method (405), CSRF on restock (403), input (400 wrong type,
 * 422 rule), the reports permission for everyone's history (403). Answers keep their top-level fields; restock
 * reports the lines it could not add in `errors` and still adds the rest.
 */

/** Handle one action; every answer ends the request. */
function transactions_dispatch(string $action, PDO $pdo, int $userId): void
{
    try {
        if (!in_array($action, TRANSACTIONS_ACTIONS, true)) {
            throw new BadRequestException('Invalid action.');
        }
        $allowed = $action === 'list' ? ['GET', 'HEAD'] : ['POST'];
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ClientErrorException('Method not allowed', 405);
        }
        if ($action === 'restock' && !csrf_verify_api(req_json())) {
            throw new ForbiddenException('Invalid CSRF token');
        }
        $in = transactions_input($action);
        $answer = $action === 'list'
            ? transactions_list($pdo, transactions_history_owner($in['scope'], $userId), $in)
            : transactions_restock_answer($pdo, $userId, $in['items']);
        echo json_encode(['success' => true] + $answer);
    } catch (Throwable $e) {
        api_fail($e, 'transactions_api_error', ['action' => $action, 'user_id' => $userId], 'An error occurred processing your request.');
    }
}

/**
 * Whose history to show: the caller's own, or everyone's (0), which needs the reports permission.
 *
 * @throws ForbiddenException
 */
function transactions_history_owner(string $scope, int $userId): int
{
    if ($scope !== 'all') {
        return $userId;
    }
    if (!has_permission('reports.view')) {
        throw new ForbiddenException('Viewing everyone\'s transactions needs the reports permission.');
    }
    return 0;
}

/**
 * Restock and describe the outcome.
 *
 * @param list<array{product_id: int, quantity: int, store_id: int}> $items
 * @return array{message: string, added: int, errors: list<string>}
 */
function transactions_restock_answer(PDO $pdo, int $userId, array $items): array
{
    $result = transactions_restock($pdo, $userId, $items, get_max_order_quantity());
    $message = "Added {$result['added']} item(s) to cart for restocking" . ($result['errors'] === [] ? '' : '. Some items had errors.');
    Logger::info('transactions_restock', $message, ['user_id' => $userId, 'items_added' => $result['added'], 'errors' => $result['errors']]);
    return ['message' => $message] + $result;
}
