-- Track what happens to a serialized asset after it is replaced or removed.

ALTER TABLE purchase_order_asset_receipts
    ADD COLUMN disposition ENUM('active', 'returned', 'repair', 'retired', 'lost') NOT NULL DEFAULT 'active' AFTER store_id,
    ADD COLUMN disposition_note VARCHAR(500) DEFAULT NULL AFTER disposition,
    ADD COLUMN disposed_by INT DEFAULT NULL AFTER disposition_note,
    ADD COLUMN disposed_at TIMESTAMP NULL DEFAULT NULL AFTER disposed_by,
    ADD CONSTRAINT fk_asset_receipt_disposed_by FOREIGN KEY (disposed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_asset_receipts_disposition (disposition);
