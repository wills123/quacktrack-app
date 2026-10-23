<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/client_error_logging.php';
require_once __DIR__ . '/../includes/client_error_logging_service.php';
require_once __DIR__ . '/../includes/client_error_logging_privacy.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/** @return array{type: string, id: string} */
function client_error_auth_bucket(): array
{
    if (!empty($_SESSION['uid'])) {
        require_login();
        if (!csrf_verify_api()) {
            throw new ForbiddenException('Invalid CSRF token');
        }
        return ['type' => 'session', 'id' => (string)$_SESSION['uid']];
    }
    $expected = env('CLIENT_ERROR_API_KEY', '');
    $provided = $_SERVER['HTTP_X_CLIENT_ERROR_KEY'] ?? '';
    if ($expected !== '' && is_string($provided) && $provided !== '' && hash_equals($expected, $provided)) {
        return ['type' => 'api_key', 'id' => hash('sha256', $provided)];
    }
    throw new ClientErrorException('Authentication required for logging endpoint.', 401);
}

$secrets = array_values(array_filter([
    env('CLIENT_ERROR_API_KEY', ''), $_SESSION['csrf'] ?? '', $_COOKIE['psess'] ?? '', session_id(),
], static fn($value): bool => is_string($value) && $value !== ''));
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        api_error('Method not allowed', 405);
    }
    $auth = client_error_auth_bucket();
    if (!api_request_is_json()) {
        throw new ClientErrorException('Content-Type must be application/json.', 415);
    }
    $input = client_error_logging_input(req_json(true));
    $bucket = $auth['type'] . ':' . $auth['id'] . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $directory = env('CLIENT_ERROR_RATE_LIMIT_DIR', sys_get_temp_dir() . '/quacktrack-client-errors');
    $limit = client_error_logging_limit(env('CLIENT_ERROR_RATE_LIMIT_MAX', '60'));
    client_error_logging_rate_limit($bucket, $directory, $limit, time());
    $clean = client_error_logging_redact($input, $secrets);
    Logger::info('client_' . $input['level'], $clean['message'], [
        'stack_trace' => $clean['stack'], 'context' => $clean['context'], 'auth_type' => $auth['type'],
        'url' => client_error_logging_redact_text(mb_substr((string)($_SERVER['HTTP_REFERER'] ?? 'unknown'), 0, 1000), $secrets),
        'user_agent' => client_error_logging_redact_text(mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 1000), $secrets),
    ]);
    echo json_encode(['success' => true, 'message' => 'Error logged successfully']);
} catch (Throwable $error) {
    client_error_logging_safe_metadata($secrets);
    if ($error instanceof ClientError && $error->getHttpStatus() === 429) {
        header('Retry-After: ' . $error->getDetails()['retry_after']);
    }
    $type = get_class($error);
    $error = $error instanceof ClientError ? $error : new RuntimeException('Unable to log client error. Please try again.');
    api_fail($error, 'client_error_logging_failed', ['exception_type' => $type]);
}
