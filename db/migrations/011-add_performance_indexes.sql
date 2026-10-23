-- Add indexes for better query performance

-- Index for transactions by user and date
CREATE INDEX idx_transactions_user_date ON transactions (user_id, created_at DESC);

-- Index for products by user
CREATE INDEX idx_products_user ON products (user_id);

-- Index for van_stock queries
CREATE INDEX idx_van_stock_user_quantity ON van_stock (user_id, quantity);

-- Index for region_storage queries
CREATE INDEX idx_region_storage_region ON region_storage (region, quantity);