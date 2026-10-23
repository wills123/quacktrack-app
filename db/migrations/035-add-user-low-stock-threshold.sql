-- Migration: Add per-user low stock threshold setting

ALTER TABLE users
ADD COLUMN low_stock_threshold INT NOT NULL DEFAULT 5
    COMMENT 'User-configurable low stock threshold for status badges'
    AFTER preferred_purchasing_email_id;

CREATE INDEX idx_users_low_stock_threshold ON users(low_stock_threshold);
