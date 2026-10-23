<?php

/**
 * Validation Utilities
 *
 * Comprehensive validation and sanitization utilities for the Quack Track system.
 * This file provides additional validation patterns and utilities that complement
 * the core validation framework in config.php.
 */

require_once __DIR__ . '/config.php';

/**
 * Advanced validation patterns for specific business logic
 */
class BusinessValidator
{
    /**
     * Validate stock transaction data
     */
    public static function validateStockTransaction(array $data): ValidationResult
    {
        $validator = new Validator($data);
        $maxNoteLength = (int)get_app_setting('stock_max_note_length', '500');
        if ($maxNoteLength < 50) {
            $maxNoteLength = 50;
        }
        $maxNoteLength = min($maxNoteLength, 5000);
        return $validator
            ->rule('product_id', ['required', 'int' => [1]], 'Please select a valid product')
            ->rule('quantity', ['required', 'int' => [1, 10000]], 'Quantity must be between 1 and 10,000')
            ->rule('transaction_type', ['required', 'in' => ['add', 'use', 'adjust', 'transfer']], 'Invalid transaction type')
            ->rule('store_id', ['required', 'int' => [1]], 'Please select a valid store')
            ->rule('note', ['length' => [0, $maxNoteLength]], 'Note cannot exceed ' . $maxNoteLength . ' characters')
            ->validate();
    }

    /**
     * Validate product creation/update data
     */
    public static function validateProduct(array $data, bool $isUpdate = false): ValidationResult
    {
        $validator = new Validator($data);
        $validator
            ->rule('name', ['required', 'length' => [1, 255]], 'Product name is required and must be under 255 characters')
            ->rule('asset_type', ['length' => [0, 100]], 'Asset type cannot exceed 100 characters')
            ->rule('manufacturer', ['length' => [0, 100]], 'Manufacturer cannot exceed 100 characters')
            ->rule('status', ['in' => ['active', 'inactive', 'discontinued']], 'Status must be active, inactive, or discontinued')
            ->rule('color', ['length' => [0, 50]], 'Color cannot exceed 50 characters')
            ->rule('order_number', ['length' => [0, 100]], 'Order number cannot exceed 100 characters');
        if (!$isUpdate) {
            $validator->rule('product_id', ['required', 'product_id', 'unique' => ['products']], 'Product ID is required, must be valid format, and unique');
        }

        return $validator->validate();
    }

    /**
     * Validate user registration data
     */
    public static function validateUserRegistration(array $data): ValidationResult
    {
        $validator = new Validator($data);
        $result = $validator
            ->rule('email', ['required', 'email', 'unique' => ['users']], 'Email is required, must be valid, and unique')
            ->rule('password', ['required', 'length' => [8, 255]], 'Password must be at least 8 characters')
            ->rule('confirm', ['required'], 'Please confirm your password')
            ->rule('region_id', ['required', 'exists' => ['regions', 'id']], 'Please select a valid region')
            ->validate();
// Check password confirmation match
        if (!$result->hasErrors() && isset($data['password']) && isset($data['confirm'])) {
            if ($data['password'] !== $data['confirm']) {
                $result->addError('confirm', 'Passwords do not match');
            }
        }

        // Ensure selected region is accepting new registrations
        if (!$result->hasErrors()) {
            $sanitized = $result->getSanitizedData();
            $regionId = $sanitized['region_id'] ?? 0;
            $stmt = safe_execute_query('SELECT allow_registration FROM regions WHERE id = ?', [$regionId], 'check_region_registration');
            $allowRegistration = $stmt->fetchColumn();
            if (!$allowRegistration) {
                $result->addError('region_id', 'The selected region is not accepting registrations at this time');
            }
        }

        return $result;
    }

    /**
     * Validate order data
     */
    public static function validateOrder(array $data): ValidationResult
    {
        $validator = new Validator($data);
        $maxNoteLength = (int)get_app_setting('stock_max_note_length', '500');
        if ($maxNoteLength < 50) {
            $maxNoteLength = 50;
        }
        $maxNoteLength = min($maxNoteLength, 5000);
        return $validator
            ->rule('store_id', ['required', 'int' => [1]], 'Please select a valid store')
            ->rule('delivery_date', ['required', 'regex' => ['/^\d{4}-\d{2}-\d{2}$/']], 'Please provide a valid delivery date')
            ->rule('notes', ['length' => [0, $maxNoteLength]], 'Notes cannot exceed ' . $maxNoteLength . ' characters')
            ->validate();
    }

    /**
     * Validate file upload with business-specific rules
     */
    public static function validateProductImage(array $file): ValidationResult
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024;
// 5MB

        $result = validate_uploaded_file($file, $allowedTypes, $maxSize);
// Additional business validation
        if (!$result->hasErrors() && isset($file['tmp_name'])) {
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo) {
            // Check minimum dimensions
                if ($imageInfo[0] < 100 || $imageInfo[1] < 100) {
                    $result->addError('file', 'Image must be at least 100x100 pixels');
                }

                // Check aspect ratio (optional - for product consistency)
                $aspectRatio = $imageInfo[0] / $imageInfo[1];
                if ($aspectRatio < 0.5 || $aspectRatio > 2.0) {
                    $result->addError('file', 'Image aspect ratio should be between 1:2 and 2:1');
                }
            }
        }

        return $result;
    }
}

/**
 * Security-focused validation patterns
 */
class SecurityValidator
{
    /**
     * Validate password strength
     */
    public static function validatePasswordStrength(string $password): ValidationResult
    {
        $result = new ValidationResult();
        if (strlen($password) < 8) {
            $result->addError('password', 'Password must be at least 8 characters long');
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $result->addError('password', 'Password must contain at least one uppercase letter');
        }

        if (!preg_match('/[a-z]/', $password)) {
            $result->addError('password', 'Password must contain at least one lowercase letter');
        }

        if (!preg_match('/[0-9]/', $password)) {
            $result->addError('password', 'Password must contain at least one number');
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $result->addError('password', 'Password must contain at least one special character');
        }

        // Check for common weak passwords
        $weakPasswords = [
            'password', '12345678', 'qwerty123', 'admin123', 'password123',
            'welcome123', 'changeme', 'letmein', '123456789', 'password1'
        ];
        if (in_array(strtolower($password), $weakPasswords, true)) {
            $result->addError('password', 'Password is too common. Please choose a stronger password');
        }

        if (!$result->hasErrors()) {
            $result->setSanitizedValue('password', $password);
        }

        return $result;
    }

    /**
     * Validate session token for API requests
     */
    public static function validateApiToken(): bool
    {
        $token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_POST['api_token'] ?? '';
        if (empty($token)) {
            return false;
        }

        // For now, just validate CSRF token
        // In a full implementation, you might have separate API tokens
        return hash_equals($_SESSION['csrf'] ?? '', $token);
    }

    /**
     * Validate IP address for rate limiting
     */
    public static function validateRateLimit(string $identifier, int $maxAttempts = 10, int $timeWindow = 3600): bool
    {
        // Simple rate limiting using session storage
        // In production, use Redis or database

        if (!isset($_SESSION['rate_limits'])) {
            $_SESSION['rate_limits'] = [];
        }

        $key = 'rate_limit_' . hash('sha256', $identifier);
        $now = time();
// Clean old attempts
        if (isset($_SESSION['rate_limits'][$key])) {
            $_SESSION['rate_limits'][$key] = array_filter($_SESSION['rate_limits'][$key], function ($timestamp) use ($now, $timeWindow) {

                    return ($now - $timestamp) < $timeWindow;
            });
        } else {
            $_SESSION['rate_limits'][$key] = [];
        }

        // Check if limit exceeded
        if (count($_SESSION['rate_limits'][$key]) >= $maxAttempts) {
            Logger::security('rate_limit_exceeded', [
                'identifier' => $identifier,
                'attempts' => count($_SESSION['rate_limits'][$key]),
                'max_attempts' => $maxAttempts,
                'time_window' => $timeWindow
            ]);
            return false;
        }

        // Record this attempt
        $_SESSION['rate_limits'][$key][] = $now;
        return true;
    }
}

/**
 * Data transformation and normalization utilities
 */
class DataNormalizer
{
    /**
     * Normalize phone number format
     */
    public static function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
// Add country code if missing (assuming US)
        if (strlen($phone) === 10) {
            $phone = '1' . $phone;
        }

        return $phone;
    }

    /**
     * Normalize product ID format
     */
    public static function normalizeProductId(string $productId): string
    {
        // Convert to uppercase and replace spaces with underscores
        $normalized = strtoupper(trim($productId));
        $normalized = preg_replace('/\s+/', '_', $normalized);
        $normalized = preg_replace('/[^A-Z0-9_-]/', '', $normalized);
        return $normalized;
    }

    /**
     * Normalize currency amount
     */
    public static function normalizeCurrency(string $amount): float
    {
        // Remove currency symbols and spaces
        $amount = preg_replace('/[^0-9.-]/', '', $amount);
        return round((float)$amount, 2);
    }

    /**
     * Normalize date format
     */
    public static function normalizeDate(string $date): ?string
    {
        try {
            $dateObj = new DateTime($date);
            return $dateObj->format('Y-m-d');
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            return null;
        }
    }

    /**
     * Normalize boolean values from form inputs
     */
    public static function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool)$value;
    }
}

/**
 * Batch validation utilities for processing multiple records
 */
class BatchValidator
{
    /**
     * Validate CSV import data
     */
    public static function validateCsvImport(array $rows, array $requiredColumns): ValidationResult
    {
        $result = new ValidationResult();
        if (empty($rows)) {
            $result->addError('csv', 'CSV file is empty');
            return $result;
        }

        // Check if all required columns are present
        $firstRow = $rows[0] ?? [];
        $missingColumns = array_diff($requiredColumns, array_keys($firstRow));
        if (!empty($missingColumns)) {
            $result->addError('csv', 'Missing required columns: ' . implode(', ', $missingColumns));
            return $result;
        }

        // Validate each row
        $validRows = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            $rowErrors = [];
        // Basic validation for each required column
            foreach ($requiredColumns as $column) {
                if (!isset($row[$column]) || trim($row[$column]) === '') {
                    $rowErrors[] = "Row {$rowNumber}: {$column} is required";
                }
            }

            if (empty($rowErrors)) {
                $validRows[] = $row;
            } else {
                $errors = array_merge($errors, $rowErrors);
            }
        }

        if (!empty($errors)) {
            $result->addError('csv', implode('; ', $errors));
        } else {
            $result->setSanitizedValue('rows', $validRows);
        }

        return $result;
    }

    /**
     * Validate bulk stock update
     */
    public static function validateBulkStockUpdate(array $updates): ValidationResult
    {
        $result = new ValidationResult();
        $validUpdates = [];
        $errors = [];
        foreach ($updates as $index => $update) {
            $updateResult = BusinessValidator::validateStockTransaction($update);
            if ($updateResult->hasErrors()) {
                foreach ($updateResult->getErrors() as $field => $message) {
                    $errors[] = "Update " . ($index + 1) . ": {$message}";
                }
            } else {
                $validUpdates[] = $updateResult->getSanitizedData();
            }
        }

        if (!empty($errors)) {
            $result->addError('bulk_update', implode('; ', $errors));
        } else {
            $result->setSanitizedValue('updates', $validUpdates);
        }

        return $result;
    }
}

/**
 * Context-aware validation that considers user permissions and business rules
 */
class ContextValidator
{
    /**
     * Validate operation based on user context
     */
    public static function validateUserOperation(string $operation, array $data, int $userId): ValidationResult
    {
        $result = new ValidationResult();
// Check if user has permission for this operation
        if (!has_permission($operation)) {
            $result->addError('permission', 'You do not have permission to perform this operation');
            return $result;
        }

        // Context-specific validations
        switch ($operation) {
            case 'stock.adjust':
                // Only elevated roles can adjust stock outside their region

                                                                                                                                                                                                                                                                                                                                                                       $can_adjust_other_regions = has_permission('system.full_access')
                    || has_permission('admin.access')
                    || has_permission('van_stock.transfer')
                    || has_permission('stock.transfer')
                    || has_permission('region_storage.transfer_region');
                if (!$can_adjust_other_regions && isset($data['region_id'])) {
                    $userRegion = $_SESSION['region_id'] ?? 0;
                    if ((int)$data['region_id'] !== (int)$userRegion) {
                            $result->addError('region', 'You can only adjust stock for your own region');
                    }
                }

                break;
            case 'orders.create':
                // Validate order limits based on capabilities (basic techs only)

                                                                                                                                                                                                                                                                                                                                                                       $isElevated = has_permission('orders.view_region') || has_permission('orders.view_all') || has_permission('orders.manage');
                if (!$isElevated && isset($data['total_amount'])) {
                    $maxOrderAmount = 10000;
        // $10,000 limit for basic users
                    if ($data['total_amount'] > $maxOrderAmount) {
                        $result->addError('amount', 'Order amount exceeds your authorization limit');
                    }
                }

                break;
            case 'products.create':
                if (!has_permission('products.create')) {
                    $result->addError('permission', 'You do not have permission to create products');
                }

                break;
        }

        return $result;
    }

    /**
     * Validate data access based on user context
     */
    public static function validateDataAccess(string $resourceType, int $resourceId, int $userId): bool
    {
        try {
            switch ($resourceType) {
                case 'order':
                                    $can_view_all_orders = has_permission('system.full_access')
                        || has_permission('admin.access')
                        || has_permission('orders.view_all')
                        || has_permission('orders.manage');
                    if ($can_view_all_orders) {
                            return true;
                    }

                    $stmt = safe_execute_query('SELECT po.user_id, u.region_id AS owner_region_id
                         FROM purchase_orders po
                         LEFT JOIN users u ON po.user_id = u.id
                         WHERE po.id = ?', [$resourceId], 'order_access_check');
                    $order = $stmt->fetch();
                    if (!$order) {
                                    return false;
                    }

                    if ((int)$order['user_id'] === (int)$userId) {
                        return true;
                    }

                    $can_view_region_orders = has_permission('orders.view_region');
                    $userRegion = $_SESSION['region_id'] ?? null;

                    return $can_view_region_orders
                        && $userRegion !== null
                        && (int)$order['owner_region_id'] === (int)$userRegion;
                case 'stock':
                            $can_view_all_stock = has_permission('system.full_access')
                    || has_permission('admin.access')
                    || has_permission('van_stock.transactions.view');
                    if ($can_view_all_stock) {
                        return true;
                    }

                    $stmt = safe_execute_query('SELECT vs.user_id, u.region_id AS owner_region_id
                         FROM van_stock vs
                         JOIN users u ON vs.user_id = u.id
                         WHERE vs.id = ?', [$resourceId], 'stock_access_check');
                    $stock = $stmt->fetch();
                    if (!$stock) {
                        return false;
                    }

                    if ((int)$stock['user_id'] === (int)$userId) {
                        return true;
                    }

                    $userRegion = $_SESSION['region_id'] ?? null;
                    $can_view_region_stock = has_permission('van_stock.transfer')
                    || has_permission('region_storage.transfer_region');


                    return $can_view_region_stock
                        && $userRegion !== null
                        && (int)$stock['owner_region_id'] === (int)$userRegion;
                default:
                            // Default to elevated access for unknown resource types


                    return has_permission('system.full_access') || has_permission('admin.access');
            }
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            Logger::error('data_access_validation_failed', $e, [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'user_id' => $userId
            ]);
            return false;
        }
    }
}

/**
 * Validation result aggregator for complex operations
 */
class ValidationAggregator
{
    private array $results = [];

    public function addResult(string $context, ValidationResult $result): void
    {
        $this->results[$context] = $result;
    }

    public function hasErrors(): bool
    {
        foreach ($this->results as $result) {
            if ($result->hasErrors()) {
                return true;
            }
        }
        return false;
    }

    public function getAllErrors(): array
    {
        $allErrors = [];
        foreach ($this->results as $context => $result) {
            if ($result->hasErrors()) {
                foreach ($result->getErrors() as $field => $message) {
                    $allErrors["{$context}.{$field}"] = $message;
                }
            }
        }

        return $allErrors;
    }

    public function getAllSanitizedData(): array
    {
        $allData = [];
        foreach ($this->results as $context => $result) {
            if (!$result->hasErrors()) {
                $allData[$context] = $result->getSanitizedData();
            }
        }

        return $allData;
    }

    public function getResult(string $context): ?ValidationResult
    {
        return $this->results[$context] ?? null;
    }
}

/**
 * Helper functions for common validation patterns
 */

/**
 * Validate and process a complete form submission with CSRF and authentication
 */
function validate_secure_form(array $rules, string $fallbackUrl = '/index.php', bool $requireAdmin = false): array
{
    // Security checks
    require_login();
    if ($requireAdmin) {
        require_permission('settings.manage');
    }
    validate_csrf_token();
// Validate form data
    $result = validate_form_data($_POST, $rules);
    handle_validation_result($result, $fallbackUrl);
    return get_validated_data($result);
}

/**
 * Validate API request with rate limiting
 */
function validate_api_request(array $rules, int $rateLimit = 60): array
{
    // Rate limiting
    $identifier = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SESSION['uid'] ?? 'anonymous');
    if (!SecurityValidator::validateRateLimit($identifier, $rateLimit, 3600)) {
        api_error('Rate limit exceeded. Please try again later.', 429);
    }

    // Authentication and CSRF
    require_login();
    validate_csrf_token();
// Validate input
    return api_validate_input($rules);
}

/**
 * Validate file upload with security checks
 */
function validate_secure_file_upload(string $fieldName, string $uploadDir = 'uploads', array $allowedTypes = ['image/jpeg', 'image/png']): array
{
    require_login();
    validate_csrf_token();
    $result = validate_file_upload($fieldName, $allowedTypes);
    if ($result->hasErrors()) {
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
        if ($isApi) {
            api_error('File upload validation failed', 400, ['errors' => $result->getErrors()]);
        } else {
            foreach ($result->getErrors() as $message) {
                flash_message($message, 'error');
            }
            redirect('/index.php');
        }
    }

    return $_FILES[$fieldName];
}
