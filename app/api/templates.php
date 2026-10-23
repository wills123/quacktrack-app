<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/templates.php';
require_once __DIR__ . '/../includes/order_template_service.php';
require_once __DIR__ . '/../includes/checkout_support.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$action = '';
try {
    $action = req_action();
    if (!array_key_exists($action, templates_input_schemas())) {
        throw new BadRequestException('Invalid action');
    }
    $method = $action === 'list' ? 'GET' : 'POST';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        api_error('Method not allowed', 405);
    }
    if ($method === 'POST' && !csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
    $input = templates_input($action);
    $pdo = db();
    $userId = (int)$_SESSION['uid'];
    if ($action === 'list') {
        echo json_encode(order_template_list($pdo, $userId));
        return;
    }
    // Template writes share checkout's existing per-user DB lock; changing a cart must not race its checkout.
    checkout_acquire_user_lock($pdo, $userId);
    try {
        $pdo->beginTransaction();
        $response = match ($action) {
            'create' => order_template_create($pdo, $userId, $input),
            'apply' => order_template_apply($pdo, $userId, $input['template_id']),
            'delete' => order_template_delete($pdo, $userId, $input['template_id']),
        };
        $pdo->commit();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        checkout_release_user_lock($pdo, $userId);
    }
    echo json_encode($response);
} catch (Throwable $e) {
    api_fail($e, 'templates_api_error', [
        'action' => $action,
        'user_id' => (int)$_SESSION['uid'],
    ], 'Template operation failed. Please try again.');
}
