ALTER TABLE stores
    ADD COLUMN project_number VARCHAR(100) DEFAULT NULL AFTER store_number,
    ADD INDEX idx_stores_project_number (project_number);
