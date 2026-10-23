-- Queue unmatched tracked-asset scans for service-desk review.

CREATE TABLE IF NOT EXISTS snipe_asset_review_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    order_item_id INT NOT NULL,
    product_id INT NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    status ENUM('pending', 'created', 'linked', 'dismissed') NOT NULL DEFAULT 'pending',
    snipe_asset_id INT UNSIGNED DEFAULT NULL,
    review_note VARCHAR(500) DEFAULT NULL,
    submitted_by INT NOT NULL,
    reviewed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,

    CONSTRAINT fk_snipe_review_order
        FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_snipe_review_item
        FOREIGN KEY (order_item_id) REFERENCES purchase_order_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_snipe_review_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_snipe_review_submitter
        FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_snipe_review_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_snipe_review_status (status, created_at),
    INDEX idx_snipe_review_order_item (order_item_id),
    INDEX idx_snipe_review_identifier (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Unmatched tracked-asset scans awaiting service-desk review';
