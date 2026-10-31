<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/survey_media_reporting_service.php';

/** A stored image path, resolved beneath its configured root; symlinks may not escape that root. */
function upload_delivery_file(string $root, string $relative): string
{
    $base = realpath($root);
    $file = realpath(rtrim($root, '/') . '/' . $relative);
    if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        throw new NotFoundException('Image not found.');
    }
    return $file;
}

/**
 * Classify legacy flat photos before public product references; a reference cannot make private travel data public.
 *
 * @return array{root: string, relative: string, private: bool}
 */
function upload_delivery_resolve(PDO $pdo, string $path): array
{
    $root = (string)env('PRODUCT_UPLOAD_DIR', '/var/www/uploads/');
    if (preg_match('#\A/uploads/products/([0-9a-f]{32}\.(?:jpg|png|gif|webp))\z#D', $path, $match) === 1) {
        return ['root' => $root, 'relative' => 'products/' . $match[1], 'private' => false];
    }
    if (preg_match('#\A/uploads/surveys/([1-9][0-9]*)/([0-9a-f]{32}\.(?:jpg|png))\z#D', $path, $match) === 1) {
        upload_delivery_survey($pdo, $path, (int)$match[1]);
        return ['root' => survey_photo_root(), 'relative' => 'surveys/' . $match[1] . '/' . $match[2], 'private' => true];
    }
    if (preg_match('#\A/uploads/((?:travel/)?[A-Za-z0-9_-][A-Za-z0-9_.-]{0,200}\.(?:jpg|png|gif|webp))\z#D', $path, $match) !== 1) {
        throw new NotFoundException('Image not found.');
    }
    $travel = $pdo->prepare('SELECT id FROM travel_places WHERE photo_path = ? LIMIT 1');
    $travel->execute([$path]);
    if (str_starts_with($match[1], 'travel/') || $travel->fetchColumn() !== false) {
        require_login();
        return ['root' => (string)env('TRAVEL_UPLOAD_DIR', '/var/www/uploads/'), 'relative' => $match[1], 'private' => true];
    }
    $product = $pdo->prepare('SELECT id FROM products WHERE image_path = ? OR image_path = ? LIMIT 1');
    $product->execute([$path, $match[1]]);
    if ($product->fetchColumn() === false && !upload_delivery_public_banner($path)) {
        throw new NotFoundException('Image not found.');
    }
    return ['root' => $root, 'relative' => $match[1], 'private' => false];
}

/** Only explicitly configured public authentication-page images qualify as legacy public banners. */
function upload_delivery_public_banner(string $path): bool
{
    return in_array($path, [get_login_left_image(), get_login_right_image(), get_register_left_image(), get_register_right_image()], true);
}

/** Reuse the survey report's draft/owner/submitted visibility rules before opening a private image. */
function upload_delivery_survey(PDO $pdo, string $path, int $submissionId): void
{
    require_login();
    if (!has_permission('surveys.submit') && !has_permission('surveys.view_own') && !has_permission('surveys.view_all')) {
        throw new ForbiddenException('Image access denied.');
    }
    survey_media_visible($pdo, SurveyReportViewer::current((int)$_SESSION['uid']), $submissionId);
    $stmt = $pdo->prepare('SELECT id FROM survey_photos WHERE submission_id = ? AND file_path = ? LIMIT 1');
    $stmt->execute([$submissionId, $path]);
    if ($stmt->fetchColumn() === false) {
        throw new NotFoundException('Image not found.');
    }
}

/** Stream only supported image types; private content must never be stored by shared caches. */
function upload_delivery_send(string $file, string $cacheControl): void
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        throw new NotFoundException('Image not found.');
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . $cacheControl);
    header('Vary: Cookie');
    header('Content-Length: ' . (string)filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($file);
    }
}
