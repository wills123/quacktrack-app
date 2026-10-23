<?php

/**
 * Request input of api/email_helper.php (one action, create_mailto). The subject and body limits are
 * in bytes: the schema bounds the characters (a character is at least one byte, so nothing longer can
 * pass) and the service then checks the bytes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** The longest address RFC 5321 allows. */
const EMAIL_HELPER_EMAIL_MAX = 254;

/** The longest subject and body, in bytes. */
const EMAIL_HELPER_SUBJECT_MAX_BYTES = 200;
const EMAIL_HELPER_BODY_MAX_BYTES = 5000;

/**
 * @return array<string, array<string, array<string, mixed>>>
 */
function email_helper_input_schemas(): array
{
    return [
        'create_mailto' => [
            'email' => in_string(max: EMAIL_HELPER_EMAIL_MAX, required: true, label: 'Email'),
            'subject' => in_string(max: EMAIL_HELPER_SUBJECT_MAX_BYTES, required: true, label: 'Subject'),
            'body' => in_string(max: EMAIL_HELPER_BODY_MAX_BYTES, required: true, label: 'Body'),
        ],
    ];
}

/**
 * Parse the query string for $action; this endpoint only builds a URI, so it reads the query only.
 *
 * @return array<string, mixed>
 */
function email_helper_input(string $action): array
{
    static $schemas = null;
    $schemas ??= email_helper_input_schemas();
    return input_parse($schemas[$action], $_GET);
}
