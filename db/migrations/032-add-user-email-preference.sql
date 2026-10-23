-- Migration: Add user preference for last-used purchasing email
-- Date: 2025-12-30
-- Description: Remember user's last selected purchasing email for faster repeat orders

ALTER TABLE users
ADD COLUMN preferred_purchasing_email_id INT NULL
    COMMENT 'Last selected purchasing email (remembered preference)'
    AFTER region_id;

-- Add foreign key constraint with ON DELETE SET NULL
-- If purchasing email is deleted, preference is cleared automatically
ALTER TABLE users
ADD CONSTRAINT fk_users_preferred_purchasing_email
    FOREIGN KEY (preferred_purchasing_email_id)
    REFERENCES purchasing_emails(id)
    ON DELETE SET NULL;

-- Add index for performance when joining with purchasing_emails
CREATE INDEX idx_users_preferred_purchasing_email ON users(preferred_purchasing_email_id);
