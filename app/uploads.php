<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/upload_delivery.php';
header('Cache-Control: private, no-store');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        throw new ClientErrorException('Method not allowed.', 405);
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!is_string($path)) {
        throw new NotFoundException('Image not found.');
    }
    $image = upload_delivery_resolve(db(), $path);
    upload_delivery_send(upload_delivery_file($image['root'], $image['relative']), $image['private'] ? 'private, no-store' : 'public, max-age=3600');
} catch (Throwable $error) {
    qt_rethrow_bad_input($error);
    http_response_code($error instanceof ClientError ? $error->getHttpStatus() : 500);
    header('Content-Type: text/plain; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $error instanceof ClientError ? $error->getMessage() : 'Unable to load image.';
    }
}
