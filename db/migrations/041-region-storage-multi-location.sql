-- Migration: Enable multi-location storage within a single region
-- Allows the same product to exist at multiple locations within one region

-- Older installations do not have the location column yet. Add it before
-- normalizing values and creating the location-aware indexes.
ALTER TABLE region_storage
  ADD COLUMN location VARCHAR(255) NULL AFTER region;

-- Convert NULL locations to empty string for consistency
UPDATE region_storage SET location = '' WHERE location IS NULL;

-- Make location NOT NULL with empty string default
ALTER TABLE region_storage
  MODIFY COLUMN location VARCHAR(255) NOT NULL DEFAULT '';

-- Add new unique constraint including location FIRST (before dropping old index)
-- This ensures foreign key can use the new index
CREATE UNIQUE INDEX uk_region_storage_product_region_location
  ON region_storage (product_id, region, location);

-- Now drop old unique constraint (product_id, region)
-- The current unique index is named 'product_id' and covers (product_id, region)
-- Note: Column name is 'region', not 'region_id'
DROP INDEX product_id ON region_storage;

-- Try to drop other possible variations (will be ignored if they don't exist)
DROP INDEX uk_region_storage_product_region ON region_storage;
DROP INDEX product_id_region_id ON region_storage;

-- Add index for location queries within a region
CREATE INDEX idx_region_storage_location
  ON region_storage (region, location);
