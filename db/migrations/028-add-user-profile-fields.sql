-- Add user profile fields for display names and usernames

ALTER TABLE users
    ADD COLUMN first_name VARCHAR(80) DEFAULT NULL AFTER email,
    ADD COLUMN last_name VARCHAR(80) DEFAULT NULL AFTER first_name,
    ADD COLUMN username VARCHAR(80) DEFAULT NULL AFTER last_name;

CREATE UNIQUE INDEX uk_users_username ON users(username);
