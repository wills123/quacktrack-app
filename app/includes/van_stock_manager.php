<?php

/**
 * Van Stock Management System
 *
 * Handles intelligent van stock adjustments with store allocation logic
 * and comprehensive transaction logging.
 */

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/inventory_ownership.php';

class VanStockManager
{
    private PDO $pdo;
    private int $userId;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    /** Seconds one request waits for another stock change of the same product to finish. */
    private const LOCK_WAIT_SECONDS = 10;

    /** The longest note the legacy transactions table keeps (the audit table keeps the whole text). */
    private const LEGACY_NOTE_LENGTH = 255;

    /**
     * Run $work as the only stock change of this product: it holds the product's lock and one transaction,
     * which is committed when $work returns and rolled back when it throws.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     * @throws ConflictException when another change of the product does not finish in time
     */
    private function withProductLock(int $productId, callable $work): mixed
    {
        $lockName = 'quacktrack_stock_product_' . $productId;
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([$lockName, self::LOCK_WAIT_SECONDS]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new ConflictException('Another stock change for this product is in progress. Please try again.');
        }
        try {
            $this->pdo->beginTransaction();
            try {
                $result = $work();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    /**
     * The product, which must exist and be quantity stock (tracked assets belong to Snipe-IT).
     *
     * @return array{name: string}
     * @throws NotFoundException|UnprocessableException
     */
    private function requireQuantityProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT name, is_tracked_asset FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new NotFoundException('Product not found.');
        }
        if ((int)$product['is_tracked_asset'] === 1) {
            throw new UnprocessableException(
                'This item is tracked in Snipe-IT and cannot be managed as quantity stock. Use the Snipe-IT asset workflow instead.'
            );
        }
        return ['name' => (string)$product['name']];
    }

    /**
     * The name of a store that must exist.
     *
     * @throws NotFoundException
     */
    private function requireStore(int $storeId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM stores WHERE id = ?');
        $stmt->execute([$storeId]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new NotFoundException('Store not found.');
        }
        return (string)$name;
    }

    /**
     * Adjust van stock with intelligent store allocation. One call is one transaction under the product lock:
     * either the stock rows and both transaction logs change, or nothing does.
     *
     * @param int $quantity Quantity to adjust (positive for add, negative for use, positive for transfer)
     * @param int $targetStoreId Store where the item will be used/allocated
     * @param string $reason add, use or transfer
     * @param int|null $fromStoreId Source store of a transfer
     * @return array{success: bool, message: string, source_store: string|null, target_store: string}
     * @throws NotFoundException|UnprocessableException|ConflictException
     */
    public function adjustStock(int $productId, int $quantity, int $targetStoreId, string $reason = 'adjust', string $note = '', ?int $fromStoreId = null): array
    {
        return $this->withProductLock($productId, function () use ($productId, $quantity, $targetStoreId, $reason, $note, $fromStoreId): array {
            $product = $this->requireQuantityProduct($productId);
            $targetName = $this->requireStore($targetStoreId);
            $source = match ($reason) {
                'use' => $this->takeForUse($productId, abs($quantity), $targetStoreId, $targetName, $note),
                'add' => $this->putIntoStore($productId, $quantity, $targetStoreId, $targetName, $note),
                'transfer' => $this->moveBetweenStores($productId, abs($quantity), (int)$fromStoreId, $targetStoreId, $note),
                default => throw new LogicException("Unsupported stock reason '{$reason}'."),
            };
            return [
                'success' => true,
                'message' => $this->generateSuccessMessage($product['name'], $quantity, $reason, $source, $targetStoreId),
                'source_store' => $source,
                'target_store' => $targetName,
            ];
        });
    }

    /**
     * Remove stock for use at a store: from that store when it has any, else the unallocated store, else the
     * biggest other one. Returns the source store's name.
     *
     * @throws UnprocessableException when the chosen source cannot cover the quantity
     */
    private function takeForUse(int $productId, int $quantity, int $targetStoreId, string $targetName, string $note): string
    {
        $stmt = $this->pdo->prepare('
            SELECT vs.store_id, vs.quantity, s.name AS store_name
            FROM van_stock vs
            JOIN stores s ON s.id = vs.store_id
            WHERE vs.product_id = ? AND vs.user_id = ? AND vs.quantity > 0
            ORDER BY
                CASE
                    WHEN vs.store_id = ? THEN 0  -- Prioritize target store
                    WHEN vs.store_id = 1 THEN 1   -- Then unallocated
                    ELSE 2                         -- Then other stores
                END,
                vs.quantity DESC
            FOR UPDATE
        ');
        $stmt->execute([$productId, $this->userId, $targetStoreId]);
        $available = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $primary = $available[0] ?? null;
        if ($primary === null || (int)$primary['quantity'] < $quantity) {
            throw $this->insufficientStock($available, $quantity, $targetName);
        }
        $sourceStoreId = (int)$primary['store_id'];
        $this->updateStockQuantity($productId, $sourceStoreId, -$quantity);
        $this->logTransaction(
            $productId,
            -$quantity,
            'use',
            $sourceStoreId,
            $targetStoreId,
            sprintf('Removed from %s for use at %s. %s', $primary['store_name'], $targetName, $note)
        );
        return (string)$primary['store_name'];
    }

    /**
     * The refusal for a use that the best source cannot cover, with the stores that could.
     *
     * @param list<array<string, mixed>> $available rows with stock, best source first
     */
    private function insufficientStock(array $available, int $quantity, string $targetName): UnprocessableException
    {
        $primary = $available[0] ?? null;
        $message = sprintf(
            'Insufficient stock at %s: %d available, %d requested',
            $primary['store_name'] ?? $targetName,
            (int)($primary['quantity'] ?? 0),
            $quantity
        );
        $alternatives = [];
        foreach (array_slice($available, 1) as $stock) {
            if ((int)$stock['quantity'] >= $quantity) {
                $alternatives[] = ['store_id' => (int)$stock['store_id'], 'store_name' => $stock['store_name'], 'available' => (int)$stock['quantity']];
            }
        }
        if ($alternatives !== []) {
            $message .= '. Available at: ' . implode(', ', array_column($alternatives, 'store_name'));
        }
        return new UnprocessableException($message, $alternatives === [] ? [] : ['alternative_stores' => $alternatives]);
    }

    /** Add stock to a store. Returns null (there is no source). */
    private function putIntoStore(int $productId, int $quantity, int $storeId, string $storeName, string $note): null
    {
        $this->updateStockQuantity($productId, $storeId, $quantity);
        $this->logTransaction($productId, $quantity, 'add', $storeId, $storeId, sprintf('Added to %s. %s', $storeName, $note));
        return null;
    }

    /**
     * Move stock between two stores of this van. Returns the source store's name.
     *
     * @throws NotFoundException|UnprocessableException
     */
    private function moveBetweenStores(int $productId, int $quantity, int $fromStoreId, int $toStoreId, string $note): string
    {
        if ($fromStoreId === $toStoreId) {
            throw new UnprocessableException('Cannot transfer to the same store');
        }
        $fromName = $this->requireStore($fromStoreId);
        $toName = $this->getStoreName($toStoreId);
        $available = $this->lockedQuantity($productId, $this->userId, $fromStoreId);
        if ($available < $quantity) {
            throw new UnprocessableException(sprintf('Insufficient stock at %s. Available: %d, Requested: %d', $fromName, $available, $quantity));
        }
        $this->updateStockQuantity($productId, $fromStoreId, -$quantity);
        $this->updateStockQuantity($productId, $toStoreId, $quantity);
        $this->logTransaction($productId, $quantity, 'transfer', $fromStoreId, $toStoreId, sprintf('Transferred from %s to %s. %s', $fromName, $toName, $note));
        return $fromName;
    }

    /** The quantity of a stock row, locked for this transaction (0 when there is no row). */
    private function lockedQuantity(int $productId, int $userId, int $storeId): int
    {
        $stmt = $this->pdo->prepare('SELECT quantity FROM van_stock WHERE product_id = ? AND user_id = ? AND store_id = ? FOR UPDATE');
        $stmt->execute([$productId, $userId, $storeId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Change the quantity of one stock row (created when missing) of $userId's van. The row is locked first and
     * the quantity never goes below zero.
     *
     * @throws UnprocessableException when the change would leave a negative quantity
     */
    private function updateStockQuantity(int $productId, int $storeId, int $quantityChange, ?int $userId = null): void
    {
        $userId ??= $this->userId;
        $stmt = $this->pdo->prepare('SELECT id, quantity FROM van_stock WHERE product_id = ? AND user_id = ? AND store_id = ? FOR UPDATE');
        $stmt->execute([$productId, $userId, $storeId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $newQuantity = (int)($existing['quantity'] ?? 0) + $quantityChange;
        if ($newQuantity < 0) {
            throw new UnprocessableException('Insufficient stock.');
        }
        if ($existing) {
            $this->pdo->prepare('UPDATE van_stock SET quantity = ?, updated_at = NOW() WHERE id = ?')->execute([$newQuantity, $existing['id']]);
            return;
        }
        $this->pdo->prepare('INSERT INTO van_stock (product_id, user_id, store_id, quantity) VALUES (?, ?, ?, ?)')
            ->execute([$productId, $userId, $storeId, $newQuantity]);
    }

    /**
     * Log transaction with comprehensive details
     */
    private function logTransaction(
        int $productId,
        int $quantity,
        string $reason,
        int $sourceStoreId,
        int $targetStoreId,
        string $note
    ): void {
        $this->logTransactionForUser(
            $this->userId,
            $productId,
            $quantity,
            $reason,
            $sourceStoreId,
            $targetStoreId,
            $note
        );
    }

    /**
     * Log a transaction for a specific user
     */
    private function logTransactionForUser(
        int $userId,
        int $productId,
        int $quantity,
        string $reason,
        int $sourceStoreId,
        int $targetStoreId,
        string $note,
        ?array $metadata = null
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO transactions (
                product_id,
                user_id,
                change_qty,
                reason,
                store_id,
                store,
                note,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ');

        $stmt->execute([
            $productId,
            $userId,
            $quantity,
            $reason,
            $targetStoreId,
            $this->getStoreName($targetStoreId),
            mb_substr($note, 0, self::LEGACY_NOTE_LENGTH)
        ]);

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO van_stock_transactions (
                    product_id,
                    user_id,
                    quantity_change,
                    reason,
                    source_store_id,
                    target_store_id,
                    note,
                    metadata,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $productId,
                $userId,
                $quantity,
                $reason,
                $sourceStoreId,
                $targetStoreId,
                $note,
                $metadata ? json_encode($metadata) : null
            ]);
        } catch (PDOException $e) {
            // Preserve compatibility with installs without the optional audit table. Any other
            // failure must roll back the stock rows and both logs together.
            if ((int)($e->errorInfo[1] ?? 0) !== 1146) {
                throw $e;
            }
            Logger::info('stock_transfer_audit_unavailable', 'Optional stock transfer audit table is unavailable.', [
                'error_type' => get_class($e),
            ]);
        }
    }

    /**
     * Get store name by ID
     */
    private function getStoreName(int $storeId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM stores WHERE id = ?');
        $stmt->execute([$storeId]);
        return $stmt->fetchColumn() ?: 'Unknown Store';
    }

    /**
     * Generate user-friendly success message
     */
    private function generateSuccessMessage(
        string $productName,
        int $quantity,
        string $reason,
        ?string $sourceStore,
        int $targetStoreId
    ): string {
        $absQty = abs($quantity);

        switch ($reason) {
            case 'use':
                return sprintf(
                    'Successfully used %d %s of "%s" from %s for %s',
                    $absQty,
                    $absQty === 1 ? 'unit' : 'units',
                    $productName,
                    $sourceStore ?: 'van stock',
                    $this->getStoreName($targetStoreId)
                );

            case 'add':
                return sprintf(
                    'Successfully added %d %s of "%s" to %s',
                    $absQty,
                    $absQty === 1 ? 'unit' : 'units',
                    $productName,
                    $this->getStoreName($targetStoreId)
                );

            case 'transfer':
                return sprintf(
                    'Successfully transferred %d %s of "%s"',
                    $absQty,
                    $absQty === 1 ? 'unit' : 'units',
                    $productName
                );

            default:
                return sprintf(
                    'Successfully adjusted "%s" stock by %+d %s',
                    $productName,
                    $quantity,
                    abs($quantity) === 1 ? 'unit' : 'units'
                );
        }
    }

    /**
     * Get current stock levels for a product across all stores
     */
    public function getProductStockLevels(int $productId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                vs.store_id,
                s.name as store_name,
                vs.quantity,
                vs.updated_at
            FROM van_stock vs
            JOIN stores s ON vs.store_id = s.id
            WHERE vs.product_id = ? AND vs.user_id = ? AND vs.quantity > 0
            ORDER BY s.name
        ');
        $stmt->execute([$productId, $this->userId]);
        return $stmt->fetchAll();
    }

    /**
     * Get transaction history for reporting
     */
    public function getTransactionHistory(
        ?int $productId = null,
        ?int $storeId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $limit = 100
    ): array {
        $sql = "
            SELECT
                t.id,
                t.created_at,
                p.name as product_name,
                p.product_id as sku,
                t.change_qty,
                t.reason,
                t.store_id,
                s.name as store_name,
                t.note,
                u.email as user_email,
                COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display
            FROM transactions t
            JOIN products p ON t.product_id = p.id
            LEFT JOIN stores s ON t.store_id = s.id
            JOIN users u ON t.user_id = u.id
            WHERE t.user_id = ?
        ";

        $params = [$this->userId];

        if ($productId) {
            $sql .= ' AND t.product_id = ?';
            $params[] = $productId;
        }

        if ($storeId) {
            $sql .= ' AND t.store_id = ?';
            $params[] = $storeId;
        }

        if ($startDate) {
            $sql .= ' AND t.created_at >= ?';
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND t.created_at <= ?';
            $params[] = $endDate;
        }

        $sql .= ' ORDER BY t.created_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ========================================
    // PHASE 4C.1: CONVENIENCE METHODS
    // ========================================

    /**
     * Add stock to a store (convenience wrapper around adjustStock)
     *
     * @return array{success: bool, message: string, source_store: string|null, target_store: string}
     */
    public function addStock(int $productId, int $quantity, int $storeId, string $note = ''): array
    {
        return $this->adjustStock($productId, abs($quantity), $storeId, 'add', $note);
    }

    /**
     * Use/remove stock for a store (convenience wrapper around adjustStock)
     *
     * @return array{success: bool, message: string, source_store: string|null, target_store: string}
     */
    public function useStock(int $productId, int $quantity, int $storeId, string $note = ''): array
    {
        return $this->adjustStock($productId, -abs($quantity), $storeId, 'use', $note);
    }

    /**
     * Transfer stock between two stores of this van (convenience wrapper around adjustStock)
     *
     * @return array{success: bool, message: string, source_store: string|null, target_store: string}
     */
    public function transferStock(int $productId, int $quantity, int $fromStoreId, int $toStoreId, string $note = ''): array
    {
        return $this->adjustStock($productId, $quantity, $toStoreId, 'transfer', $note, $fromStoreId);
    }

    /**
     * Transfer stock from this user to another user's van, atomically: both vans' rows and all four log entries
     * change, or none do.
     *
     * @return array{success: bool, message: string}
     * @throws NotFoundException|UnprocessableException|ConflictException
     */
    public function transferStockToUser(int $productId, int $quantity, int $fromStoreId, int $targetUserId, int $toStoreId, string $note = ''): array
    {
        if ($targetUserId === $this->userId) {
            throw new UnprocessableException('Select another van to transfer stock to.');
        }
        return $this->withProductLock($productId, function () use ($productId, $quantity, $fromStoreId, $targetUserId, $toStoreId, $note): array {
            $product = $this->requireQuantityProduct($productId);
            $target = $this->pdo->prepare('SELECT id FROM users WHERE id = ?');
            $target->execute([$targetUserId]);
            if (!$target->fetchColumn()) {
                throw new NotFoundException('Target van not found.');
            }
            $sourceStoreName = $this->requireStore($fromStoreId);
            $targetStoreName = $this->requireStore($toStoreId);
            $available = $this->lockedQuantity($productId, $this->userId, $fromStoreId);
            if ($available < $quantity) {
                throw new UnprocessableException(sprintf('Insufficient stock at %s. Available: %d, Requested: %d', $sourceStoreName, $available, $quantity));
            }
            $this->updateStockQuantity($productId, $fromStoreId, -$quantity);
            $this->updateStockQuantity($productId, $toStoreId, $quantity, $targetUserId);
            $metadata = [
                'source_user_id' => $this->userId,
                'target_user_id' => $targetUserId,
                'source_store_id' => $fromStoreId,
                'target_store_id' => $toStoreId,
            ];
            $this->logTransactionForUser(
                $this->userId,
                $productId,
                -$quantity,
                'transfer',
                $fromStoreId,
                $toStoreId,
                sprintf('Transferred to another van (%s). %s', $targetStoreName, $note),
                $metadata
            );
            $this->logTransactionForUser(
                $targetUserId,
                $productId,
                $quantity,
                'transfer',
                $fromStoreId,
                $toStoreId,
                sprintf('Received from another van (%s). %s', $sourceStoreName, $note),
                $metadata
            );
            return [
                'success' => true,
                'message' => sprintf('Transferred %d %s of "%s" from %s to %s', $quantity, $quantity === 1 ? 'unit' : 'units', $product['name'], $sourceStoreName, $targetStoreName),
            ];
        });
    }

    /**
     * Get total stock for a product across all stores
     *
     * @param int $productId Product to query
     * @return int Total quantity
     */
    public function getTotalStock(int $productId): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(quantity), 0) as total
            FROM van_stock
            WHERE product_id = ? AND user_id = ? AND quantity > 0
        ');
        $stmt->execute([$productId, $this->userId]);
        return (int)$stmt->fetchColumn();
    }

    // ========================================
    // PHASE 4C.2: QUERY METHODS
    // ========================================

    /**
     * Get all stock for a specific store
     *
     * @param int $storeId Store to query
     * @param int|null $productId Optional filter by product
     * @return array Stock items at the store
     */
    public function getStockByStore(int $storeId, ?int $productId = null): array
    {
        $sql = '
            SELECT
                vs.product_id,
                p.name as product_name,
                p.product_id as sku,
                vs.quantity,
                vs.updated_at
            FROM van_stock vs
            JOIN products p ON vs.product_id = p.id
            WHERE vs.user_id = ? AND vs.store_id = ? AND vs.quantity > 0
        ';

        $params = [$this->userId, $storeId];

        if ($productId) {
            $sql .= ' AND vs.product_id = ?';
            $params[] = $productId;
        }

        $sql .= ' ORDER BY p.name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get products with stock below threshold
     *
     * @param int $threshold Minimum quantity threshold
     * @return array Products with low stock
     */
    public function getLowStockProducts(int $threshold = 5): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                p.id as product_id,
                p.name as product_name,
                p.product_id as sku,
                COALESCE(SUM(vs.quantity), 0) as total_quantity,
                ? as threshold
            FROM products p
            LEFT JOIN van_stock vs ON p.id = vs.product_id AND vs.user_id = ?
            GROUP BY p.id, p.name, p.product_id
            HAVING total_quantity > 0 AND total_quantity < ?
            ORDER BY total_quantity ASC, p.name
        ');
        $stmt->execute([$threshold, $this->userId, $threshold]);
        $lowStockProducts = $stmt->fetchAll();

        // Add store breakdown for each product
        foreach ($lowStockProducts as &$product) {
            $product['stores'] = $this->getProductStockLevels($product['product_id']);
        }

        return $lowStockProducts;
    }

    /**
     * Get product details with stock information
     *
     * @param int $productId Product to query
     * @return array|null Product data or null if not found
     */
    public function getProduct(int $productId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, product_id as sku, name
            FROM products
            WHERE id = ?
        ');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            return null;
        }

        $product['total_stock'] = $this->getTotalStock($productId);
        $product['stores'] = $this->getProductStockLevels($productId);

        return $product;
    }

    /**
     * Search stock by product name, SKU, or barcode
     *
     * @param string $query Search query
     * @param int|null $storeId Optional filter by store
     * @return array Matching products with stock
     */
    public function searchStock(string $query, ?int $storeId = null): array
    {
        $searchTerm = '%' . $query . '%';

        $sql = '
            SELECT DISTINCT
                p.id as product_id,
                p.product_id as sku,
                p.name as product_name,
                COALESCE(SUM(vs.quantity), 0) as total_quantity
            FROM products p
            LEFT JOIN van_stock vs ON p.id = vs.product_id AND vs.user_id = ?
            WHERE (
                p.name LIKE ?
                OR p.product_id LIKE ?
                OR p.barcode LIKE ?
            )
        ';

        $params = [$this->userId, $searchTerm, $searchTerm, $searchTerm];

        if ($storeId) {
            $sql .= ' AND (vs.store_id = ? OR vs.store_id IS NULL)';
            $params[] = $storeId;
        }

        $sql .= '
            GROUP BY p.id, p.product_id, p.name
            HAVING total_quantity > 0
            ORDER BY p.name
            LIMIT 50
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ========================================
    // PHASE 4C.3: ANALYTICS METHODS
    // ========================================

    /**
     * Get comprehensive stock summary
     *
     * @return array Aggregated stock metrics
     */
    public function getStockSummary(): array
    {
        // Total products with stock
        $stmt = $this->pdo->prepare('
            SELECT COUNT(DISTINCT product_id) as total_products
            FROM van_stock
            WHERE user_id = ? AND quantity > 0
        ');
        $stmt->execute([$this->userId]);
        $totalProducts = (int)$stmt->fetchColumn();

        // Total quantity
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(quantity), 0) as total_quantity
            FROM van_stock
            WHERE user_id = ? AND quantity > 0
        ');
        $stmt->execute([$this->userId]);
        $totalQuantity = (int)$stmt->fetchColumn();

        // Stores with stock
        $stmt = $this->pdo->prepare('
            SELECT COUNT(DISTINCT store_id) as stores_with_stock
            FROM van_stock
            WHERE user_id = ? AND quantity > 0
        ');
        $stmt->execute([$this->userId]);
        $storesWithStock = (int)$stmt->fetchColumn();

        // Low stock count (using default threshold of 5)
        $lowStockProducts = $this->getLowStockProducts(5);
        $lowStockCount = count($lowStockProducts);

        // Last transaction
        $stmt = $this->pdo->prepare('
            SELECT created_at
            FROM transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $stmt->execute([$this->userId]);
        $lastTransaction = $stmt->fetchColumn() ?: null;

        return [
            'total_products' => $totalProducts,
            'total_quantity' => $totalQuantity,
            'stores_with_stock' => $storesWithStock,
            'low_stock_count' => $lowStockCount,
            'last_transaction' => $lastTransaction
        ];
    }

    /**
     * Get stock movement report for a time period
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array Movement analysis
     */
    public function getStockMovementReport(string $startDate, string $endDate): array
    {
        // Get transactions in period
        $stmt = $this->pdo->prepare('
            SELECT
                reason,
                SUM(change_qty) as total_change,
                COUNT(*) as transaction_count
            FROM transactions
            WHERE user_id = ?
                AND created_at >= ?
                AND created_at <= ?
            GROUP BY reason
        ');
        $stmt->execute([$this->userId, $startDate, $endDate]);
        $movements = $stmt->fetchAll();

        $additions = 0;
        $uses = 0;
        $transfers = 0;

        foreach ($movements as $movement) {
            switch ($movement['reason']) {
                case 'add':
                    $additions += $movement['total_change'];
                    break;
                case 'use':
                    $uses += abs($movement['total_change']);
                    break;
                case 'transfer':
                    $transfers += $movement['transaction_count'];
                    break;
            }
        }

        // Most active products
        $stmt = $this->pdo->prepare('
            SELECT
                p.name as product_name,
                p.product_id as sku,
                COUNT(*) as transaction_count,
                SUM(t.change_qty) as net_change
            FROM transactions t
            JOIN products p ON t.product_id = p.id
            WHERE t.user_id = ?
                AND t.created_at >= ?
                AND t.created_at <= ?
            GROUP BY p.id, p.name, p.product_id
            ORDER BY transaction_count DESC
            LIMIT 10
        ');
        $stmt->execute([$this->userId, $startDate, $endDate]);
        $mostActiveProducts = $stmt->fetchAll();

        // Most active stores
        $stmt = $this->pdo->prepare('
            SELECT
                s.name as store_name,
                COUNT(*) as transaction_count
            FROM transactions t
            JOIN stores s ON t.store_id = s.id
            WHERE t.user_id = ?
                AND t.created_at >= ?
                AND t.created_at <= ?
            GROUP BY s.id, s.name
            ORDER BY transaction_count DESC
            LIMIT 10
        ');
        $stmt->execute([$this->userId, $startDate, $endDate]);
        $mostActiveStores = $stmt->fetchAll();

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'additions' => $additions,
            'uses' => $uses,
            'transfers' => $transfers,
            'net_change' => $additions - $uses,
            'most_active_products' => $mostActiveProducts,
            'most_active_stores' => $mostActiveStores
        ];
    }
}
