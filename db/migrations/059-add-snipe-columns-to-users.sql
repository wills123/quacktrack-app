-- Migration: Add Snipe IT OAuth columns to users table
-- Phase 2: OAuth2 SSO Integration
-- Adds columns for tracking Snipe IT user data and login method

ALTER TABLE users
    ADD COLUMN snipe_user_id INT UNSIGNED DEFAULT NULL AFTER role,
    ADD COLUMN snipe_department VARCHAR(100) DEFAULT NULL,
    ADD COLUMN snipe_company VARCHAR(100) DEFAULT NULL,
    ADD COLUMN snipe_location_id INT UNSIGNED DEFAULT NULL,
    ADD COLUMN login_method VARCHAR(20) NOT NULL DEFAULT 'local',
    ADD UNIQUE INDEX uk_users_snipe_user_id (snipe_user_id);

-- Column descriptions:
-- snipe_user_id: Unique user ID from Snipe IT (set during OAuth login)
-- snipe_department: User's department in Snipe IT (used for role mapping: "Service Desk" -> service_desk, etc.)
-- snipe_company: User's company in Snipe IT
-- snipe_location_id: Primary location ID from Snipe IT (used for region assignment)
-- login_method: 'local' for email/password, 'snipe_oauth' for OAuth SSO
-- uk_users_snipe_user_id: Ensures each Snipe IT user maps to at most one Quacktrack user
