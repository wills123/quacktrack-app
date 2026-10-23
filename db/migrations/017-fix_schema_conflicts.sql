-- Migration: Fix schema conflicts and ensure consistency
-- This migration safely handles potential conflicts from previous migrations

-- Ensure stores table exists with default store
INSERT IGNORE INTO stores (id, name, address) VALUES (1, 'Unallocated', 'Default store for unallocated items');

-- Fix van_stock table structure if needed
-- First, check if store_id column exists, if not add it
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'van_stock' 
    AND COLUMN_NAME = 'store_id'
);

-- Add store_id column if it doesn't exist
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE van_stock ADD COLUMN store_id INT NOT NULL DEFAULT 1',
    'SELECT "store_id column already exists" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'van_stock' 
    AND CONSTRAINT_NAME = 'fk_van_stock_store'
);

SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE van_stock ADD CONSTRAINT fk_van_stock_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT',
    'SELECT "fk_van_stock_store constraint already exists" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fix unique constraint on van_stock
-- Drop old constraint if it exists
SET @old_constraint_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'van_stock' 
    AND INDEX_NAME = 'uk_van_stock'
    AND COLUMN_NAME = 'product_id'
    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = 'van_stock' 
         AND INDEX_NAME = 'uk_van_stock') = 2
);

-- Drop old 2-column unique constraint if it exists
SET @sql = IF(@old_constraint_exists > 0, 
    'ALTER TABLE van_stock DROP INDEX uk_van_stock',
    'SELECT "Old uk_van_stock constraint does not exist" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create new 3-column unique constraint if it doesn't exist
SET @new_constraint_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'van_stock' 
    AND INDEX_NAME = 'uk_van_stock_user_product_store'
);

SET @sql = IF(@new_constraint_exists = 0, 
    'ALTER TABLE van_stock ADD CONSTRAINT uk_van_stock_user_product_store UNIQUE (user_id, product_id, store_id)',
    'SELECT "uk_van_stock_user_product_store constraint already exists" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Safely update transactions reason ENUM
-- First check current ENUM values
SET @enum_has_transfer = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'transactions' 
    AND COLUMN_NAME = 'reason'
    AND COLUMN_TYPE LIKE "%'transfer'%"
);

-- Only modify if transfer is not already in the ENUM
SET @sql = IF(@enum_has_transfer = 0, 
    "ALTER TABLE transactions MODIFY COLUMN reason ENUM('add', 'use', 'adjust', 'transfer') NOT NULL",
    'SELECT "transactions.reason ENUM already includes transfer" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add missing indexes if they don't exist
-- Check and add index on transactions.store_id
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'transactions' 
    AND INDEX_NAME = 'idx_trans_store_id'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE transactions ADD INDEX idx_trans_store_id (store_id)',
    'SELECT "idx_trans_store_id already exists" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add index on transactions.reason
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'transactions' 
    AND INDEX_NAME = 'idx_trans_reason'
);

SET @sql = IF(@index_exists = 0, 
    'ALTER TABLE transactions ADD INDEX idx_trans_reason (reason)',
    'SELECT "idx_trans_reason already exists" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;