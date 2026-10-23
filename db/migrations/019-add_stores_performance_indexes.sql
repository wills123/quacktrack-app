-- Add indexes for stores table to optimize search and pagination performance
-- This is critical for handling large datasets (300+ stores)

-- Create indexes (will fail if they already exist, which is fine)
-- The migration runner will detect "Duplicate key name" and mark as already applied

CREATE INDEX idx_stores_name ON stores (name);
-- Note: address is TEXT, so we need to specify a prefix length for the index
CREATE INDEX idx_stores_name_address ON stores (name, address(100));
