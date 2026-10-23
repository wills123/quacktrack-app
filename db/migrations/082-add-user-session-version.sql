-- Incrementing a user's version invalidates every previously issued session,
-- including concurrent renewal aliases. A new login records the current value.
ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0;
