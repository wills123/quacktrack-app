<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/products.php';
require_login();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
$pdo = db();
$current_user_id = (int)$_SESSION['uid'];
$action = req_action();
if (!defined('PRODUCT_IMAGE_MAX_BYTES')) {
    define('PRODUCT_IMAGE_MAX_BYTES', 5 * 1024 * 1024);
}

const PRODUCT_UPLOAD_DIR_MODE = 0755;
const PRODUCT_UPLOAD_FILE_MODE = 0644;
const PRODUCT_IMAGE_NAME_BYTES = 16;

function products_api_image_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
}

function save_products_api_image(string $binary, string $uploadDir): string
{
    $size = strlen($binary);
    if ($size === 0) {
        throw new UnprocessableException('Image data is empty.');
    }
    if ($size > PRODUCT_IMAGE_MAX_BYTES) {
        throw new UnprocessableException('Image is too large. Maximum size is 5MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($binary);
    $allowed = products_api_image_mimes();
    if (!isset($allowed[$mime])) {
        throw new UnprocessableException('Only JPEG, PNG, GIF, or WebP images are allowed.');
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, PRODUCT_UPLOAD_DIR_MODE, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    try {
        $filename = bin2hex(random_bytes(PRODUCT_IMAGE_NAME_BYTES)) . '.' . $allowed[$mime];
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        throw new RuntimeException('Failed to generate image filename.');
    }

    $targetPath = rtrim($uploadDir, '/') . '/' . $filename;
    if (file_put_contents($targetPath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Failed to store uploaded image.');
    }
    if (!chmod($targetPath, PRODUCT_UPLOAD_FILE_MODE)) {
        error_log('products image: could not set the file mode of ' . basename($targetPath));
    }
    return '/uploads/' . $filename;
}

function process_products_api_upload(array $file, string $uploadDir): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        // The client's file was missing, partial or over the limit: their error. A missing
        // temp dir, a failed disk write or a blocking extension is ours.
        $clientCodes = [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL];
        if (in_array($error, $clientCodes, true)) {
            throw new UnprocessableException('Image upload failed. Please try again.');
        }
        throw new RuntimeException('Image upload failed on the server (code ' . $error . ').');
    }

    $size = $file['size'] ?? 0;
    if ($size <= 0) {
        throw new UnprocessableException('Uploaded image is empty.');
    }
    if ($size > PRODUCT_IMAGE_MAX_BYTES) {
        throw new UnprocessableException('Image is too large. Maximum size is 5MB.');
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new BadRequestException('Invalid image upload source.');
    }

    $binary = file_get_contents($file['tmp_name']);
    if ($binary === false) {
        throw new RuntimeException('Failed to read uploaded image.');
    }

    return save_products_api_image($binary, $uploadDir);
}

/** Where uploaded images are stored; the environment can point it elsewhere (the fuzzer uses a temp dir). */
function products_api_upload_dir(): string
{
    return (string)env('PRODUCT_UPLOAD_DIR', '/var/www/uploads/');
}

/** Changing actions take POST only: a GET carries no CSRF check, so it must never reach them. */
function products_api_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_error('Method not allowed', 405);
    }
}

function products_api_csrf(): void
{
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid CSRF token');
    }
}

/**
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function products_api_format_dates(array $product): array
{
    foreach (['created_at', 'modified_at'] as $column) {
        if ($product[$column]) {
            $product[$column] = date('Y-m-d H:i', (int)strtotime((string)$product[$column]));
        }
    }
    return $product;
}

/**
 * The WHERE clause and its parameters for a parsed search request.
 *
 * @param array<string, mixed> $in
 * @return array{0: string, 1: list<string>}
 */
function products_api_search_filter(array $in): array
{
    $conditions = [];
    $params = [];
    if (($in['q'] ?? '') !== '') {
        $conditions[] = '(p.name LIKE ? OR p.product_id LIKE ? OR p.manufacturer LIKE ?)';
        $like = '%' . $in['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    if (($in['category'] ?? '') !== '') {
        $conditions[] = 'p.asset_type LIKE ?';
        $params[] = '%' . $in['category'] . '%';
    }
    $manufacturers = $in['manufacturer'] ?? [];
    if ($manufacturers !== []) {
        $conditions[] = 'p.manufacturer IN (' . implode(',', array_fill(0, count($manufacturers), '?')) . ')';
        array_push($params, ...$manufacturers);
    }
    if (($in['status'] ?? '') !== '') {
        $conditions[] = 'p.status = ?';
        $params[] = $in['status'];
    }
    return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
}

function products_api_search(PDO $pdo, int $userId): void
{
    $in = products_input('search');
    [$where, $params] = products_api_search_filter($in);
    // arch-ok: sql-interpolation - $where is built from fixed fragments with ? placeholders; every value is bound
    $count = $pdo->prepare("SELECT COUNT(*) FROM products p $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    // arch-ok: sql-interpolation - the same $where as above
    $stmt = $pdo->prepare("
        SELECT p.id, p.product_id, p.barcode, p.name, p.image_path, p.asset_type,
               p.manufacturer, p.status, p.color, p.order_number, p.purchase_url,
               p.acumatica_inventory_id, p.acumatica_default_vendor_id,
               p.is_tracked_asset, p.created_at, p.modified_at,
               COALESCE(vs.quantity, 0) as van_stock,
               COALESCE(rs.quantity, 0) as region_stock
        FROM products p
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM van_stock
            WHERE user_id = ?
            GROUP BY product_id
        ) vs ON p.id = vs.product_id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM region_storage
            WHERE region = ?
            GROUP BY product_id
        ) rs ON p.id = rs.product_id
        $where
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge([$userId, $_SESSION['region'] ?? ''], $params, [$in['limit'], $in['offset']]));
    api_success([
        'products' => array_map('products_api_format_dates', $stmt->fetchAll()),
        'total' => $total,
        'limit' => $in['limit'],
        'offset' => $in['offset'],
        'has_more' => ((int)$in['offset'] + (int)$in['limit']) < $total,
    ], 'Products retrieved successfully');
}

function products_api_get(PDO $pdo, int $userId): void
{
    $productId = products_input('get')['id'];
    $stmt = $pdo->prepare('
        SELECT p.id, p.product_id, p.barcode, p.name, p.image_path, p.asset_type,
               p.manufacturer, p.status, p.color, p.order_number, p.purchase_url,
               p.acumatica_inventory_id, p.acumatica_default_vendor_id,
               p.is_tracked_asset, p.created_at, p.modified_at,
               COALESCE(vs.quantity, 0) as van_stock,
               COALESCE(rs.quantity, 0) as region_stock,
               (SELECT COUNT(*) FROM user_favorites uf WHERE uf.product_id = p.id AND uf.user_id = ?) as is_favorite
        FROM products p
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM van_stock
            WHERE user_id = ?
            GROUP BY product_id
        ) vs ON p.id = vs.product_id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM region_storage
            WHERE region = ?
            GROUP BY product_id
        ) rs ON p.id = rs.product_id
        WHERE p.id = ?
    ');
    $stmt->execute([$userId, $userId, $_SESSION['region'] ?? '', $productId]);
    $product = $stmt->fetch();
    if (!$product) {
        throw new NotFoundException('Product not found');
    }
    $product = products_api_format_dates($product);
    $product['is_favorite'] = (bool)$product['is_favorite'];
    api_success($product, 'Product retrieved successfully');
}

/**
 * The one uploaded file of the "image" field. PHP also accepts image[] and nested names, whose parts
 * are arrays; they are a malformed request, not an upload failure.
 *
 * @return array<string, mixed>
 */
function products_api_uploaded_image(): array
{
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || ($file['name'] ?? '') === '') {
        throw new BadRequestException('No image file provided');
    }
    foreach (['name', 'tmp_name', 'error', 'size'] as $part) {
        if (!isset($file[$part]) || is_array($file[$part])) {
            throw new BadRequestException('Send exactly one image file in the "image" field.');
        }
    }
    return $file;
}

function products_api_upload_image(PDO $pdo): void
{
    products_api_require_post();
    products_api_csrf();
    require_permission('products.edit');
    $productId = products_input('upload_image')['product_id'];
    $stmt = $pdo->prepare('SELECT id FROM products WHERE id = ?');
    $stmt->execute([$productId]);
    if (!$stmt->fetch()) {
        throw new NotFoundException('Product not found');
    }
    $webPath = process_products_api_upload(products_api_uploaded_image(), products_api_upload_dir());
    $update = $pdo->prepare('UPDATE products SET image_path = ?, modified_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([$webPath, $productId]);
    api_success(['image_path' => $webPath], 'Image uploaded successfully');
}

/**
 * The distinct non-empty values of one products column.
 *
 * @param 'manufacturer'|'asset_type' $column
 * @return list<string>
 */
function products_api_distinct(PDO $pdo, string $column): array
{
    // arch-ok: sql-interpolation - $column is one of the two column names the callers pass, never request input
    $stmt = $pdo->prepare("SELECT DISTINCT $column FROM products WHERE $column IS NOT NULL AND $column != '' ORDER BY $column ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function products_api_bulk_update_status(PDO $pdo): void
{
    products_api_require_post();
    products_api_csrf();
    require_permission('products.edit');
    $in = products_input('bulk_update_status');
    $placeholders = implode(',', array_fill(0, count($in['product_ids']), '?'));
    // arch-ok: sql-interpolation - $placeholders is a run of ? marks, one per validated id
    $stmt = $pdo->prepare("
        UPDATE products
        SET status = ?, modified_at = CURRENT_TIMESTAMP
        WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$in['status']], $in['product_ids']));
    $updated = $stmt->rowCount();
    api_success(['updated_count' => $updated], "Updated $updated products");
}

try {
    switch ($action) {
        case 'search':
            products_api_search($pdo, $current_user_id);
            break;
        case 'get':
            products_api_get($pdo, $current_user_id);
            break;
        case 'upload_image':
            products_api_upload_image($pdo);
            break;
        case 'get_manufacturers':
            api_success(['manufacturers' => products_api_distinct($pdo, 'manufacturer')], 'Manufacturers retrieved successfully');
            break;
        case 'get_categories':
            api_success(['categories' => products_api_distinct($pdo, 'asset_type')], 'Categories retrieved successfully');
            break;
        case 'bulk_update_status':
            products_api_bulk_update_status($pdo);
            break;
        default:
            throw new BadRequestException('Invalid action');
    }
} catch (Exception $e) {
    // api_fail() answers every ClientError itself (status, details, endpoint context), so this
    // catch does not hand them to the boundary via qt_rethrow_bad_input().
    // Client errors answer with their own 4xx; anything else is logged and answers 500
    api_fail($e, 'products_api_error', [
        'action' => $action,
        'user_id' => $current_user_id,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    ], 'Product operation failed. Please try again.');
}
