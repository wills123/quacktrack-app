-- Add barcode support for products

ALTER TABLE products
  ADD COLUMN barcode VARCHAR(100) NULL AFTER product_id;

CREATE INDEX idx_products_barcode ON products (barcode);

-- Backfill barcode from existing SKUs by stripping colons.
-- This allows scanning codes like "QQQ110842" when the SKU is stored as "QQQ:110842".
UPDATE products
SET barcode = REPLACE(product_id, ':', '')
WHERE barcode IS NULL OR barcode = '';

