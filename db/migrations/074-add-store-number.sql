ALTER TABLE stores
    ADD COLUMN store_number VARCHAR(50) DEFAULT NULL AFTER name,
    ADD UNIQUE KEY uq_stores_store_number (store_number);
