<?php

require_once 'config.php';
require_login();
$pdo = db();
$current_user_id = $_SESSION['uid'];
$max_note_length = (int)get_app_setting('stock_max_note_length', '500');
if ($max_note_length < 50) {
    $max_note_length = 50;
}
$max_note_length = min($max_note_length, 5000);
if (!defined('PRODUCT_IMAGE_MAX_BYTES')) {
    define('PRODUCT_IMAGE_MAX_BYTES', 5 * 1024 * 1024); // 5MB
}
if (!defined('PRODUCT_IMPORT_MAX_BYTES')) {
    define('PRODUCT_IMPORT_MAX_BYTES', 10 * 1024 * 1024); // 10MB
}

function validate_product_import_file(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Product import upload failed.');
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > PRODUCT_IMPORT_MAX_BYTES) {
        throw new RuntimeException('Product import file is empty or too large.');
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid product import upload.');
    }
    $extension = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
        throw new RuntimeException('Only .xls and .xlsx product imports are allowed.');
    }
    $readerType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($tmpName);
    $expectedType = $extension === 'xls' ? 'Xls' : 'Xlsx';
    if ($readerType !== $expectedType) {
        throw new RuntimeException('Product import file type does not match its extension.');
    }
    return $tmpName;
}

function product_image_mime_map(): array
{

    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

function log_products_exception(Throwable $e, string $context): void
{

    if (class_exists('Logger')) {
        Logger::error($context, $e);
    } else {
        error_log($context . ': ' . $e->getMessage());
    }
}

function normalize_barcode(string $barcode): string
{

    return str_replace(':', '', $barcode);
}

function save_product_image_binary(string $binary, string $uploadDir): string
{

    $size = strlen($binary);
    if ($size === 0) {
        throw new RuntimeException('Image data is empty.');
    }
    if ($size > PRODUCT_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Image is too large. Maximum size is 5MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->buffer($binary);
    $allowed = product_image_mime_map();
    if (!isset($allowed[$detectedMime])) {
        throw new RuntimeException('Only JPEG, PNG, GIF, or WebP images are allowed.');
    }
    $extension = $allowed[$detectedMime];
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    try {
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        throw new RuntimeException('Failed to generate image filename.');
    }
    $targetPath = rtrim($uploadDir, '/') . '/' . $filename;
    if (@file_put_contents($targetPath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Failed to store uploaded image.');
    }
    @chmod($targetPath, 0644);
    return '/uploads/' . $filename;
}

function process_uploaded_product_image(array $file, string $uploadDir): string
{

    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }

    $size = $file['size'] ?? 0;
    if ($size <= 0) {
        throw new RuntimeException('Uploaded image is empty.');
    }
    if ($size > PRODUCT_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Image is too large. Maximum size is 5MB.');
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid image upload source.');
    }

    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        throw new RuntimeException('Failed to read uploaded image.');
    }

    return save_product_image_binary($binary, $uploadDir);
}

function process_captured_product_image(string $data, string $uploadDir): string
{

    $binary = base64_decode($data, true);
    if ($binary === false) {
        throw new RuntimeException('Captured image data is invalid.');
    }

    return save_product_image_binary($binary, $uploadDir);
}

// Handle add product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_verify();
    require_permission('products.create');
// Basic validation
    $product_id = req_string($_POST, 'product_id');
    $barcode = req_string($_POST, 'barcode');
    $name = req_string($_POST, 'name');
    $asset_type = req_string($_POST, 'asset_type');
    $manufacturer = req_string($_POST, 'manufacturer');
    $status = req_string($_POST, 'status', 'active');
    $color = req_string($_POST, 'color');
    $order_number = req_string($_POST, 'order_number');
    $purchase_url = req_string($_POST, 'purchase_url');
    $is_tracked_asset = isset($_POST['is_tracked_asset']) ? 1 : 0;
    $errors = [];
    if (!empty($purchase_url) && !filter_var($purchase_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Purchase URL must be a valid URL (e.g., https://example.com)';
    }

    if (empty($product_id)) {
        $errors[] = 'Product ID is required';
    }
    if (empty($name)) {
        $errors[] = 'Product name is required';
    }

    // Normalize barcode default from SKU when not provided.
    if ($barcode === '' && $product_id !== '') {
        $barcode = normalize_barcode($product_id);
    }

    if ($barcode !== '' && !preg_match('/^[a-zA-Z0-9\:_-]+$/', $barcode)) {
        $errors[] = 'Barcode must be alphanumeric (dashes, underscores, and colons allowed) and up to 100 characters.';
    }
    if (strlen($barcode) > 100) {
        $errors[] = 'Barcode must be 100 characters or fewer.';
    }

    $normalized_barcode = $barcode !== '' ? normalize_barcode($barcode) : '';
    if ($barcode !== '' && $normalized_barcode === '') {
        $errors[] = 'Barcode must include at least one non-colon character.';
    }

    // Check if product ID already exists
    if (!empty($product_id)) {
        $stmt = $pdo->prepare('SELECT id FROM products WHERE product_id = ?');
        $stmt->execute([$product_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Product ID already exists';
        }
    }

    // Prevent duplicate barcodes (scanner lookups would become ambiguous).
    if ($normalized_barcode !== '') {
        try {
            $stmt = $pdo->prepare("SELECT id FROM products WHERE REPLACE(barcode, ':', '') = ? LIMIT 1");
            $stmt->execute([$normalized_barcode]);
            if ($stmt->fetch()) {
                $errors[] = 'Barcode already exists on another product';
            }
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::info('barcode_check_unavailable', 'Skipping duplicate barcode check because the schema is incomplete.', [
                'error_type' => get_class($e),
            ]);
        }
    }

    $imagePath = null;
    $uploadDir = '/var/www/uploads/';
    if (empty($errors)) {
    // Prefer captured image data (from in-browser camera capture) if provided
        $capturedDataUrl = req_string($_POST, 'captured_image_data');
        if ($capturedDataUrl !== '' && str_starts_with($capturedDataUrl, 'data:image/')) {
            if (preg_match('/^data:(image\/[a-z0-9+.-]+);base64,(.*)$/i', $capturedDataUrl, $matches)) {
                try {
                    $imagePath = process_captured_product_image($matches[2], $uploadDir);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            } else {
                $errors[] = 'Captured image format is invalid.';
            }
        }

        // Fallback to traditional file upload when no captured image
        if ($imagePath === null && !empty($_FILES['image']['name'])) {
            try {
                $imagePath = process_uploaded_product_image($_FILES['image'], $uploadDir);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
        // Insert product
            $stmt = $pdo->prepare('INSERT INTO products (product_id, barcode, name, user_id, image_path, asset_type, manufacturer, status, color, order_number, purchase_url, is_tracked_asset) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $product_id,
                $normalized_barcode !== '' ? $normalized_barcode : null,
                $name,
                $current_user_id,
                $imagePath,
                $asset_type ?: null,
                $manufacturer ?: null,
                $status,
                $color ?: null,
                $order_number ?: null,
                $purchase_url ?: null,
                $is_tracked_asset
            ]);
            $productDbId = $pdo->lastInsertId();
        // Create initial van stock entry
                    $stmt = $pdo->prepare('INSERT INTO van_stock (product_id, user_id, store_id, quantity) VALUES (?, ?, 1, 0)');
            $stmt->execute([$productDbId, $current_user_id]);
            $pdo->commit();
            flash('Product added successfully', 'success');
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            $pdo->rollBack();
            log_products_exception($e, 'product_add_failed');
            $errors[] = 'Failed to create product. Please try again.';
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            flash($error, 'error');
        }
    } else {
        header('Location: /products');
        exit;
    }
}

// Handle bulk import (simplified)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_verify();
    require_permission('products.bulk_import');
    if (!empty($_FILES['products_file']['name'])) {
        try {
            $importPath = validate_product_import_file($_FILES['products_file']);
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(
                \PhpOffice\PhpSpreadsheet\IOFactory::identify($importPath)
            );
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($importPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            $importedCount = 0;
            $skippedCount = 0;
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO products (product_id, barcode, name, user_id, asset_type, manufacturer, status, color, order_number, purchase_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $vanStockStmt = $pdo->prepare('INSERT INTO van_stock (product_id, user_id, store_id, quantity) VALUES (?, ?, 1, 0)');

            for ($row = 2; $row <= $highestRow; ++$row) {
                $product_id = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
                $name = trim($worksheet->getCell('D' . $row)->getValue() ?? '');
                if (!empty($product_id) && !empty($name)) {
                    try {
                        $stmt->execute([
                            $product_id,
                            str_replace(':', '', $product_id),
                            $name,
                            $current_user_id,
                            trim($worksheet->getCell('E' . $row)->getValue() ?? '') ?: null,
                            trim($worksheet->getCell('F' . $row)->getValue() ?? '') ?: null,
                            trim($worksheet->getCell('H' . $row)->getValue() ?? '') ?: 'active',
                            trim($worksheet->getCell('L' . $row)->getValue() ?? '') ?: null,
                            trim($worksheet->getCell('M' . $row)->getValue() ?? '') ?: null,
                            trim($worksheet->getCell('N' . $row)->getValue() ?? '') ?: null
                        ]);
                        $prodId = $pdo->lastInsertId();
                        $vanStockStmt->execute([$prodId, $current_user_id]);
                        $importedCount++;
                    } catch (PDOException $e) {
                        $skippedCount++;
                    }
                }
            }

            $pdo->commit();
            flash("Import completed: {$importedCount} products imported, {$skippedCount} skipped", 'success');
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            $pdo->rollBack();
            log_products_exception($e, 'product_import_failed');
            flash('Import failed. Please verify the file and try again.', 'error');
        }
    }

    header('Location: /products');
    exit;
}

// Handle product edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    csrf_verify();
    require_permission('products.edit');
    $id = req_int($_POST, 'id');
    $product_id = req_string($_POST, 'product_id');
    $barcode = req_string($_POST, 'barcode');
    $name = req_string($_POST, 'name');
    $asset_type = req_string($_POST, 'asset_type');
    $manufacturer = req_string($_POST, 'manufacturer');
    $status = req_string($_POST, 'status', 'active');
    $color = req_string($_POST, 'color');
    $order_number = req_string($_POST, 'order_number');
    $purchase_url = req_string($_POST, 'purchase_url');
    $acumatica_inventory_id = req_string($_POST, 'acumatica_inventory_id');
    $acumatica_default_vendor_id = req_string($_POST, 'acumatica_default_vendor_id');
    $is_tracked_asset = isset($_POST['is_tracked_asset']) ? 1 : 0;
    if (!empty($purchase_url) && !filter_var($purchase_url, FILTER_VALIDATE_URL)) {
        flash('Purchase URL must be a valid URL.', 'error');
        header('Location: /products');
        exit;
    }

    if ($id > 0 && !empty($product_id) && !empty($name)) {
        try {
        // Normalize barcode default from SKU when not provided.
            if ($barcode === '') {
                $barcode = normalize_barcode($product_id);
            }

            if ($barcode !== '' && (strlen($barcode) > 100 || !preg_match('/^[a-zA-Z0-9\:_-]+$/', $barcode))) {
                flash('Barcode must be alphanumeric (dashes, underscores, and colons allowed) and up to 100 characters.', 'error');
                header('Location: /products');
                exit;
            }

            $normalized_barcode = $barcode !== '' ? normalize_barcode($barcode) : '';
            if ($barcode !== '' && $normalized_barcode === '') {
                flash('Barcode must include at least one non-colon character.', 'error');
                header('Location: /products');
                exit;
            }

            // Prevent duplicate barcodes (scanner lookups would become ambiguous).
            if ($normalized_barcode !== '') {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM products WHERE REPLACE(barcode, ':', '') = ? AND id <> ? LIMIT 1");
                    $stmt->execute([$normalized_barcode, $id]);
                    if ($stmt->fetch()) {
                        flash('Barcode already exists on another product', 'error');
                        header('Location: /products');
                        exit;
                    }
                } catch (Throwable $e) {
                    qt_rethrow_bad_input($e);
                    Logger::info('barcode_check_unavailable', 'Skipping duplicate barcode check because the schema is incomplete.', [
                        'error_type' => get_class($e),
                    ]);
                }
            }

            // Get current image path
            $stmt = $pdo->prepare('SELECT image_path FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $currentProduct = $stmt->fetch();
            $imagePath = $currentProduct['image_path'] ?? null;
        // Handle new image upload
            if (!empty($_FILES['image']['name'])) {
                try {
                    $imagePath = process_uploaded_product_image($_FILES['image'], '/var/www/uploads/');
                } catch (RuntimeException $e) {
                    flash($e->getMessage(), 'error');
                    header('Location: /products');
                    exit;
                }
            }

            $stmt = $pdo->prepare('UPDATE products SET product_id = ?, barcode = ?, name = ?, asset_type = ?, manufacturer = ?, status = ?, color = ?, order_number = ?, purchase_url = ?, acumatica_inventory_id = ?, acumatica_default_vendor_id = ?, image_path = ?, is_tracked_asset = ? WHERE id = ?');
            $stmt->execute([
                $product_id,
                $normalized_barcode !== '' ? $normalized_barcode : null,
                $name,
                $asset_type ?: null,
                $manufacturer ?: null,
                $status,
                $color ?: null,
                $order_number ?: null,
                $purchase_url ?: null,
                $acumatica_inventory_id ?: null,
                $acumatica_default_vendor_id ?: null,
                $imagePath,
                $is_tracked_asset,
                $id
            ]);
            flash('Product updated successfully', 'success');
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            log_products_exception($e, 'product_update_failed');
            flash('Failed to update product. Please try again.', 'error');
        }
    }

    header('Location: /products');
    exit;
}

// Handle product delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('products.delete');
    $id = req_int($_POST, 'id');
    if ($id > 0) {
        try {
            $pdo->beginTransaction();
        // Delete related records first
            $pdo->prepare('DELETE FROM van_stock WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM transactions WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM cart_items WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM user_favorites WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_order_items WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM order_template_items WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM region_storage WHERE product_id = ?')->execute([$id]);
        // Delete the product
            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
            flash('Product deleted successfully', 'success');
        } catch (Exception $e) {
            qt_rethrow_bad_input($e);
            $pdo->rollBack();
                log_products_exception($e, 'product_delete_failed');
            flash('Failed to delete product. Please try again.', 'error');
        }
    }

    header('Location: /products');
    exit;
}

// Products are loaded asynchronously on the page; avoid fetching all rows here.
$products = [];
// $main_class and $extra_styles are set inside products.view.php before header.php is included
require __DIR__ . '/views/products.view.php';
