<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/** The configured limit defaults to the existing 60 events per minute. */
function client_error_logging_limit(string $value): int
{
    $limit = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    return $limit === false ? 60 : $limit;
}

/** @return array{count: int, reset: int} */
function client_error_logging_rate_record(string $contents, int $now): array
{
    if ($contents === '') {
        return ['count' => 0, 'reset' => $now + 60];
    }
    $data = json_decode($contents, true);
    if (!is_array($data) || !is_int($data['count'] ?? null) || !is_int($data['reset'] ?? null) || $data['count'] < 0) {
        throw new RuntimeException('Client error rate limiter unavailable.');
    }
    return $data['reset'] <= $now ? ['count' => 0, 'reset' => $now + 60] : ['count' => $data['count'], 'reset' => $data['reset']];
}

/** @param resource $handle @param array{count: int, reset: int} $record */
function client_error_logging_write_rate($handle, array $record): void
{
    $json = json_encode($record, JSON_THROW_ON_ERROR);
    if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
        throw new RuntimeException('Client error rate limiter unavailable.');
    }
}

/** Only the PHP worker may access limiter state; another worker may create the directory first. */
function client_error_logging_rate_directory(string $directory): void
{
    if (is_link($directory)) {
        throw new RuntimeException('Client error rate limiter unavailable.');
    }
    if (!is_dir($directory)) {
        try {
            mkdir($directory, 0700);
        } catch (RuntimeException $error) {
            clearstatcache(true, $directory);
            if (!is_dir($directory)) {
                throw $error;
            }
        }
    }
    if ((fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('Client error rate limiter unavailable.');
    }
}

/** Serialize the check and increment; a refused event never increments the counter. */
function client_error_logging_rate_limit(string $bucket, string $directory, int $limit, int $now): void
{
    $path = rtrim($directory, '/') . '/client_error_rl_' . hash('sha256', $bucket);
    $handle = null;
    // Convert filesystem warnings to a safe fault; do not leak paths into PHP's log or the response.
    set_error_handler(static function (): never {
        throw new RuntimeException('Client error rate limiter unavailable.');
    });
    try {
        client_error_logging_rate_directory($directory);
        if (is_link($path)) {
            throw new RuntimeException('Client error rate limiter unavailable.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Client error rate limiter unavailable.');
        }
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('Client error rate limiter unavailable.');
        }
        $record = client_error_logging_rate_record($contents, $now);
        if ($record['count'] >= $limit) {
            throw new ClientErrorException('Rate limit exceeded. Please try again later.', 429, ['retry_after' => max(1, $record['reset'] - $now)]);
        }
        $record['count']++;
        client_error_logging_write_rate($handle, $record);
    } finally {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        restore_error_handler();
    }
}
