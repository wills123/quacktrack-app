-- Track individual Snipe-IT assets received against tracked purchase-order items.
-- A Snipe-IT asset is matched only after a technician scans its serial or tag.

CREATE TABLE IF NOT EXISTS purchase_order_asset_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    product_id INT NOT NULL,
    snipe_asset_id INT UNSIGNED NOT NULL,
    serial VARCHAR(255) DEFAULT NULL,
    asset_tag VARCHAR(255) DEFAULT NULL,
    destination ENUM('van', 'region') NOT NULL DEFAULT 'van',
    target_user_id INT DEFAULT NULL,
    target_region_id INT DEFAULT NULL,
    store_id INT DEFAULT NULL,
    received_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_asset_receipt_order
        FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_receipt_item
        FOREIGN KEY (order_item_id) REFERENCES purchase_order_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_receipt_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_receipt_target_user
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_receipt_target_region
        FOREIGN KEY (target_region_id) REFERENCES regions(id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_receipt_store
        FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_receipt_receiver
        FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,

    UNIQUE KEY uk_asset_receipt_snipe_asset (snipe_asset_id),
    UNIQUE KEY uk_asset_receipt_order_item_asset (order_item_id, snipe_asset_id),
    INDEX idx_asset_receipts_order (order_id),
    INDEX idx_asset_receipts_item (order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual Snipe-IT assets matched to tracked purchase-order items';
