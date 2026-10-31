<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/purchasing_emails.php';
require_once __DIR__ . '/../includes/purchasing_email_service.php';
require_login();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$pdo = db();
$current_user_id = (int)$_SESSION['uid'];
$action = req_action();

/** @param list<string> $allowed */
function purchasing_emails_require_method(array $allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_error('Method not allowed', 405);
    }
}

/** @param array<string, mixed> $body */
function purchasing_emails_respond(array $body): void
{
    echo json_encode(['success' => true] + $body);
}

try {
    switch ($action) {
        case 'list':
            purchasing_emails_require_method(['GET', 'HEAD']);
            purchasing_emails_respond([
                'emails' => purchasing_emails_active($pdo),
                'preferred_id' => purchasing_email_preferred_id($pdo, $current_user_id),
            ]);
            break;
        case 'get_template':
            purchasing_emails_require_method(['GET', 'HEAD']);
            $emailId = purchasing_email_input('get_template')['email_id'];
            purchasing_emails_respond(['template' => purchasing_email_template($pdo, $emailId)]);
            break;
        case 'save_preference':
            purchasing_emails_require_method(['POST']);
            if (!csrf_verify_api()) {
                throw new ForbiddenException('Invalid CSRF token');
            }
            purchasing_email_save_preference($pdo, $current_user_id, purchasing_email_input('save_preference')['email_id']);
            purchasing_emails_respond([]);
            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Exception $e) {
    // api_fail() answers every ClientError itself (status, details, endpoint context), so this
    // catch does not hand them to the boundary via qt_rethrow_bad_input().
    api_fail($e, 'purchasing_emails_api_error', [
        'action' => $action,
        'user_id' => $current_user_id,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ], 'Purchasing email operation failed. Please try again.');
}
