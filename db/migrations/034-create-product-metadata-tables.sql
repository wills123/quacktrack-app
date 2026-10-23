-- Migration: Create product categories and manufacturers management tables
-- This migration adds proper category and manufacturer management to replace free-form text fields

-- ==========================================
-- PRODUCT CATEGORIES TABLE
-- ==========================================
CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_category_active (is_active),
    INDEX idx_category_sort (sort_order),
    INDEX idx_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Manages product categories with admin-defined taxonomy';

-- ==========================================
-- PRODUCT MANUFACTURERS TABLE
-- ==========================================
CREATE TABLE IF NOT EXISTS product_manufacturers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    website VARCHAR(255),
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_manufacturer_active (is_active),
    INDEX idx_manufacturer_sort (sort_order),
    INDEX idx_manufacturer_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Manages product manufacturers with admin-defined list';

-- ==========================================
-- MIGRATE EXISTING DATA
-- ==========================================

-- Migrate categories from existing products
-- NOTE: DISTINCT must be applied before ROW_NUMBER() is computed, otherwise the
-- window function makes every source row unique (per-row, not per-name) and
-- DISTINCT becomes a no-op, causing duplicate-name inserts when multiple
-- products share the same asset_type.
INSERT INTO product_categories (name, is_active, sort_order)
SELECT
    name,
    1 as is_active,
    ROW_NUMBER() OVER (ORDER BY name) as sort_order
FROM (
    SELECT DISTINCT TRIM(asset_type) as name
    FROM products
    WHERE asset_type IS NOT NULL
      AND TRIM(asset_type) != ''
      AND NOT EXISTS (
          SELECT 1 FROM product_categories pc
          WHERE pc.name = TRIM(products.asset_type)
      )
) AS distinct_categories
ORDER BY name;

-- Migrate manufacturers from existing products
INSERT INTO product_manufacturers (name, is_active, sort_order)
SELECT
    name,
    1 as is_active,
    ROW_NUMBER() OVER (ORDER BY name) as sort_order
FROM (
    SELECT DISTINCT TRIM(manufacturer) as name
    FROM products
    WHERE manufacturer IS NOT NULL
      AND TRIM(manufacturer) != ''
      AND NOT EXISTS (
          SELECT 1 FROM product_manufacturers pm
          WHERE pm.name = TRIM(products.manufacturer)
      )
) AS distinct_manufacturers
ORDER BY name;

-- ==========================================
-- ADD FOREIGN KEYS TO PRODUCTS TABLE
-- ==========================================

-- Note: We keep the original text columns for backward compatibility
-- New products should use the ID columns for consistency

-- Add category_id column
ALTER TABLE products
ADD COLUMN category_id INT NULL AFTER asset_type;

-- Add manufacturer_id column
ALTER TABLE products
ADD COLUMN manufacturer_id INT NULL AFTER manufacturer;

-- Add foreign key constraints with ON DELETE SET NULL for safety
ALTER TABLE products
ADD CONSTRAINT fk_products_category
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL;

ALTER TABLE products
ADD CONSTRAINT fk_products_manufacturer
    FOREIGN KEY (manufacturer_id) REFERENCES product_manufacturers(id) ON DELETE SET NULL;

-- Add indexes for performance
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_manufacturer ON products(manufacturer_id);

-- ==========================================
-- BACKFILL FOREIGN KEY IDS
-- ==========================================

-- Link existing products to their categories
UPDATE products p
INNER JOIN product_categories pc ON TRIM(p.asset_type) = pc.name
SET p.category_id = pc.id
WHERE p.asset_type IS NOT NULL AND TRIM(p.asset_type) != '';

-- Link existing products to their manufacturers
UPDATE products p
INNER JOIN product_manufacturers pm ON TRIM(p.manufacturer) = pm.name
SET p.manufacturer_id = pm.id
WHERE p.manufacturer IS NOT NULL AND TRIM(p.manufacturer) != '';
