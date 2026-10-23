<?php

declare(strict_types=1);

require_once __DIR__ . '/OutboundHttp.php';

/**
 * Send one email through the Resend API.
 *
 * @param string               $apiKey  RESEND_API_KEY
 * @param array<string, mixed> $message from, to, subject, html, text
 * @return array{ok: bool, status: int, error: string|null} error is a log-safe summary, never the key
 */
function resend_send_email(string $apiKey, array $message): array
{
    $sent = OutboundHttp::send(
        'POST',
        'https://api.resend.com/emails',
        ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'],
        json_encode($message) ?: null,
        30
    );
    if ($sent['error'] !== null) {
        return ['ok' => false, 'status' => 0, 'error' => $sent['error']];
    }
    if ($sent['status'] !== 200) {
        // Resend explains a refusal in "message"; the raw body is not logged.
        $decoded = json_decode($sent['body'], true);
        $reason = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
        return ['ok' => false, 'status' => $sent['status'], 'error' => 'HTTP ' . $sent['status'] . ($reason !== '' ? ': ' . $reason : '')];
    }

    return ['ok' => true, 'status' => 200, 'error' => null];
}
