-- Add is_tracked_asset flag to products
-- Purpose: Marks whether a product requires individual serial number tracking and Snipe-IT sync.
--   1 = tracked asset (serialized, registered in Snipe-IT on receipt, chain of custody required)
--   0 = quantity-only item (tracked by count in van stock, no serial number needed)
-- This flag is set by admins per product and drives the Van Ledger and Receive+Register workflows.

ALTER TABLE products
  ADD COLUMN is_tracked_asset TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = serialized asset tracked in Snipe-IT; 0 = quantity-only van stock item'
  AFTER purchase_url;

CREATE INDEX idx_products_tracked ON products (is_tracked_asset);
