<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/**
 * Photos of travel places: an uploaded image is checked (size, real image type, pixel count), decoded and written
 * again under a random name, so the stored file is never the uploaded bytes. A problem with the file is a 4xx;
 * a server problem (no directory, no image library) is a 500.
 */

/** The longest photo upload in bytes unless TRAVEL_PHOTO_MAX_BYTES says otherwise. */
const TRAVEL_PHOTO_DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

/** The most pixels of an uploaded photo unless TRAVEL_PHOTO_MAX_PIXELS says otherwise. */
const TRAVEL_PHOTO_DEFAULT_MAX_PIXELS = 16 * 1024 * 1024;

/** The directory photos are stored in (TRAVEL_UPLOAD_DIR overrides the production default). */
function travel_upload_dir(): string
{
    return rtrim((string)env('TRAVEL_UPLOAD_DIR', '/var/www/uploads/'), '/') . '/';
}

/** @return array<string, string> the allowed image types and the extension each is stored with */
function travel_photo_mime_map(): array
{
    return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
}

/**
 * Run $call with PHP's warnings muted: the image functions warn about bad input and return false, which the
 * caller turns into a 4xx.
 *
 * @param callable(): mixed $call
 */
function travel_quietly(callable $call): mixed
{
    set_error_handler(static fn(): bool => true);
    try {
        return $call();
    } finally {
        restore_error_handler();
    }
}

/**
 * Check, decode and store an uploaded photo; returns its web path, e.g. "/uploads/<32 hex>.png".
 *
 * @param array<string, mixed> $file one entry of $_FILES
 * @throws BadRequestException|UnprocessableException
 */
function travel_store_photo(array $file): string
{
    travel_check_upload($file);
    $binary = file_get_contents((string)$file['tmp_name']);
    if ($binary === false) {
        throw new RuntimeException('Failed to read uploaded image.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
    $allowed = travel_photo_mime_map();
    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new UnprocessableException('Only JPEG, PNG, GIF, or WebP images are allowed.');
    }
    travel_check_dimensions($binary);
    $source = travel_decode($binary);
    try {
        return travel_write_image($source, $mime, $allowed[$mime]);
    } finally {
        imagedestroy($source);
    }
}

/**
 * The image is readable and not larger than the pixel limit.
 *
 * @throws UnprocessableException
 */
function travel_check_dimensions(string $binary): void
{
    $info = travel_quietly(static fn(): array|false => getimagesizefromstring($binary));
    if (!is_array($info)) {
        throw new UnprocessableException('Uploaded file is not a valid image.');
    }
    $maxPixels = defined('TRAVEL_PHOTO_MAX_PIXELS') ? (int)TRAVEL_PHOTO_MAX_PIXELS : TRAVEL_PHOTO_DEFAULT_MAX_PIXELS;
    if ($info[0] <= 0 || $info[1] <= 0 || $info[0] * $info[1] > $maxPixels) {
        throw new UnprocessableException('Image dimensions are too large.');
    }
}

/**
 * Decode the image bytes.
 *
 * @throws UnprocessableException
 */
function travel_decode(string $binary): GdImage
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Image processing is unavailable.');
    }
    $source = travel_quietly(static fn(): GdImage|false => imagecreatefromstring($binary));
    if (!$source instanceof GdImage) {
        throw new UnprocessableException('Failed to process uploaded image.');
    }
    return $source;
}

/**
 * The upload itself: sent without error, not empty, within the size limit, and really an HTTP upload.
 *
 * @param array<string, mixed> $file
 * @throws BadRequestException|UnprocessableException
 */
function travel_check_upload(array $file): void
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new UnprocessableException('Image is too large. Maximum size is 5MB.');
    }
    if ($error === UPLOAD_ERR_PARTIAL || $error === UPLOAD_ERR_NO_FILE) {
        throw new BadRequestException('Image upload failed.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed on the server.');
    }
    travel_check_upload_file($file);
}

/**
 * The size and origin of an upload that arrived without error.
 *
 * @param array<string, mixed> $file
 * @throws BadRequestException|UnprocessableException
 */
function travel_check_upload_file(array $file): void
{
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new UnprocessableException('Uploaded image is empty.');
    }
    $maxBytes = defined('TRAVEL_PHOTO_MAX_BYTES') ? (int)TRAVEL_PHOTO_MAX_BYTES : TRAVEL_PHOTO_DEFAULT_MAX_BYTES;
    if ($size > $maxBytes) {
        throw new UnprocessableException('Image is too large. Maximum size is 5MB.');
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new BadRequestException('Invalid image upload source.');
    }
}

/** Write the decoded image under a random name and return its web path. */
function travel_write_image(GdImage $image, string $mime, string $extension): string
{
    $dir = travel_upload_dir() . 'travel/';
    if (!is_dir($dir) && !travel_quietly(static fn(): bool => mkdir($dir, 0755, true)) && !is_dir($dir)) {
        throw new RuntimeException('Upload directory is not writable.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = $dir . $filename;
    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, 85),
        'image/png' => imagesavealpha($image, true) && imagepng($image, $path, 6),
        'image/gif' => imagegif($image, $path),
        default => function_exists('imagewebp') ? imagewebp($image, $path, 80) : throw new RuntimeException('WebP images are not supported on this server.'),
    };
    if (!$saved) {
        throw new RuntimeException('Failed to store uploaded image.');
    }
    travel_quietly(static fn(): bool => chmod($path, 0644));
    return '/uploads/travel/' . $filename;
}

/** Remove the file of a stored photo path; anything that is not a path this code wrote is ignored. */
function travel_remove_photo_file(?string $webPath): void
{
    if ($webPath === null || preg_match('#^/uploads/((?:travel/)?[0-9a-f]{32}\.(?:jpg|png|gif|webp))$#', $webPath, $match) !== 1) {
        return;
    }
    $path = travel_upload_dir() . $match[1];
    if (is_file($path)) {
        travel_quietly(static fn(): bool => unlink($path));
    }
}
