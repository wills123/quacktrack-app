-- Preserve financial attribution independently from order shipping and receipt destinations.

ALTER TABLE purchase_order_items
    ADD COLUMN bill_to_store_id INT NULL AFTER notes;

ALTER TABLE purchase_order_items
    ADD INDEX idx_purchase_order_items_bill_to_store (bill_to_store_id);

ALTER TABLE purchase_order_items
    ADD COLUMN bill_to_store_key INT NOT NULL DEFAULT 0;

UPDATE purchase_order_items
SET bill_to_store_key = COALESCE(bill_to_store_id, 0);

ALTER TABLE purchase_order_items
    DROP INDEX uk_order_product,
    ADD UNIQUE KEY uk_order_product_bill_to (order_id, product_id, bill_to_store_key);

ALTER TABLE cart_items
    ADD COLUMN bill_to_store_key INT NOT NULL DEFAULT 0;

UPDATE cart_items
SET bill_to_store_key = COALESCE(bill_to_store_id, 0);

ALTER TABLE cart_items
    DROP INDEX uk_cart_user_product,
    ADD UNIQUE KEY uk_cart_user_product_bill_to (user_id, product_id, bill_to_store_key);

ALTER TABLE purchase_order_items
    ADD CONSTRAINT fk_purchase_order_items_bill_to_store
        FOREIGN KEY (bill_to_store_id) REFERENCES stores(id) ON DELETE SET NULL;
