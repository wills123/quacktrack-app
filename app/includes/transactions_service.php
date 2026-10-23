<?php

/**
 * Van stock transaction history and restock (api/transactions.php). The history is a read of van_stock_transactions
 * with fixed SQL (filters are bound, never spliced in). Restock adds lines to the caller's own cart, one cart line per
 * product and billing store; lines it cannot take are reported in `errors` and the others are still added.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/cart_service.php';

/** The filters every history query shares; the ? pairs let one statement serve every combination of filters. */
const TRANSACTIONS_FILTER_SQL = '
    FROM van_stock_transactions vst
    JOIN products p ON p.id = vst.product_id
    LEFT JOIN stores ss ON ss.id = vst.source_store_id
    LEFT JOIN stores ts ON ts.id = vst.target_store_id
    LEFT JOIN users u ON u.id = vst.user_id
    WHERE (? = 0 OR vst.user_id = ?)
      AND vst.created_at BETWEEN ? AND ?
      AND (? = 0 OR vst.target_store_id = ?)
      AND (? = \'\' OR vst.reason = ?)
      AND (? = \'all\' OR (vst.target_store_id IS NOT NULL AND (vst.source_store_id IS NULL OR vst.source_store_id <> vst.target_store_id)))';

/**
 * The bound values of TRANSACTIONS_FILTER_SQL, in order.
 *
 * @param array<string, mixed> $in parsed list input
 * @return list<int|string>
 */
function transactions_filter_params(array $in, int $ownerId): array
{
    $reason = ($in['reason'] ?? 'all') === 'all' ? '' : $in['reason'];
    return [
        $ownerId, $ownerId,
        $in['start_date'] . ' 00:00:00', $in['end_date'] . ' 23:59:59',
        $in['store_id'], $in['store_id'],
        $reason, $reason,
        $in['tab'],
    ];
}

/**
 * One page of the history, the store usage summary (Store Usage tab only), paging and the filters applied. The answer
 * keeps the handler's established top-level fields. $ownerId limits it to one user's transactions; 0 is everyone's
 * (the caller has checked the reports permission).
 *
 * @param array<string, mixed> $in parsed list input
 * @return array<string, mixed>
 */
function transactions_list(PDO $pdo, int $ownerId, array $in): array
{
    $params = transactions_filter_params($in, $ownerId);
    $count = $pdo->prepare('SELECT COUNT(*)' . TRANSACTIONS_FILTER_SQL);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $limit = $in['limit'];
    $offset = ($in['page'] - 1) * $limit;
    $rows = $pdo->prepare(
        'SELECT vst.id, vst.product_id, vst.user_id, vst.quantity_change, vst.reason, vst.source_store_id, vst.target_store_id,
                vst.note, vst.created_at, p.name AS product_name, p.product_id AS sku, ss.name AS source_store_name,
                ts.name AS target_store_name, u.email AS user_email,
                COALESCE(NULLIF(u.username, \'\'), NULLIF(CONCAT_WS(\' \', u.first_name, u.last_name), \'\'), u.email) AS user_display'
        . TRANSACTIONS_FILTER_SQL
        . ' ORDER BY vst.created_at DESC, vst.id DESC LIMIT ? OFFSET ?'
    );
    $rows->execute([...$params, $limit, $offset]);
    return [
        'tab' => $in['tab'],
        'transactions' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'summary' => $in['tab'] === 'store_usage' ? transactions_summary($pdo, $params) : [],
        'pagination' => [
            'page' => $in['page'], 'limit' => $limit, 'total' => $total, 'total_pages' => (int)ceil($total / $limit),
            'has_prev' => $in['page'] > 1, 'has_next' => ($offset + $limit) < $total,
        ],
        'filters' => [
            'start_date' => $in['start_date'], 'end_date' => $in['end_date'], 'store_id' => $in['store_id'],
            'reason' => $in['reason'] ?? '', 'scope' => $in['scope'],
        ],
    ];
}

/**
 * Billable usage summed by target store, source store and product.
 *
 * @param list<int|string> $params
 * @return list<array<string, mixed>>
 */
function transactions_summary(PDO $pdo, array $params): array
{
    $summary = $pdo->prepare(
        'SELECT vst.target_store_id, ts.name AS target_store_name, vst.source_store_id, ss.name AS source_store_name,
                p.id AS product_id, p.name AS product_name, p.product_id AS sku, SUM(ABS(vst.quantity_change)) AS total_qty'
        . TRANSACTIONS_FILTER_SQL
        . ' GROUP BY vst.target_store_id, ts.name, vst.source_store_id, ss.name, p.id, p.product_id, p.name
            ORDER BY ts.name ASC, p.name ASC'
    );
    $summary->execute($params);
    return $summary->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add the restock lines to the caller's cart, each billed to its store. A line whose quantity is over the order
 * limit, whose product is missing or not orderable, or whose store does not exist is skipped and reported; the rest
 * are written in one transaction, so a database failure adds nothing.
 *
 * @param list<array{product_id: int, quantity: int, store_id: int}> $items
 * @return array{added: int, errors: list<string>}
 */
function transactions_restock(PDO $pdo, int $userId, array $items, int $maxPerItem): array
{
    $product = $pdo->prepare('SELECT product_id AS sku, status FROM products WHERE id = ?');
    $store = $pdo->prepare('SELECT id FROM stores WHERE id = ?');
    $accepted = [];
    $errors = [];
    foreach ($items as $item) {
        $problem = transactions_restock_problem($product, $store, $item, $maxPerItem);
        if ($problem === null) {
            $accepted[] = $item;
        } else {
            $errors[] = $problem;
        }
    }
    if ($accepted !== []) {
        transactions_add_to_cart($pdo, $userId, $accepted);
    }
    return ['added' => count($accepted), 'errors' => $errors];
}

/**
 * Why a restock line cannot be added, in the handler's established wording, or null when it can.
 *
 * @param array{product_id: int, quantity: int, store_id: int} $item
 */
function transactions_restock_problem(PDOStatement $product, PDOStatement $store, array $item, int $maxPerItem): ?string
{
    $productId = $item['product_id'];
    if ($item['quantity'] > $maxPerItem) {
        return "Invalid quantity for product {$productId}. Must be 1-{$maxPerItem}.";
    }
    $product->execute([$productId]);
    $row = $product->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return "Product not found: {$productId}";
    }
    if (($row['status'] ?? '') !== 'active') {
        return "{$row['sku']} is not currently orderable.";
    }
    $store->execute([$item['store_id']]);
    return $store->fetch() === false ? "Store not found: {$item['store_id']}" : null;
}

/**
 * Write the accepted lines: one cart line per product and billing store (bill_to_store_key), quantities summed.
 *
 * @param list<array{product_id: int, quantity: int, store_id: int}> $items
 */
function transactions_add_to_cart(PDO $pdo, int $userId, array $items): void
{
    $pdo->beginTransaction();
    try {
        $add = $pdo->prepare(
            // Restocked units go to the van. A merged line keeps its region units: when its split adds up, the new
            // units join the van part (set before quantity, as MySQL applies these assignments in order).
            'INSERT INTO cart_items (user_id, product_id, quantity, notes, bill_to_store_id, bill_to_store_key, receive_to_van_qty, receive_to_region_qty)
             VALUES (?, ?, ?, \'\', ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                receive_to_van_qty = IF(receive_to_van_qty + receive_to_region_qty = quantity, receive_to_van_qty + VALUES(quantity), receive_to_van_qty),
                quantity = quantity + VALUES(quantity), updated_at = CURRENT_TIMESTAMP'
        );
        foreach ($items as $item) {
            cart_resync_key($pdo, $userId, $item['product_id'], $item['store_id']);
            $add->execute([$userId, $item['product_id'], $item['quantity'], $item['store_id'], $item['store_id'], $item['quantity']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
