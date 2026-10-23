-- Migration: Add order receipt automation fields and idempotency log

ALTER TABLE purchase_orders
ADD COLUMN receive_to_van TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
ADD COLUMN receive_to_region TINYINT(1) NOT NULL DEFAULT 0 AFTER receive_to_van;

CREATE TABLE IF NOT EXISTS purchase_order_stock_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    destination ENUM('van', 'region') NOT NULL,
    quantity INT NOT NULL,
    target_user_id INT NULL,
    target_region_id INT NULL,
    store_id INT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_order_receipt (order_id, product_id, destination),
    CONSTRAINT fk_order_receipt_order FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_receipt_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_receipt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_order_receipt_target_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_receipt_target_region FOREIGN KEY (target_region_id) REFERENCES regions(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_receipt_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks stock receipts applied when purchase orders are marked received';
