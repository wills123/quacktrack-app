<?php

declare(strict_types=1);

/**
 * Order receipt status helper, shared by the orders API and the tracked-asset
 * review API so that every path that records a receipt re-derives the order's
 * status (sent / partially_received / received) the same way.
 */

/**
 * Recalculate and store an order's receipt status from its stock and tracked-asset receipts.
 */
function syncOrderReceiptStatus(PDO $pdo, int $order_id): string
{
    $itemsStmt = $pdo->prepare('
        SELECT poi.id, poi.product_id, poi.quantity, p.is_tracked_asset
        FROM purchase_order_items poi
        JOIN products p ON p.id = poi.product_id
        WHERE poi.order_id = ?
    ');
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($items)) {
        return 'sent';
    }

    $supply_expected = [];
    $tracked_expected = [];
    foreach ($items as $item) {
        if ((int)$item['is_tracked_asset'] === 1) {
            $tracked_expected[(int)$item['id']] = (int)$item['quantity'];
            continue;
        }
        $product_id = (int)$item['product_id'];
        $supply_expected[$product_id] = ($supply_expected[$product_id] ?? 0) + (int)$item['quantity'];
    }

    $received_any = false;
    $is_complete = true;

    if (!empty($supply_expected)) {
        $receiptStmt = $pdo->prepare('
            SELECT product_id, SUM(quantity) AS quantity
            FROM purchase_order_stock_receipts
            WHERE order_id = ?
            GROUP BY product_id
        ');
        $receiptStmt->execute([$order_id]);
        $supply_received = [];
        foreach ($receiptStmt->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
            $quantity = (int)$receipt['quantity'];
            $supply_received[(int)$receipt['product_id']] = $quantity;
            $received_any = $received_any || $quantity > 0;
        }
        foreach ($supply_expected as $product_id => $quantity) {
            if (($supply_received[$product_id] ?? 0) < $quantity) {
                $is_complete = false;
            }
        }
    }

    if (!empty($tracked_expected)) {
        $assetReceiptStmt = $pdo->prepare('
            SELECT order_item_id, COUNT(*) AS quantity
            FROM purchase_order_asset_receipts
            WHERE order_id = ?
            GROUP BY order_item_id
        ');
        $assetReceiptStmt->execute([$order_id]);
        $tracked_received = [];
        foreach ($assetReceiptStmt->fetchAll(PDO::FETCH_ASSOC) as $receipt) {
            $quantity = (int)$receipt['quantity'];
            $tracked_received[(int)$receipt['order_item_id']] = $quantity;
            $received_any = $received_any || $quantity > 0;
        }
        foreach ($tracked_expected as $item_id => $quantity) {
            if (($tracked_received[$item_id] ?? 0) < $quantity) {
                $is_complete = false;
            }
        }
    }

    $status = $is_complete ? 'received' : ($received_any ? 'partially_received' : 'sent');
    $update = $pdo->prepare("UPDATE purchase_orders SET status = ? WHERE id = ? AND status <> 'cancelled'");
    $update->execute([$status, $order_id]);

    return $status;
}
