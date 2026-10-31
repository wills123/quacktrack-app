<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/scan_product.php';
require_once __DIR__ . '/../includes/scan_product_service.php';
require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        api_error('Invalid request method', 405);
    }
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
    $input = scan_product_input($_POST);
    $barcode = $input['barcode'];
    $product = scan_product_lookup(db(), $barcode);
    if ($product === null) {
        throw new NotFoundException('Product not found for scan: ' . $barcode);
    }
    foreach (['created_at', 'modified_at'] as $field) {
        if ($product[$field]) {
            $product[$field] = date('Y-m-d H:i', strtotime($product[$field]));
        }
    }
    api_success($product, 'Product found');
} catch (Throwable $e) {
    api_fail($e, 'scan_product_api_error', [
        'user_id' => (int)$_SESSION['uid'],
    ], 'Product scan failed. Please try again.');
}
