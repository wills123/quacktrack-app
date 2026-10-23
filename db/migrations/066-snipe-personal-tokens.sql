-- Migration: Add personal Snipe-IT OAuth token columns to users table
-- Enables per-user token storage so actions in Snipe-IT are attributed
-- to the correct individual rather than the shared service API key.

ALTER TABLE users
    ADD COLUMN snipe_access_token  TEXT         DEFAULT NULL AFTER login_method,
    ADD COLUMN snipe_refresh_token TEXT         DEFAULT NULL AFTER snipe_access_token,
    ADD COLUMN snipe_token_expires INT UNSIGNED DEFAULT NULL AFTER snipe_refresh_token,
    ADD COLUMN snipe_permissions   TEXT         DEFAULT NULL AFTER snipe_token_expires;

-- snipe_access_token:  AES-256-CBC encrypted personal OAuth access token
-- snipe_refresh_token: AES-256-CBC encrypted refresh token for silent renewal
-- snipe_token_expires: Unix timestamp when the access token expires
-- snipe_permissions:   JSON object of Snipe-IT permission flags from /users/me
