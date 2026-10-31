-- Migration: Add enhanced van stock transaction tracking table
-- This table provides more detailed tracking of van stock movements between stores

CREATE TABLE IF NOT EXISTS van_stock_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    quantity_change INT NOT NULL,
    reason ENUM('add', 'use', 'adjust', 'transfer', 'return', 'damage', 'loss') NOT NULL,
    source_store_id INT DEFAULT NULL,
    target_store_id INT DEFAULT NULL,
    note TEXT,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT fk_vst_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_vst_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vst_source_store FOREIGN KEY (source_store_id) REFERENCES stores(id) ON DELETE SET NULL,
    CONSTRAINT fk_vst_target_store FOREIGN KEY (target_store_id) REFERENCES stores(id) ON DELETE SET NULL,
    
    INDEX idx_vst_created (created_at),
    INDEX idx_vst_product_user (product_id, user_id),
    INDEX idx_vst_reason (reason),
    INDEX idx_vst_stores (source_store_id, target_store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes to improve transaction queries
-- Note: These will fail silently if indexes already exist
ALTER TABLE transactions ADD INDEX idx_trans_store_id (store_id);
ALTER TABLE transactions ADD INDEX idx_trans_reason (reason);
