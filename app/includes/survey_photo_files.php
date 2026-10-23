<?php

/**
 * Where survey photo files live. Rows keep a web path ('/uploads/surveys/<submission>/<name>'); the file is under the
 * upload directory (SURVEY_UPLOAD_DIR overrides the production default, so tests use a disposable directory).
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

/** The web path prefix of every survey photo. */
const SURVEY_PHOTO_WEB_PREFIX = '/uploads/surveys/';

/** The upload directory, with a trailing slash. */
function survey_photo_root(): string
{
    return rtrim((string)env('SURVEY_UPLOAD_DIR', '/var/www/uploads/'), '/') . '/';
}

/** The directory of one submission's photos. */
function survey_photo_dir(int $submissionId): string
{
    return survey_photo_root() . 'surveys/' . $submissionId;
}

/** The file of a stored web path, or null when the path is not one of ours (never a path outside the directory). */
function survey_photo_file(string $webPath): ?string
{
    if (preg_match('#^/uploads/surveys/\d+/[0-9a-f]{32}\.(?:jpg|png)$#', $webPath) !== 1) {
        return null;
    }
    return survey_photo_root() . substr($webPath, strlen('/uploads/'));
}

/**
 * Remove photo files once their rows are gone, then the submission's directory when it is empty.
 *
 * @param list<mixed> $paths web paths
 * @return list<string> the paths whose files are still there
 */
function survey_photo_remove_files(int $submissionId, array $paths): array
{
    $left = [];
    foreach ($paths as $path) {
        $file = survey_photo_file((string)$path);
        if ($file !== null && is_file($file) && !unlink($file)) {
            $left[] = (string)$path;
        }
    }
    $dir = survey_photo_dir($submissionId);
    if (is_dir($dir) && (scandir($dir) ?: []) === ['.', '..']) {
        rmdir($dir);
    }
    return $left;
}

/**
 * The public fields of a photo row.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function survey_photo_public(array $row): array
{
    return [
        'id' => (int)$row['id'], 'answer_id' => $row['answer_id'] === null ? null : (int)$row['answer_id'],
        'url' => (string)$row['file_path'], 'file_path' => (string)$row['file_path'], 'original_filename' => (string)$row['original_filename'],
        'file_size_bytes' => (int)$row['file_size_bytes'], 'mime_type' => (string)$row['mime_type'],
    ];
}

/**
 * The name to keep for an upload: its last path part without control characters, 'photo' when nothing is left.
 *
 * @throws UnprocessableException
 */
function survey_photo_name(mixed $name): string
{
    $name = is_string($name) ? $name : '';
    if (!mb_check_encoding($name, 'UTF-8')) {
        throw new UnprocessableException('The photo name is not valid text.');
    }
    $base = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))));
    if (mb_strlen($base) > 255) {
        throw new UnprocessableException('The photo name must be at most 255 characters.');
    }
    return $base === '' ? 'photo' : $base;
}
