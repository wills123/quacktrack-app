<?php

declare(strict_types=1);

/** @param list<string> $secrets */
function client_error_logging_redact_text(string $text, array $secrets): string
{
    foreach ($secrets as $secret) {
        if ($secret !== '') {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
    }
    $text = preg_replace('~(https?://)[^/\s@]+@~i', '$1[REDACTED]@', $text) ?? '';
    $text = preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~i', '$1?[REDACTED]', $text) ?? '';
    $text = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $text) ?? '';
    $text = preg_replace('/\b([\w-]*(?:password|secret|token|session|cookie|csrf|api[_-]?key|psess|authorization)[\w-]*)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)/i', '$1=[REDACTED]', $text) ?? '';
    // HTTP metadata is not necessarily UTF-8, even when the JSON payload is valid.
    return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
}

/** @param list<string> $secrets */
function client_error_logging_redact(mixed $value, array $secrets): mixed
{
    if (is_string($value)) {
        return client_error_logging_redact_text($value, $secrets);
    }
    if (!is_array($value)) {
        return $value;
    }
    $clean = [];
    foreach ($value as $key => $item) {
        $name = preg_replace('/[^a-z0-9]/', '', strtolower((string)$key)) ?? '';
        $safeKey = is_string($key) ? client_error_logging_redact_text($key, $secrets) : $key;
        $sensitive = preg_match('/password|secret|token|auth|cookie|session|csrf|apikey|psess|credential/', $name) === 1;
        $clean[$safeKey] = $sensitive ? '[REDACTED]' : client_error_logging_redact($item, $secrets);
    }
    return $clean;
}

/** Remove secret-bearing metadata before the shared fault logger copies it. @param list<string> $secrets */
function client_error_logging_safe_metadata(array $secrets): void
{
    foreach (['REQUEST_URI', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR'] as $name) {
        $text = (string)($_SERVER[$name] ?? '');
        if ($name === 'REQUEST_URI') {
            $text = explode('?', $text, 2)[0];
        }
        $_SERVER[$name] = mb_substr(client_error_logging_redact_text($text, $secrets), 0, 1000);
    }
}
