<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/change_password.php';
require_once __DIR__ . '/../includes/change_password_service.php';
require_login();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$userId = (int)$_SESSION['uid'];
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        api_error('Method not allowed', 405);
    }
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
    $input = change_password_input(api_request_is_json() ? req_json(true) : $_POST);
    $changed = change_password_save(db(), $userId, $input, 'validate_password_strength', (int)$_SESSION['session_version']);
    $_SESSION['session_version'] = $changed['session_version'];
    $wasForced = $changed['was_forced'];
    Logger::security('password_changed_api', ['user_id' => $userId, 'was_forced' => $wasForced]);
    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
} catch (ChangePasswordStrengthException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $error->getMessage(), 'code' => 400] + $error->getDetails());
} catch (Throwable $error) {
    if ($error instanceof ChangePasswordCurrentException) {
        Logger::security('password_change_invalid_current_api', ['user_id' => $userId]);
    }
    // A database/extension fault can include credentials or hashes in its message. Keep only its class.
    $exceptionType = get_class($error);
    $error = $error instanceof ClientError ? $error : new RuntimeException('Unable to change password. Please try again.');
    api_fail($error, 'password_change_api_failed', ['user_id' => $userId, 'exception_type' => $exceptionType]);
}
