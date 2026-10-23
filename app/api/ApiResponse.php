<?php

/**
 * ApiResponse
 *
 * Standardized JSON API responses.
 * Ensures consistent response format across all endpoints.
 */

class ApiResponse
{
    /**
     * Send success response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $code HTTP status code
     * @return never
     */
    public static function success($data = null, string $message = 'Success', int $code = 200): never
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => self::timestamp()
        ], $code);
    }

    /**
     * Send error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param Exception|null $exception Optional exception for debug info
     * @param array|null $data Optional response data
     * @return never
     */
    public static function error(string $message, int $code = 400, ?Exception $exception = null, ?array $data = null): never
    {
        $response = [
            'success' => false,
            'message' => $message,
            'data' => $data,
            'timestamp' => self::timestamp()
        ];
// Include debug info in development mode
        if (self::isDevelopment() && $exception !== null) {
            $response['debug'] = [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
            ];
        }

        self::send($response, $code);
    }

    /**
     * Send validation error response
     *
     * @param array $errors Validation errors (field => message pairs)
     * @param string $message General error message
     * @return never
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): never
    {
        self::send([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'timestamp' => self::timestamp()
        ], 400);
    }

    /**
     * Send a failure with any 4xx/5xx status, the message, an optional list of problems and optional extra data.
     *
     * @param list<string>|null $errors Problems shown to the user one by one
     * @param array<string, mixed>|null $data Extra data, e.g. alternative stores
     * @return never
     */
    public static function failure(string $message, int $code, ?array $errors = null, ?array $data = null): never
    {
        $response = ['success' => false, 'message' => $message, 'data' => $data, 'timestamp' => self::timestamp()];
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        self::send($response, $code);
    }

    /**
     * Send forbidden response
     *
     * @param string $message Forbidden message
     * @return never
     */
    public static function forbidden(string $message = 'Permission denied'): never
    {
        self::send([
            'success' => false,
            'message' => $message,
            'data' => null,
            'timestamp' => self::timestamp()
        ], 403);
    }

    /**
     * Send unauthorized response
     *
     * @param string $message Unauthorized message
     * @return never
     */
    public static function unauthorized(string $message = 'Authentication required'): never
    {
        self::send([
            'success' => false,
            'message' => $message,
            'data' => null,
            'timestamp' => self::timestamp()
        ], 401);
    }

    /**
     * Send rate limit exceeded response
     *
     * @param string $message Rate limit message
     * @param int|null $retryAfter Seconds until retry allowed
     * @return never
     */
    public static function rateLimitExceeded(string $message = 'Rate limit exceeded', ?int $retryAfter = null): never
    {
        $response = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'timestamp' => self::timestamp()
        ];
        if ($retryAfter !== null) {
            $response['retry_after'] = $retryAfter;
        }

        self::send($response, 429);
    }

    /**
     * Send JSON response and exit
     *
     * @param array $data Response data
     * @param int $code HTTP status code
     * @return never
     */
    private static function send(array $data, int $code): never
    {
        if (($data['success'] ?? null) === false) {
            // The documented error envelope also carries `error` and `code`; `message` stays for the existing pages.
            $data += ['error' => $data['message'] ?? '', 'code' => $code];
        }
        http_response_code($code);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Get current timestamp in ISO 8601 format
     *
     * @return string
     */
    private static function timestamp(): string
    {
        return date('c'); // ISO 8601 format
    }

    /**
     * Check if running in development mode
     *
     * @return bool
     */
    private static function isDevelopment(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'prod') !== 'prod';
    }
}
