<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/email_helper.php';
require_once __DIR__ . '/../includes/email_helper_service.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$action = req_action();

try {
    switch ($action) {
        case 'create_mailto':
            // A read-only URI builder: no CSRF token is needed, so nothing but GET may reach it.
            if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
                header('Allow: GET, HEAD');
                api_error('Method not allowed', 405);
            }
            $message = email_helper_validate(email_helper_input('create_mailto'));
            $methods = email_helper_mailto_methods($message['email'], $message['subject'], $message['body']);
            echo json_encode([
                'success' => true,
                'methods' => $methods,
                'recommended' => $methods['rawurlencode'],
            ]);
            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Exception $e) {
    // api_fail() answers every ClientError itself (status, details, endpoint context), so this
    // catch does not hand them to the boundary via qt_rethrow_bad_input().
    api_fail($e, 'email_helper_api_error', [
        'action' => $action,
        'user_id' => $_SESSION['uid'] ?? null,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ], 'Email operation failed. Please try again.');
}
