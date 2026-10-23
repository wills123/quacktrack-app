-- Migration: Create travel feature tables (regions, places, tags, comments)

-- ==========================================
-- TRAVEL REGIONS
-- ==========================================
CREATE TABLE IF NOT EXISTS travel_regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    updated_by INT,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_travel_regions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Regions for travel recommendations and avoid lists';

-- ==========================================
-- TRAVEL PLACES
-- ==========================================
CREATE TABLE IF NOT EXISTS travel_places (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    type ENUM('recommended', 'avoid') NOT NULL,
    description TEXT,
    safety_level ENUM('low', 'medium', 'high') NULL,
    visited TINYINT(1) NOT NULL DEFAULT 0,
    photo_path VARCHAR(255),
    added_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (region_id) REFERENCES travel_regions(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_travel_places_region (region_id),
    INDEX idx_travel_places_type (type),
    INDEX idx_travel_places_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Travel places with recommendations and avoid list metadata';

-- ==========================================
-- TRAVEL PLACE TAGS
-- ==========================================
CREATE TABLE IF NOT EXISTS travel_place_tags (
    place_id INT NOT NULL,
    tag VARCHAR(50) NOT NULL,

    PRIMARY KEY (place_id, tag),
    FOREIGN KEY (place_id) REFERENCES travel_places(id) ON DELETE CASCADE,
    INDEX idx_travel_place_tags_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tag list for travel places';

-- ==========================================
-- TRAVEL COMMENTS / RATINGS
-- ==========================================
CREATE TABLE IF NOT EXISTS travel_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    place_id INT NOT NULL,
    user_id INT,
    rating TINYINT NULL,
    comment_text VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (place_id) REFERENCES travel_places(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_travel_comments_place (place_id),
    INDEX idx_travel_comments_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Comments and optional ratings for travel places';
