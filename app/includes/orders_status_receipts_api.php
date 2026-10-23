<?php

declare(strict_types=1);

require_once __DIR__ . '/input_schemas/orders_status_receipts.php';
require_once __DIR__ . '/orders_status_receipts_assets.php';

/** The POST-only status/receipt boundary, with the established top-level response fields. */
function orders_status_receipts_dispatch(string $action, PDO $pdo, int $userId): void
{
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            throw new ClientErrorException('Method not allowed', 405);
        }
        if (!csrf_verify_api()) {
            throw new ForbiddenException('Invalid CSRF token');
        }
        $input = orders_status_receipts_input($action);
        $viewer = new OrdersViewer($userId, (int)($_SESSION['region_id'] ?? 0), false, false, has_permission('orders.manage'));
        $result = $action === 'update_status' ? orders_status_receipts_update($pdo, $viewer, $input)
            : orders_status_receipts_receive($pdo, $viewer, $input);
        if (isset($result['review_id'])) {
            api_error('No matching Snipe-IT asset was found. The item was sent to the service desk review queue.', 404, $result);
            return;
        }
        echo json_encode(['success' => true] + $result);
    } catch (SnipeUnavailableException $e) {
        api_error('Snipe-IT integration is not available.', 503);
    } catch (SnipeProviderException $e) {
        Logger::error('order_receipt_provider_error', $e, ['action' => $action, 'user_id' => $userId]);
        api_error('Snipe-IT did not answer correctly. Try again later.', 502);
    } catch (Throwable $e) {
        api_fail($e, 'orders_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to update this order.');
    }
}
