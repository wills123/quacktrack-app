-- Migration: Create Snipe IT location mappings table
-- Phase 5: Location Mapping
-- Maps Snipe IT locations to Quacktrack regions for user assignment

CREATE TABLE snipe_it_location_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL,
    snipe_location_id INT UNSIGNED NOT NULL,
    snipe_location_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_snipe_location (snipe_location_id),
    CONSTRAINT fk_snipe_loc_map_region FOREIGN KEY (region_id)
        REFERENCES regions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table description:
-- id: Primary key
-- region_id: Quacktrack region (foreign key to regions table)
-- snipe_location_id: Snipe IT location ID
-- snipe_location_name: Snipe IT location name (for reference/debugging)
-- created_at: When mapping was created
-- updated_at: When mapping was last updated
-- uk_snipe_location: Ensures each Snipe IT location maps to exactly one region (globally unique)

-- Insert default mapping example (uncomment if you have a default region):
-- INSERT INTO snipe_it_location_mappings (region_id, snipe_location_id, snipe_location_name)
-- VALUES (1, 1, 'Headquarters');
