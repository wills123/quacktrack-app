-- Migration: bound the lifetime of already-stored Snipe-IT OAuth tokens
--
-- Snipe-IT issues OAuth access tokens that live about 20 years, and Quack Track stored
-- that expiry as-is. New tokens are now capped at SNIPE_TOKEN_MAX_AGE (default 24 h) when
-- they are saved; this brings tokens saved before that change into line. Within an hour
-- of the next request each one is refreshed through its refresh token, which rotates the
-- credential. Safe to run more than once: LEAST() never lengthens an expiry.

UPDATE users
   SET snipe_token_expires = LEAST(snipe_token_expires, UNIX_TIMESTAMP() + 3600)
 WHERE snipe_access_token IS NOT NULL
   AND snipe_token_expires IS NOT NULL;
