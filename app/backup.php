<?php

// backup.php
// Exports product data and images to a zip archive.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

require_login();
if (env('APP_ENV', 'dev') === 'production') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}
require_permission('admin.access');

try {
    // 1. Fetch product data
    $stmt = db()->query('SELECT * FROM products');
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Create a temporary file for the product data
    $json_file_path = tempnam(sys_get_temp_dir(), 'products_backup') . '.json';
    file_put_contents($json_file_path, json_encode($products, JSON_PRETTY_PRINT));

    // 3. Create a zip archive
    $zip = new ZipArchive();
    $backup_dir = __DIR__ . '/backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0775, true);
    }
    $zip_file_path = $backup_dir . '/backup-' . date('Y-m-d-His') . '.zip';

    if ($zip->open($zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception("Could not open archive");
    }

    // 4. Add product data to the zip
    $zip->addFile($json_file_path, 'products.json');

    // 5. Add images to the zip
    $uploads_dir = __DIR__ . '/../uploads';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = 'uploads/' . substr($filePath, strlen($uploads_dir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }

    // 6. Close the zip and clean up
    $zip->close();
    unlink($json_file_path);

    // 7. Download the zip file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zip_file_path) . '"');
    header('Content-Length: ' . filesize($zip_file_path));
    flush();
    readfile($zip_file_path);
    exit;
} catch (Exception $e) {
    qt_rethrow_bad_input($e);
    http_response_code(500);
    echo "Backup failed: " . $e->getMessage();
    exit;
}
