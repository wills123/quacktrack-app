<?php

declare(strict_types=1);

define('QUACKTRACK_NO_SESSION', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api_key_auth.php';
require_once __DIR__ . '/../../includes/api_errors.php';
require_once __DIR__ . '/../../includes/quickqueue_order_validation.php';
require_once __DIR__ . '/../../includes/external_orders_service.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** @param array<string, mixed> $body */
function quickqueue_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<string, mixed> $details */
function quickqueue_error(string $message, int $status = 400, array $details = []): never
{
    quickqueue_response([
        'success' => false,
        'error' => $message,
        'code' => $status,
        'details' => $details,
    ], $status);
}

/**
 * The decoded JSON body of a create request.
 *
 * @throws BadRequestException
 */
function quickqueue_read_json_body(): mixed
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new BadRequestException('A JSON request body is required.');
    }
    $payload = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new BadRequestException('Request body must contain valid JSON.');
    }
    return $payload;
}

/** @param array{id: int, created_by_user_id: int} $auth */
function quickqueue_handle_create(PDO $pdo, array $auth): never
{
    $body = quickqueue_read_json_body();
    $payload = quickqueue_validate_order_payload($body);
    $headerKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($headerKey !== '' && !hash_equals($headerKey, $payload['idempotency_key'])) {
        throw new BadRequestException('The Idempotency-Key header does not match the request body.');
    }
    [$status, $response] = external_orders_create($pdo, $auth, $payload, quickqueue_legacy_request_hash($body, $payload));
    quickqueue_response($response, $status);
}

/** @param array{id: int} $auth */
function quickqueue_handle_show(PDO $pdo, array $auth): never
{
    $order = external_orders_find($pdo, $auth['id'], external_orders_show_input()['order_number']);
    if ($order === null) {
        throw new NotFoundException('Order not found.');
    }
    quickqueue_response(['success' => true, 'data' => $order]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    quickqueue_error('Only GET and POST requests are allowed.', 405);
}

try {
    $auth = quacktrack_api_key_authenticate('quickqueue:orders');
    $pdo = db();
    if ($method === 'GET') {
        quickqueue_handle_show($pdo, $auth);
    }
    quickqueue_handle_create($pdo, $auth);
} catch (ClientError $exception) {
    quickqueue_error($exception->getMessage(), $exception->getHttpStatus(), $exception->getDetails());
} catch (Throwable $exception) {
    qt_rethrow_bad_input($exception);
    Logger::error('quickqueue_order_endpoint_failed', $exception);
    quickqueue_error('Unable to process the request.', 500);
}
