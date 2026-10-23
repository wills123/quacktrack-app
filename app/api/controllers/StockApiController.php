<?php

/**
 * StockApiController
 *
 * Handles stock management API operations: the middleware checks (permission, rate limit), the request
 * schemas (includes/input_schemas/stock.php) and the van stock manager, which does the locked, atomic work.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api_errors.php';
require_once __DIR__ . '/../../includes/input_schemas/stock.php';
require_once __DIR__ . '/../../validation/ValidationRules.php';
require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiMiddleware.php';
require_once __DIR__ . '/../../includes/van_stock_manager.php';

class StockApiController
{
    private VanStockManager $stockManager;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->stockManager = new VanStockManager($pdo, $userId);
    }

    /**
     * Route request to appropriate action handler. Client mistakes answer with their 4xx status; anything else
     * is logged and answers a generic 500.
     */
    public function handleRequest(string $action): void
    {
        try {
            match ($action) {
                'add_item' => $this->handleAddItem(),
                'adjust' => $this->handleAdjustStock(),
                'transfer' => $this->handleTransfer(),
                'transfer_van' => $this->handleTransferVan(),
                default => throw new BadRequestException('Invalid action'),
            };
        } catch (ClientError $e) {
            $this->respondClientError($e);
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('stock_api', $e, ['action' => $action, 'file' => $e->getFile(), 'line' => $e->getLine()]);
            ApiResponse::error('An error occurred while processing your request', 500);
        }
    }

    /** The old response shape: message, a list of problems in 'errors', and extra data such as alternative stores. */
    private function respondClientError(ClientError $e): never
    {
        $details = $e->getDetails();
        $fields = $details['fields'] ?? null;
        unset($details['fields']);
        ApiResponse::failure($e->getMessage(), $e->getHttpStatus(), is_array($fields) ? array_values($fields) : null, $details === [] ? null : $details);
    }

    /** Permission, rate limit, then the request body for $action within the administrator's limits. @return array<string, mixed> */
    private function begin(string $action, string $rateKey): array
    {
        ApiMiddleware::applyAll('stock.adjust', $rateKey, ValidationRules::getStockRateLimit(), ValidationRules::getStockRateLimitWindow());
        $data = stock_input($action);
        stock_check_limits($data, ValidationRules::getMaxStockQuantity(), ValidationRules::getMaxNoteLength());
        return $data;
    }

    private function handleAddItem(): never
    {
        $data = $this->begin('add_item', 'stock_add');
        $result = $this->stockManager->addStock($data['product_id'], $data['quantity'], $data['store_id'], $data['note']);
        ApiResponse::success($result, $result['message']);
    }

    /** Stock adjustment: add to a location, or use from it (the best source location is chosen). */
    private function handleAdjustStock(): never
    {
        $data = $this->begin('adjust', 'stock_adjust');
        $result = $data['adjustment_type'] === 'add'
            ? $this->stockManager->addStock($data['product_id'], $data['quantity'], $data['store_id'], $data['note'])
            : $this->stockManager->useStock($data['product_id'], $data['quantity'], $data['store_id'], $data['note']);
        ApiResponse::success($result, $result['message']);
    }

    private function handleTransfer(): never
    {
        $data = $this->begin('transfer', 'stock_transfer');
        $result = $this->stockManager->transferStock($data['product_id'], $data['quantity'], $data['from_store_id'], $data['to_store_id'], $data['note']);
        ApiResponse::success($result, $result['message']);
    }

    private function handleTransferVan(): never
    {
        $data = $this->begin('transfer_van', 'stock_transfer_van');
        $result = $this->stockManager->transferStockToUser(
            $data['product_id'],
            $data['quantity'],
            $data['from_store_id'],
            $data['target_user_id'],
            $data['to_store_id'],
            $data['note']
        );
        ApiResponse::success($result, $result['message']);
    }
}
