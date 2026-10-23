<?php

declare(strict_types=1);

require_once __DIR__ . '/OutboundHttp.php';

/**
 * Verify a Cloudflare Turnstile token server-side.
 *
 * The check fails closed: if Cloudflare cannot be reached or answers with something
 * unreadable, `ok` is false and `transport_error` says why, so callers can log it
 * differently from a token Cloudflare actively rejected.
 *
 * @return array{ok: bool, transport_error: string|null, error_codes: array<int, string>}
 */
function turnstile_verify(string $secretKey, string $token, string $remoteIp = ''): array
{
    $sent = OutboundHttp::send(
        'POST',
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query(['secret' => $secretKey, 'response' => $token, 'remoteip' => $remoteIp]),
        10
    );
    if ($sent['error'] !== null) {
        return ['ok' => false, 'transport_error' => $sent['error'], 'error_codes' => []];
    }

    $result = json_decode($sent['body'], true);
    if ($sent['status'] !== 200 || !is_array($result)) {
        return ['ok' => false, 'transport_error' => 'Unexpected Turnstile response (HTTP ' . $sent['status'] . ')', 'error_codes' => []];
    }
    $codes = is_array($result['error-codes'] ?? null) ? array_map('strval', $result['error-codes']) : [];

    return ['ok' => ($result['success'] ?? false) === true, 'transport_error' => null, 'error_codes' => $codes];
}
