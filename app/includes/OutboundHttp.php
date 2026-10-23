<?php

declare(strict_types=1);

/**
 * OutboundHttp
 *
 * The single place Quack Track talks cURL to another service (Snipe-IT, Acumatica,
 * QuickQueue, Cloudflare Turnstile, Resend), so transport safety is enforced once
 * instead of per call site:
 *  - HTTPS only. Plain HTTP is accepted only in development (APP_ENV=dev) or when the
 *    caller names an explicit per-service opt-out flag that is set to 1.
 *  - TLS verification always on (certificate chain and host name).
 *  - Redirects never followed: a redirect would replay credentials, including an
 *    Authorization header or session cookie, to wherever it points.
 *  - Only the HTTP(S) protocols are allowed, with connect and total timeouts and a
 *    cap on how much of a response is read.
 */
final class OutboundHttp
{
    /** Largest response body we will read (bytes). */
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;
    private const CONNECT_TIMEOUT = 5;

    /**
     * Refuse to send credentials over cleartext HTTP outside development.
     *
     * @param string $insecureFlag Name of the env flag that permits http:// for this service
     *                             (for example ACUMATICA_ALLOW_INSECURE_HTTP); '' for none
     * @throws RuntimeException when the URL is not an allowed endpoint
     */
    public static function assertSecureUrl(string $url, string $insecureFlag = ''): void
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || !in_array($scheme, ['https', 'http'], true)) {
            throw new RuntimeException('Outbound URL must be an absolute http(s) URL.');
        }
        if ($scheme === 'https' || self::insecureHttpAllowed($insecureFlag)) {
            return;
        }
        throw new RuntimeException(
            'Outbound URL must use HTTPS. Plain HTTP is only permitted when APP_ENV=dev'
            . ($insecureFlag !== '' ? ' or ' . $insecureFlag . '=1' : '') . '.'
        );
    }

    /**
     * @param array<int, string> $headers      Complete header lines ("Name: value")
     * @param string|null        $body         Raw request body, or null for none
     * @param string             $insecureFlag See assertSecureUrl()
     * @return array{status: int, body: string, error: string|null, retry_after: int|null, headers: array<int, string>}
     *         status 0 means the request never completed (see error);
     *         retry_after is the Retry-After header in seconds, when the server sent one;
     *         headers are the response header lines, trimmed
     */
    public static function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        string $insecureFlag = ''
    ): array {
        self::assertSecureUrl($url, $insecureFlag);

        $ch = curl_init();
        if ($ch === false) {
            return self::failure('curl_init() failed');
        }

        $received = '';
        $tooLarge = false;
        $retryAfter = null;
        $responseHeaders = [];
        $allowHttp = self::insecureHttpAllowed($insecureFlag);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ($allowHttp ? CURLPROTO_HTTP : 0),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$retryAfter, &$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                if (stripos($line, 'retry-after:') === 0 && ctype_digit(trim(substr($line, 12)))) {
                    $retryAfter = (int)trim(substr($line, 12));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received, &$tooLarge): int {
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0; // aborts the transfer
                }
                $received .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            return self::failure('Response exceeded the size limit');
        }
        if ($ok === false) {
            return self::failure('cURL error: ' . $curlError);
        }

        return [
            'status' => $status,
            'body' => $received,
            'error' => null,
            'retry_after' => $retryAfter,
            'headers' => $responseHeaders,
        ];
    }

    /** @return array{status: int, body: string, error: string, retry_after: null, headers: array<int, string>} */
    private static function failure(string $error): array
    {
        return ['status' => 0, 'body' => '', 'error' => $error, 'retry_after' => null, 'headers' => []];
    }

    private static function insecureHttpAllowed(string $insecureFlag): bool
    {
        return env('APP_ENV', 'prod') === 'dev'
            || ($insecureFlag !== '' && env($insecureFlag, '0') === '1');
    }
}
