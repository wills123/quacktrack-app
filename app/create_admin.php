<?php

/**
 * Create the first administrator of a fresh installation, once, from the command line inside the PHP container:
 *
 *     docker compose exec quacktrack-php php app/create_admin.php
 *
 * It reads ADMIN_EMAIL and ADMIN_PASSWORD (12+ characters, see validate_password_strength()) from the environment
 * or .env, refuses when an administrator already exists, and the account must change its password at first sign-in.
 * Remove ADMIN_PASSWORD from .env afterwards. Exit codes: 0 created, 1 invalid input, 2 an administrator exists.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('QUACKTRACK_NO_SESSION', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/first_admin.php';

exit(create_first_admin(db(), env('ADMIN_EMAIL'), env('ADMIN_PASSWORD'), STDOUT));
