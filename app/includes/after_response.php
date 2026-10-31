<?php

/**
 * Work that must not change how long a response takes (audit findings H3 and H4). Sending an email takes a network
 * round trip, so a page that sends one only for an existing account answers measurably slower for it. These helpers
 * finish the response first (PHP-FPM) and then do the work, so the answer's timing is the same either way.
 */

declare(strict_types=1);

/**
 * Run $task after the response has been sent: the session is saved and the client released first.
 *
 * @param callable(): mixed $task
 */
function run_after_response(callable $task): void
{
    register_shutdown_function(static function () use ($task): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $task();
        } catch (Throwable $e) {
            Logger::error('after_response_task_failed', $e);
        }
    });
}

/** Send a short plain-text notice about the account at $email (no link, no secret). Returns whether it was sent. */
function send_account_notice_email(string $email, string $subject, string $text): bool
{
    $apiKey = env('RESEND_API_KEY');
    if ($apiKey === '') {
        Logger::info('account_notice_not_sent', 'RESEND_API_KEY is not configured', ['subject' => $subject]);
        return false;
    }
    $from = env('FROM_NAME', 'Quack Track') . ' <' . env('FROM_EMAIL', 'noreply@quacktrack.com') . '>';
    $sent = resend_send_email($apiKey, ['from' => $from, 'to' => [$email], 'subject' => $subject, 'text' => $text]);
    if (!$sent['ok']) {
        Logger::info('account_notice_failed', 'Account notice email failed', ['subject' => $subject]);
    }
    return $sent['ok'];
}
