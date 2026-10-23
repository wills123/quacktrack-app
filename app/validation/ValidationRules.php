<?php

/**
 * ValidationRules
 *
 * Reusable validation rules for input data.
 * Throws ValidationException on failure.
 *
 * Uses configurable limits from app_settings for stock operations.
 */

class ValidationRules
{
    /**
     * Get configurable limits from app_settings with fallback defaults
     *
     * @param string $settingKey Setting key in app_settings table
     * @param int $default Default value if setting not found
     * @return int
     */
    private static function getLimit(string $settingKey, int $default): int
    {
        return (int)get_app_setting($settingKey, (string)$default);
    }

    /**
     * Validate field is not empty
     *
     * @param mixed $value Value to check
     * @param string $fieldName Field name for error message
     * @return bool
     * @throws ValidationException
     */
    public static function required($value, string $fieldName): bool
    {
        if (empty($value) && $value !== '0' && $value !== 0) {
            throw new ValidationException(["$fieldName is required"]);
        }
        return true;
    }

    /**
     * Validate value is an integer
     *
     * @param mixed $value Value to validate
     * @param string $fieldName Field name for error message
     * @return int Validated integer value
     * @throws ValidationException
     */
    public static function integer($value, string $fieldName): int
    {
        if (!is_numeric($value) || (int)$value != $value) {
            throw new ValidationException(["$fieldName must be an integer"]);
        }
        return (int)$value;
    }

    /**
     * Validate integer is at least minimum value
     *
     * @param int $value Value to check
     * @param int $min Minimum allowed value
     * @param string $fieldName Field name for error message
     * @return bool
     * @throws ValidationException
     */
    public static function min(int $value, int $min, string $fieldName): bool
    {
        if ($value < $min) {
            throw new ValidationException(["$fieldName must be at least $min"]);
        }
        return true;
    }

    /**
     * Validate integer does not exceed maximum value
     *
     * @param int $value Value to check
     * @param int $max Maximum allowed value
     * @param string $fieldName Field name for error message
     * @return bool
     * @throws ValidationException
     */
    public static function max(int $value, int $max, string $fieldName): bool
    {
        if ($value > $max) {
            $message = "$fieldName cannot exceed $max";
// Add helpful hint for configurable limits
            if (in_array($fieldName, ['Quantity'])) {
                $message .= ". For larger operations, contact your administrator.";
            }
            throw new ValidationException([$message]);
        }
        return true;
    }

    /**
     * Validate string length does not exceed maximum
     *
     * @param string $value String to check
     * @param int $max Maximum length
     * @param string $fieldName Field name for error message
     * @return bool
     * @throws ValidationException
     */
    public static function maxLength(string $value, int $max, string $fieldName): bool
    {
        if (strlen($value) > $max) {
            $currentLength = strlen($value);
            $message = "$fieldName cannot exceed $max characters (currently $currentLength)";
            if ($fieldName === 'Note') {
                $message .= ". Please shorten your note or contact your administrator.";
            }
            throw new ValidationException([$message]);
        }
        return true;
    }

    /**
     * Validate value is in allowed array (whitelist)
     *
     * @param mixed $value Value to check
     * @param array $allowed Array of allowed values
     * @param string $fieldName Field name for error message
     * @return bool
     * @throws ValidationException
     */
    public static function inArray($value, array $allowed, string $fieldName): bool
    {
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException(["Invalid $fieldName"]);
        }
        return true;
    }

    // ========================================
    // Configurable limits for stock operations
    // ========================================

    /**
     * Get maximum stock quantity per operation (configurable)
     * Default: 10,000
     *
     * @return int
     */
    public static function getMaxStockQuantity(): int
    {
        return self::getLimit('stock_max_quantity', 10000);
    }

    /**
     * Get maximum note length for stock operations (configurable)
     * Default: 500 characters
     *
     * @return int
     */
    public static function getMaxNoteLength(): int
    {
        return self::getLimit('stock_max_note_length', 500);
    }

    /**
     * Get rate limit for stock operations (configurable)
     * Default: 50 operations per window
     *
     * @return int
     */
    public static function getStockRateLimit(): int
    {
        return self::getLimit('stock_rate_limit_per_minute', 50);
    }

    /**
     * Get rate limit window in seconds (configurable)
     * Default: 60 seconds (1 minute)
     *
     * @return int
     */
    public static function getStockRateLimitWindow(): int
    {
        return self::getLimit('stock_rate_limit_window_seconds', 60);
    }
}
