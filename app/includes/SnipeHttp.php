<?php

declare(strict_types=1);

require_once __DIR__ . '/OutboundHttp.php';

/**
 * SnipeHttp
 *
 * Snipe-IT's view of OutboundHttp (API calls, OAuth token exchange, token refresh):
 * HTTPS only, TLS verification, no redirects, timeouts and a response size cap.
 * Plain HTTP is allowed when APP_ENV=dev or SNIPE_IT_ALLOW_INSECURE_HTTP=1.
 */
final class SnipeHttp
{
    private const INSECURE_FLAG = 'SNIPE_IT_ALLOW_INSECURE_HTTP';

    /**
     * Refuse to send credentials over cleartext HTTP outside development.
     *
     * @throws RuntimeException when the URL is not an allowed Snipe-IT endpoint
     */
    public static function assertSecureUrl(string $url): void
    {
        OutboundHttp::assertSecureUrl($url, self::INSECURE_FLAG);
    }

    /**
     * @param array<int, string> $headers   Complete header lines ("Name: value")
     * @param string|null        $body      Raw request body, or null for none
     * @return array{status: int, body: string, error: string|null, retry_after: int|null, headers: array<int, string>}
     *         status 0 means the request never completed (see error)
     */
    public static function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        return OutboundHttp::send($method, $url, $headers, $body, $timeout, self::INSECURE_FLAG);
    }
}
