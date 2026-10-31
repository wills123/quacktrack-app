-- Update unique constraint to include store_id
DROP INDEX uk_van_stock ON van_stock;
CREATE UNIQUE INDEX uk_van_stock ON van_stock (product_id, user_id, store_id);