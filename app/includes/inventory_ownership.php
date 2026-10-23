<?php

declare(strict_types=1);

/**
 * Inventory ownership rules shared by quantity-stock workflows.
 *
 * Quantity stock belongs to Quack-Track. Serialized/tracked assets belong to
 * Snipe-IT and must be handled through the Snipe-IT integration instead.
 */
final class InventoryOwnershipPolicy
{
    /**
     * Return whether a Quack-Track product is marked as a Snipe-IT asset.
     */
    public static function isTrackedAsset(PDO $pdo, int $productId): bool
    {
        $stmt = $pdo->prepare('SELECT is_tracked_asset FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (int)$value === 1;
    }

    /**
     * Reject a product when a caller is trying to use quantity-stock logic.
     *
     * @return array{name: string, product_id: string}
     */
    public static function requireQuantityProduct(PDO $pdo, int $productId): array
    {
        $stmt = $pdo->prepare(
            'SELECT name, product_id, is_tracked_asset FROM products WHERE id = ?'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException('Product not found');
        }

        if ((int)($product['is_tracked_asset'] ?? 0) === 1) {
            throw new RuntimeException(
                'This item is tracked in Snipe-IT and cannot be managed as quantity stock. '
                . 'Use the Snipe-IT asset workflow instead.'
            );
        }

        return $product;
    }
}
