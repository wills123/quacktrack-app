-- Add store_id column with a default value (assuming 'Unallocated' store has ID 1)
ALTER TABLE van_stock ADD COLUMN store_id INT NOT NULL DEFAULT 1;

-- Add foreign key constraint
ALTER TABLE van_stock ADD CONSTRAINT fk_van_stock_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT;