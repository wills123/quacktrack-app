-- Migration: Add per-item receipt destinations for cart and orders
-- Date: 2026-01-18
-- Description: Store per-item split quantities for van vs region receipt

-- ============================================================================
-- Part 1: Update cart_items
-- ============================================================================

ALTER TABLE cart_items
ADD COLUMN receive_to_van_qty INT NOT NULL DEFAULT 0
    COMMENT 'Quantity of this cart item to receive to van stock'
    AFTER bill_to_store_id,
ADD COLUMN receive_to_region_qty INT NOT NULL DEFAULT 0
    COMMENT 'Quantity of this cart item to receive to region storage'
    AFTER receive_to_van_qty;

-- Default existing cart rows to van receipt
UPDATE cart_items
SET receive_to_van_qty = quantity,
    receive_to_region_qty = 0
WHERE receive_to_van_qty = 0
  AND receive_to_region_qty = 0;

-- ============================================================================
-- Part 2: Update purchase_order_items
-- ============================================================================

ALTER TABLE purchase_order_items
ADD COLUMN receive_to_van_qty INT NOT NULL DEFAULT 0
    COMMENT 'Quantity of this item to receive to van stock'
    AFTER notes,
ADD COLUMN receive_to_region_qty INT NOT NULL DEFAULT 0
    COMMENT 'Quantity of this item to receive to region storage'
    AFTER receive_to_van_qty;

-- Note: Existing orders will fall back to order-level receipt preferences.
