<?php

declare(strict_types=1);

/**
 * Typed client errors and the one place an API turns an exception into an HTTP status.
 *
 * The rule: an exception that means "the client asked for something invalid, missing or not
 * allowed" is a ClientError and answers with its own 4xx status and a message that is safe to
 * show. Everything else (PDOException, RuntimeException from a service, any Error, a plain
 * Exception) is a fault: 500, an error id, logged at ERROR, message hidden outside dev.
 *
 * Only application-owned classes implement ClientError. PHP's built-in exceptions are never
 * treated as client errors, so a precondition failure inside a shared library
 * (InvalidArgumentException in AcumaticaClient, ...) cannot be mistaken for a bad request.
 */
interface ClientError extends Throwable
{
    /** HTTP status for this error (400-499). */
    public function getHttpStatus(): int;

    /** Extra structured data for the client (field names, offending items, ...). */
    public function getDetails(): array;
}

/** Base class for client errors that carry their own status. */
class ClientErrorException extends RuntimeException implements ClientError
{
    /** @var array<string, mixed> */
    public array $details;

    private int $httpStatus;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = '', int $httpStatus = 400, array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = ($httpStatus >= 400 && $httpStatus <= 499) ? $httpStatus : 400;
        $this->details = $details;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

/** 400: the request is malformed (bad id, unknown action, missing required field). */
class BadRequestException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Bad request.', array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 400, $details, $previous);
    }
}

/** 403: understood, but the caller may not do this (failed CSRF check, missing permission). */
class ForbiddenException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Forbidden.', array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 403, $details, $previous);
    }
}

/** 404: the thing asked for does not exist (or is not visible to this user). */
class NotFoundException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Not found.', array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 404, $details, $previous);
    }
}

/** 409: the request conflicts with the current state (already exists, already processed). */
class ConflictException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Conflict.', array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 409, $details, $previous);
    }
}

/** 422: well-formed, but a business rule rejects it (unsupported image type, wrong state). */
class UnprocessableException extends ClientErrorException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Unprocessable request.', array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 422, $details, $previous);
    }
}

/**
 * Decide the response for a throwable, without sending it.
 *
 * @return array{client: bool, status: int, message: string, details: array<string, mixed>}
 */
function api_classify(Throwable $e, string $publicMessage = 'Unable to complete the request. Please try again.'): array
{
    if ($e instanceof ClientError) {
        return ['client' => true, 'status' => $e->getHttpStatus(), 'message' => $e->getMessage(), 'details' => $e->getDetails()];
    }
    $message = env('APP_ENV') === 'dev' ? $e->getMessage() : $publicMessage;
    return ['client' => false, 'status' => 500, 'message' => $message, 'details' => []];
}

/**
 * Log and answer a throwable the way api_classify() decides, then stop. Use it as the body of
 * an API's catch block (after rolling back any transaction).
 *
 * @param array<string, mixed> $extra context for the log entry (action, user id, ...)
 */
function api_fail(Throwable $e, string $context, array $extra = [], string $publicMessage = 'Unable to complete the request. Please try again.'): never
{
    $response = api_classify($e, $publicMessage);
    if ($response['client']) {
        Logger::warning($context, $e->getMessage(), $extra + ['status' => $response['status'], 'error_type' => get_class($e), 'details' => $response['details']]);
        api_error($response['message'], $response['status'], $response['details']);
        // arch-ok: exit-in-library - this is the response boundary: the answer has been sent
        exit;
    }
    $errorId = uniqid('ERR-', true);
    Logger::error($context, $e, $extra + ['error_id' => $errorId, 'error_type' => get_class($e)]);
    api_error($response['message'], 500, ['error_id' => $errorId]);
    // arch-ok: exit-in-library - this is the response boundary: the answer has been sent
    exit;
}
