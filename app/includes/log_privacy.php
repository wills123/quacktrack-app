<?php

declare(strict_types=1);

/** Keep route-level diagnostics without storing bearer tokens from query strings. */
function log_safe_uri(string $uri): string
{
    return explode('?', $uri, 2)[0];
}

/**
 * @param array<array-key, mixed> $data
 * @return array<array-key, mixed>
 */
function log_safe_context(array $data): array
{
    foreach ($data as $key => $value) {
        $name = strtolower((string)$key);
        if (preg_match('/(?:^|_)(?:password|secret|token|authorization|cookie|session_id)(?:$|_)/', $name) === 1) {
            $data[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $data[$key] = log_safe_context($value);
        } elseif (is_string($value) && in_array($name, ['request_uri', 'referer', 'url'], true)) {
            $data[$key] = log_safe_uri($value);
        }
    }
    return $data;
}

/** Create private logs from the first byte; refuse to append to an exposed file that cannot be restricted. */
function log_private_append(string $filename, string $line): int|false
{
    if (is_file($filename) && ((int)fileperms($filename) & 0007) !== 0) {
        set_error_handler(static fn(): bool => true);
        try {
            if (!chmod($filename, 0640)) {
                return false;
            }
        } finally {
            restore_error_handler();
        }
    }
    $mask = umask(0077);
    set_error_handler(static fn(): bool => true);
    try {
        return file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    } finally {
        restore_error_handler();
        umask($mask);
    }
}
