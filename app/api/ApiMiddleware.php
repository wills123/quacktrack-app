<?php

/**
 * ApiMiddleware
 *
 * Centralized middleware for API requests.
 * Handles authentication, authorization, CSRF validation, and rate limiting.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/../validation/ValidationRules.php';
require_once __DIR__ . '/../validation/ValidationException.php';

class ApiMiddleware
{
    /**
     * Authenticate user (require login)
     *
     * @return void Exits with 401 if not authenticated
     */
    public static function authenticate(): void
    {
        require_login();
    }

    /**
     * Authorize user (require specific permission)
     *
     * @param string $permission Permission identifier (e.g., 'stock.adjust')
     * @return void Exits with 403 if not authorized
     */
    public static function authorize(string $permission): void
    {
        // First ensure user is authenticated
        self::authenticate();
// Check if user has required permission
        if (!has_permission($permission)) {
            ApiResponse::forbidden('You do not have permission to perform this action');
        }
    }

    /**
     * Validate CSRF token on POST requests
     *
     * @return void Exits with 400 if CSRF validation fails
     */
    public static function csrfValidate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrf_verify_api()) {
                ApiResponse::error('Invalid or missing CSRF token', 400);
            }
        }
    }

    /**
     * Apply rate limiting
     *
     * @param string $action Action identifier (e.g., 'stock_add')
     * @param int|null $limit Max operations per window (null = use default from settings)
     * @param int|null $window Window in seconds (null = use default from settings)
     * @return void Exits with 429 if rate limit exceeded
     */
    public static function rateLimit(string $action, ?int $limit = null, ?int $window = null): void
    {
        global $current_user_id;
// Use configurable defaults if not specified
        if ($limit === null) {
            $limit = ValidationRules::getStockRateLimit();
        }
        if ($window === null) {
            $window = ValidationRules::getStockRateLimitWindow();
        }

        $rateLimit = check_rate_limit($action, $current_user_id, $limit, $window);
        if (!$rateLimit['allowed']) {
            $retryAfter = $rateLimit['retry_after'] ?? $window;
            ApiResponse::rateLimitExceeded('Rate limit exceeded. Please try again later.', $retryAfter);
        }
    }

    /**
     * Apply all standard middleware (auth, CSRF, rate limit)
     *
     * @param string $permission Required permission
     * @param string $rateLimitAction Rate limit action identifier
     * @param int|null $rateLimit Max operations per window
     * @param int|null $rateLimitWindow Window in seconds
     * @return void
     */
    public static function applyAll(
        string $permission,
        string $rateLimitAction,
        ?int $rateLimit = null,
        ?int $rateLimitWindow = null
    ): void {
        self::authenticate();
        self::authorize($permission);
        self::csrfValidate();
        self::rateLimit($rateLimitAction, $rateLimit, $rateLimitWindow);
    }
}
