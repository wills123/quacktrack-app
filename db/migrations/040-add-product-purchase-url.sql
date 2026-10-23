-- Migration: Add purchase_url field to products table
-- Date: 2026-01-21

ALTER TABLE products
ADD COLUMN purchase_url VARCHAR(2048) NULL
    COMMENT 'External URL for purchasing this product'
    AFTER order_number;
