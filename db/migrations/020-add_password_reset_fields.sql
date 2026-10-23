-- Migration: Add password reset and security fields to users table
-- Description: Adds must_reset_password flag, password_changed_at timestamp, and failed_login_attempts tracking

ALTER TABLE users
ADD COLUMN must_reset_password BOOLEAN DEFAULT 0 NOT NULL COMMENT 'Flag to force user to reset password on next login',
ADD COLUMN password_changed_at TIMESTAMP NULL COMMENT 'Timestamp of last password change',
ADD COLUMN failed_login_attempts INT DEFAULT 0 NOT NULL COMMENT 'Counter for failed login attempts',
ADD COLUMN last_failed_login TIMESTAMP NULL COMMENT 'Timestamp of last failed login attempt';

-- Add index for efficient lookup of users requiring password reset
CREATE INDEX idx_users_must_reset ON users(must_reset_password);

-- Add index for failed login tracking
CREATE INDEX idx_users_failed_logins ON users(failed_login_attempts, last_failed_login);

-- Set password_changed_at to created_at for existing users (one-time initialization)
UPDATE users SET password_changed_at = created_at WHERE password_changed_at IS NULL;
