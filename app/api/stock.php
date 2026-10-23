<?php

/**
 * Stock API Entry Point
 *
 * Routes stock management API requests to the controller. Every stock operation changes data, so only POST is
 * accepted (a GET never reaches a handler); the CSRF token is checked before anything else is read.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_login();
require_once __DIR__ . '/controllers/StockApiController.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    ApiResponse::error('Only POST requests are allowed', 405);
}
if (!csrf_verify_api()) {
    ApiResponse::error('Invalid CSRF token', 403);
}

$action = req_action();
if ($action === '') {
    ApiResponse::error('No action specified', 400);
}

$current_user_id = (int)($_SESSION['uid'] ?? 0);
if ($current_user_id === 0) {
    ApiResponse::unauthorized('User not authenticated');
}

$controller = new StockApiController(db(), $current_user_id);
$controller->handleRequest($action);
