-- Migration: Link travel feature to main regions table and add region notes

-- ==========================================
-- TRAVEL REGION NOTES
-- ==========================================
CREATE TABLE IF NOT EXISTS travel_region_notes (
    region_id INT PRIMARY KEY,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,

    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Optional notes for travel regions sourced from main regions table';

-- ==========================================
-- MIGRATE EXISTING TRAVEL REGIONS (if present)
-- ==========================================
INSERT INTO regions (name)
SELECT DISTINCT tr.name
FROM travel_regions tr
LEFT JOIN regions r ON r.name = tr.name
WHERE r.id IS NULL;

INSERT INTO travel_region_notes (region_id, notes, created_by, updated_by)
SELECT r.id,
       MAX(tr.notes) AS notes,
       MAX(tr.created_by) AS created_by,
       MAX(tr.updated_by) AS updated_by
FROM travel_regions tr
JOIN regions r ON r.name = tr.name
WHERE tr.notes IS NOT NULL AND TRIM(tr.notes) <> ''
GROUP BY r.id
ON DUPLICATE KEY UPDATE
    notes = VALUES(notes),
    updated_by = VALUES(updated_by);

-- ==========================================
-- REMAP TRAVEL PLACES TO MAIN REGIONS TABLE
-- ==========================================
UPDATE travel_places p
JOIN travel_regions tr ON p.region_id = tr.id
JOIN regions r ON r.name = tr.name
SET p.region_id = r.id;

-- Drop FK to travel_regions if it exists
SET @travel_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'travel_places'
      AND COLUMN_NAME = 'region_id'
      AND REFERENCED_TABLE_NAME = 'travel_regions'
    LIMIT 1
);
SET @drop_sql := IF(@travel_fk IS NOT NULL, CONCAT('ALTER TABLE travel_places DROP FOREIGN KEY ', @travel_fk), 'SELECT 1');
PREPARE drop_stmt FROM @drop_sql;
EXECUTE drop_stmt;
DEALLOCATE PREPARE drop_stmt;

-- Add FK to regions if missing
SET @regions_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'travel_places'
      AND COLUMN_NAME = 'region_id'
      AND REFERENCED_TABLE_NAME = 'regions'
    LIMIT 1
);
SET @add_sql := IF(@regions_fk IS NULL,
    'ALTER TABLE travel_places ADD CONSTRAINT fk_travel_places_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE add_stmt FROM @add_sql;
EXECUTE add_stmt;
DEALLOCATE PREPARE add_stmt;
