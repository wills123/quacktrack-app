-- Add region association to stores table
-- Prerequisite for the Store IT Standards Survey feature
-- Allows filtering stores by region throughout the application

ALTER TABLE stores ADD COLUMN region_id INT DEFAULT NULL;

ALTER TABLE stores ADD CONSTRAINT fk_stores_region
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;

CREATE INDEX idx_stores_region ON stores (region_id);
