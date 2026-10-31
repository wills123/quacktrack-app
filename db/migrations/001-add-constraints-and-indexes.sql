-- Migration: Add missing constraints and indexes
-- This migration adds the missing constraints and indexes identified in the code review

-- Add unique constraint to van_stock table if it doesn't exist
ALTER TABLE van_stock 
ADD CONSTRAINT uk_van_stock_user_product_store UNIQUE (user_id, product_id, store_id);

-- Add indexes to van_stock table
ALTER TABLE van_stock 
ADD INDEX idx_van_stock_user (user_id),
ADD INDEX idx_van_stock_product (product_id);

-- Add store_id column to transactions table
ALTER TABLE transactions 
ADD COLUMN store_id INT DEFAULT NULL AFTER reason,
ADD CONSTRAINT fk_tx_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL;

-- Add indexes to transactions table
ALTER TABLE transactions 
ADD INDEX idx_transactions_user (user_id),
ADD INDEX idx_transactions_product (product_id),
ADD INDEX idx_transactions_created (created_at);

-- Add region_id column to region_storage table for proper foreign key
ALTER TABLE region_storage 
ADD COLUMN region_id INT NOT NULL AFTER product_id,
ADD CONSTRAINT fk_region_storage_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE;

-- Add unique constraint to region_storage
ALTER TABLE region_storage 
ADD CONSTRAINT uk_region_storage_product_region UNIQUE (product_id, region_id);

-- Add index to region_storage
ALTER TABLE region_storage 
ADD INDEX idx_region_storage_region (region_id);

-- Add indexes to products table
ALTER TABLE products 
ADD INDEX idx_products_name (name),
ADD INDEX idx_products_user (user_id),
ADD INDEX idx_products_created (created_at);

-- Add index to cart_items table
ALTER TABLE cart_items 
ADD INDEX idx_cart_items_updated (updated_at);

-- Add indexes to purchase_orders table
ALTER TABLE purchase_orders 
ADD INDEX idx_purchase_orders_user (user_id),
ADD INDEX idx_purchase_orders_status (status),
ADD INDEX idx_purchase_orders_created (created_at);