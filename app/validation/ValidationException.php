<?php

/**
 * ValidationException
 *
 * Exception thrown when validation fails.
 * Includes array of validation error messages.
 */

class ValidationException extends Exception
{
    private array $errors;

    /**
     * @param array $errors Array of validation error messages
     * @param int $code HTTP status code (default 400)
     */
    public function __construct(array $errors, int $code = 400)
    {
        $this->errors = $errors;
        $message = 'Validation failed: ' . implode(', ', $errors);
        parent::__construct($message, $code);
    }

    /**
     * Get all validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first validation error message
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
