<?php

/**
 * Request input of api/purchasing_emails.php, one schema per action (see includes/input_schema.php).
 * list takes no input.
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** purchasing_emails.id is an INT column; a larger number can never name a row. */
const PURCHASING_EMAIL_MAX_ID = 2147483647;

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function purchasing_email_input_schemas(): array
{
    return [
        'get_template' => [
            'email_id' => in_int(min: 1, max: PURCHASING_EMAIL_MAX_ID, required: true, label: 'Purchasing email', column: 'purchasing_emails.id'),
        ],
        // 0 clears the preference; it is never a stand-in for a missing or malformed value.
        'save_preference' => [
            'email_id' => in_int(min: 0, max: PURCHASING_EMAIL_MAX_ID, required: true, label: 'Purchasing email', column: 'users.preferred_purchasing_email_id'),
        ],
    ];
}

/**
 * Parse the request for $action. save_preference changes data, so it reads the body only: a GET
 * (which carries no CSRF check) can never reach it with an id.
 *
 * @return array<string, mixed>
 */
function purchasing_email_input(string $action): array
{
    static $schemas = null;
    $schemas ??= purchasing_email_input_schemas();
    return input_parse($schemas[$action], $action === 'save_preference' ? $_POST : $_GET);
}
