-- Add Snipe-IT location mapping directly to stores table.
-- This allows kit shipments to check out assets to a store-specific
-- Snipe-IT location, overriding the region-level fallback.

ALTER TABLE stores
    ADD COLUMN snipe_location_id   INT UNSIGNED DEFAULT NULL
        COMMENT 'Snipe-IT location ID for this store (overrides region mapping)',
    ADD COLUMN snipe_location_name VARCHAR(255) DEFAULT NULL
        COMMENT 'Cached Snipe-IT location name for display';

CREATE INDEX idx_stores_snipe_location ON stores (snipe_location_id);
