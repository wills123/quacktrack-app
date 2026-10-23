-- Migration: Add multi-store billing support
-- Date: 2025-12-26
-- Description: Add bill_to_store_id to cart_items and order_template_items
--              to support per-item billing while maintaining single shipping destination

-- ============================================================================
-- Part 1: Update cart_items table
-- ============================================================================

-- Add bill_to_store_id column to cart_items
ALTER TABLE cart_items
ADD COLUMN bill_to_store_id INT NULL
    COMMENT 'Store to bill for this item. NULL means same as ship_to_store.'
    AFTER notes;

-- Add foreign key constraint with ON DELETE SET NULL
-- If a store is deleted, billing falls back to ship_to_store (NULL)
ALTER TABLE cart_items
ADD CONSTRAINT fk_cart_bill_to_store
    FOREIGN KEY (bill_to_store_id)
    REFERENCES stores(id)
    ON DELETE SET NULL;

-- Add index for performance (queries filtering/grouping by billing store)
CREATE INDEX idx_cart_items_bill_to_store ON cart_items(bill_to_store_id);

-- ============================================================================
-- Part 2: Update order_template_items table
-- ============================================================================

-- Add bill_to_store_id to template items
-- Templates can save billing information for reuse
ALTER TABLE order_template_items
ADD COLUMN bill_to_store_id INT NULL
    COMMENT 'Store to bill for this item when template is applied'
    AFTER quantity;

-- Add foreign key constraint
ALTER TABLE order_template_items
ADD CONSTRAINT fk_template_items_bill_to_store
    FOREIGN KEY (bill_to_store_id)
    REFERENCES stores(id)
    ON DELETE SET NULL;

-- ============================================================================
-- Migration Notes
-- ============================================================================
--
-- Backward Compatibility:
-- - Both columns are nullable - existing records continue to work
-- - NULL value means "bill to ship_to_store" (default behavior)
-- - No data migration needed - gradual adoption as users modify carts
--
-- Benefits:
-- - Automatic billing allocation from transaction reorders
-- - Single order with multiple billing stores
-- - Clean separation of shipping vs billing
-- - Template support for multi-store billing workflows
--
-- ============================================================================
