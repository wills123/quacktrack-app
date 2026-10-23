<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/profile_completion.php';
require_once __DIR__ . '/../includes/profile_completion_service.php';
require_login();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
$action = '';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        api_error('Method not allowed', 405);
    }
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
    $action = req_raw_string($_POST, 'action');
    $input = profile_completion_input($action);
    $pdo = db();
    $userId = (int)$_SESSION['uid'];
    switch ($action) {
        case 'check_completion':
            echo json_encode(profile_completion_status($pdo, $userId));
            break;
        case 'complete_profile':
            $regionName = profile_completion_save($pdo, $userId, $input);
            // Commit succeeded: update this browser only after the stored profile changed.
            $_SESSION['name'] = build_user_display_name($input['first_name'], $input['last_name'], $input['username'], $_SESSION['email'] ?? '');
            $_SESSION['display_name'] = $_SESSION['name'];
            foreach (['first_name', 'last_name', 'username', 'region_id'] as $field) {
                $_SESSION[$field] = $input[$field];
            }
            $_SESSION['region'] = $regionName;
            Logger::info('profile_completed', 'User completed profile', ['user_id' => $userId]);
            echo json_encode(['success' => true, 'message' => 'Profile completed successfully!']);
            break;
    }
} catch (Throwable $error) {
    api_fail($error, 'profile_completion_api_error', [
        'action' => $action,
        'user_id' => (int)$_SESSION['uid'],
    ], 'Profile operation failed. Please try again.');
}
