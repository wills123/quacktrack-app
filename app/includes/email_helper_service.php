<?php

/**
 * Builds mailto: URIs for api/email_helper.php. Nothing here sends mail or contacts a provider.
 *
 * The recipient goes in the address position and the subject and body in the query, each percent-encoded
 * so that no character of user content can end a parameter (&), start the query (?) or the fragment (#),
 * or add a recipient or header of its own.
 */

declare(strict_types=1);

require_once __DIR__ . '/input_schemas/email_helper.php';

/** Control characters; a body may also hold tab and line breaks. */
const EMAIL_HELPER_SUBJECT_CONTROLS = '/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u';
const EMAIL_HELPER_BODY_CONTROLS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

/**
 * The checked recipient, subject and body of a parsed request.
 *
 * @param array<string, mixed> $input
 * @return array{email: string, subject: string, body: string}
 */
function email_helper_validate(array $input): array
{
    $email = filter_var((string)$input['email'], FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        throw new UnprocessableException('Email must be a valid address.');
    }
    $subject = (string)$input['subject'];
    $body = (string)$input['body'];
    if (preg_match(EMAIL_HELPER_SUBJECT_CONTROLS, $subject) === 1) {
        throw new UnprocessableException('Subject cannot contain line breaks or control characters.');
    }
    if (preg_match(EMAIL_HELPER_BODY_CONTROLS, $body) === 1) {
        throw new UnprocessableException('Body cannot contain control characters.');
    }
    if (strlen($subject) > EMAIL_HELPER_SUBJECT_MAX_BYTES) {
        throw new UnprocessableException('Subject can be at most ' . EMAIL_HELPER_SUBJECT_MAX_BYTES . ' bytes.');
    }
    if (strlen($body) > EMAIL_HELPER_BODY_MAX_BYTES) {
        throw new UnprocessableException('Body can be at most ' . EMAIL_HELPER_BODY_MAX_BYTES . ' bytes.');
    }
    return ['email' => $email, 'subject' => $subject, 'body' => $body];
}

/** The address as it goes after "mailto:": reserved characters are encoded, "@" and "+" stay as they are. */
function email_helper_address(string $email): string
{
    return strtr(rawurlencode($email), ['%40' => '@', '%2B' => '+']);
}

/** What JavaScript's encodeURIComponent() produces: rawurlencode() plus the marks it leaves as they are. */
function email_helper_js_component(string $text): string
{
    return strtr(rawurlencode($text), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
}

/** Line breaks become a single LF, then everything outside the unreserved set is percent-encoded. */
function email_helper_manual_component(string $text): string
{
    return rawurlencode(str_replace(["\r\n", "\r"], "\n", $text));
}

/**
 * The same message in the three encodings the endpoint has always returned.
 *
 * @return array{rawurlencode: string, encodeURIComponent: string, manual: string}
 */
function email_helper_mailto_methods(string $email, string $subject, string $body): array
{
    $prefix = 'mailto:' . email_helper_address($email) . '?subject=';
    return [
        'rawurlencode' => $prefix . rawurlencode($subject) . '&body=' . rawurlencode($body),
        'encodeURIComponent' => $prefix . email_helper_js_component($subject) . '&body=' . email_helper_js_component($body),
        'manual' => $prefix . email_helper_manual_component($subject) . '&body=' . email_helper_manual_component($body),
    ];
}
