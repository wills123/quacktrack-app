<?php

/**
 * Survey photos and reporting for api/surveys.php. A photo belongs to one answer of the caller's own draft; the
 * draft is locked while a photo is added or removed, so the per-answer and per-submission limits hold under
 * concurrent uploads, and a file is never left without its row (or a row without its file). Reports show a person
 * their own submissions, and with surveys.view_all everyone's submitted ones; nobody sees another person's draft.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/survey_service.php';
require_once __DIR__ . '/survey_design_service.php';
require_once __DIR__ . '/survey_responses_service.php';
require_once __DIR__ . '/survey_photo_files.php';

/** The largest photo, in bytes. */
const SURVEY_MEDIA_MAX_BYTES = 5 * 1024 * 1024;

/** Photos one answer may have. */
const SURVEY_MEDIA_PER_ANSWER = 3;

/** Photos one submission may have. */
const SURVEY_MEDIA_PER_SUBMISSION = 20;

/** The image types a photo may be, with the file extension each is stored under. */
const SURVEY_MEDIA_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

/** How far (in days, either side) a manager survey looks for a tech survey to compare with. */
const SURVEY_MEDIA_PAIR_WINDOW_DAYS = 30;

/** Who is reading reports: their own submissions always, everyone's submitted ones with surveys.view_all. */
final class SurveyReportViewer
{
    public function __construct(public readonly int $userId, public readonly bool $viewAll)
    {
    }

    public static function current(int $userId): self
    {
        return new self($userId, has_permission('surveys.view_all'));
    }
}

/**
 * The photo file of the request, checked: present and uploaded (400), not empty, at most 5 MB, a JPEG or PNG that
 * decodes as an image, with a name of at most 255 characters (422).
 *
 * @param array<string, mixed> $files the request's $_FILES
 * @return array{tmp: string, name: string, mime: string, size: int}
 * @throws BadRequestException|UnprocessableException
 */
function survey_media_photo(array $files): array
{
    $file = survey_media_photo_entry($files);
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new UnprocessableException('Photo is too large. Maximum size is 5 MB.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new BadRequestException('The photo upload did not complete. Try again.');
    }
    return ['tmp' => $file['tmp_name'], 'name' => survey_photo_name($file['name'] ?? null)] + survey_media_photo_content($file['tmp_name']);
}

/**
 * The single `photo` entry of $_FILES (not a list of files), when one was sent.
 *
 * @param array<string, mixed> $files
 * @return array{tmp_name: string, error: int, name?: mixed}
 * @throws BadRequestException
 */
function survey_media_photo_entry(array $files): array
{
    $file = $files['photo'] ?? null;
    if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new BadRequestException('A photo file is required.');
    }
    return $file;
}

/**
 * Size and type of an uploaded file, read from the file itself.
 *
 * @return array{mime: string, size: int}
 * @throws UnprocessableException
 */
function survey_media_photo_content(string $tmp): array
{
    $size = (int)filesize($tmp);
    if ($size <= 0) {
        throw new UnprocessableException('The photo is empty.');
    }
    if ($size > SURVEY_MEDIA_MAX_BYTES) {
        throw new UnprocessableException('Photo is too large. Maximum size is 5 MB.');
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(SURVEY_MEDIA_TYPES[$mime])) {
        throw new UnprocessableException('Only JPEG and PNG images are allowed.');
    }
    $image = getimagesize($tmp);
    if ($image === false || $image['mime'] !== $mime) {
        throw new UnprocessableException('The photo is not a readable image.');
    }
    return ['mime' => $mime, 'size' => $size];
}

/**
 * The answer of $questionId in a locked draft, created when the draft has none yet.
 *
 * @param array{id: int, version_id: int} $draft
 * @throws UnprocessableException when the question is not part of the draft's survey
 */
function survey_media_answer_id(PDO $pdo, array $draft, int $questionId): int
{
    $stmt = $pdo->prepare(
        'SELECT q.id, a.id AS answer_id FROM survey_version_questions q
         LEFT JOIN survey_answers a ON a.submission_id = ? AND a.version_question_id = q.id
         WHERE q.id = ? AND q.version_id = ?'
    );
    $stmt->execute([$draft['id'], $questionId, $draft['version_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new UnprocessableException('That question is not part of this survey.');
    }
    if ($row['answer_id'] !== null) {
        return (int)$row['answer_id'];
    }
    $pdo->prepare('INSERT INTO survey_answers (submission_id, version_question_id, score, notes) VALUES (?, ?, NULL, NULL)')->execute([$draft['id'], $questionId]);
    return (int)$pdo->lastInsertId();
}

/**
 * Refuse a photo over the per-answer or per-submission limit (the draft is locked, so the counts hold).
 *
 * @throws UnprocessableException
 */
function survey_media_photo_limits(PDO $pdo, int $submissionId, int $answerId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(answer_id = ?), 0) FROM survey_photos WHERE submission_id = ?');
    $stmt->execute([$answerId, $submissionId]);
    [$all, $answer] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
    if ($answer >= SURVEY_MEDIA_PER_ANSWER) {
        throw new UnprocessableException('Maximum ' . SURVEY_MEDIA_PER_ANSWER . ' photos per answer.');
    }
    if ($all >= SURVEY_MEDIA_PER_SUBMISSION) {
        throw new UnprocessableException('Maximum ' . SURVEY_MEDIA_PER_SUBMISSION . ' photos per submission.');
    }
}

/**
 * Move the upload into the submission's directory under a random name and return its web path.
 *
 * @param array{tmp: string, mime: string} $photo
 * @throws RuntimeException
 */
function survey_media_store_file(int $submissionId, array $photo): string
{
    $dir = survey_photo_dir($submissionId);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the survey photo directory.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . SURVEY_MEDIA_TYPES[$photo['mime']];
    if (!move_uploaded_file($photo['tmp'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not store the survey photo.');
    }
    chmod($dir . '/' . $name, 0644);
    return SURVEY_PHOTO_WEB_PREFIX . $submissionId . '/' . $name;
}

/**
 * Add a photo to one answer of the caller's draft and return it. The file is removed again when the row cannot be
 * stored.
 *
 * @param array{tmp: string, name: string, mime: string, size: int} $photo
 * @return array<string, mixed>
 */
function survey_media_upload(PDO $pdo, int $userId, int $submissionId, int $questionId, array $photo): array
{
    $stored = null;
    try {
        return survey_design_transaction($pdo, static function () use ($pdo, $userId, $submissionId, $questionId, $photo, &$stored): array {
            $draft = survey_responses_own_draft($pdo, SURVEY_RESPONSES_LOCKED_ROW_SQL, $submissionId, $userId);
            $answerId = survey_media_answer_id($pdo, $draft, $questionId);
            survey_media_photo_limits($pdo, $submissionId, $answerId);
            $stored = survey_media_store_file($submissionId, $photo);
            $pdo->prepare('INSERT INTO survey_photos (submission_id, answer_id, file_path, original_filename, file_size_bytes, mime_type) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$submissionId, $answerId, $stored, $photo['name'], $photo['size'], $photo['mime']]);
            return survey_photo_public([
                'id' => $pdo->lastInsertId(), 'answer_id' => $answerId, 'file_path' => $stored, 'original_filename' => $photo['name'],
                'file_size_bytes' => $photo['size'], 'mime_type' => $photo['mime'],
            ]);
        });
    } catch (Throwable $e) {
        if ($stored !== null) {
            survey_photo_remove_files($submissionId, [$stored]);
        }
        throw $e;
    }
}

/**
 * Remove a photo of the caller's draft: the row first, then (after the commit) its file.
 *
 * @throws ClientErrorException
 */
function survey_media_delete_photo(PDO $pdo, int $userId, int $photoId): void
{
    [$submissionId, $path] = survey_design_transaction($pdo, static function () use ($pdo, $userId, $photoId): array {
        $stmt = $pdo->prepare('SELECT submission_id, file_path FROM survey_photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($photo === false) {
            throw new NotFoundException('Photo not found.');
        }
        survey_responses_own_draft($pdo, SURVEY_RESPONSES_LOCKED_ROW_SQL, (int)$photo['submission_id'], $userId);
        $delete = $pdo->prepare('DELETE FROM survey_photos WHERE id = ?');
        $delete->execute([$photoId]);
        if ($delete->rowCount() === 0) {
            throw new NotFoundException('Photo not found.');
        }
        return [(int)$photo['submission_id'], (string)$photo['file_path']];
    });
    if (survey_photo_remove_files($submissionId, [$path]) !== []) {
        Logger::warning('survey_photo_file_left', 'A deleted survey photo file could not be removed', ['photo_id' => $photoId, 'file' => $path]);
    }
}

/**
 * Make sure a store exists.
 *
 * @throws NotFoundException
 */
function survey_media_require_store(PDO $pdo, int $storeId): void
{
    $stmt = $pdo->prepare('SELECT id FROM stores WHERE id = ?');
    $stmt->execute([$storeId]);
    if ($stmt->fetchColumn() === false) {
        throw new NotFoundException('Store not found.');
    }
}

/** The columns every submission list answers with. */
const SURVEY_MEDIA_LIST_COLUMNS = "s.id, s.store_id, s.user_id, s.submitter_role, s.version_id, v.version_number, s.overall_avg_score, s.submitted_at,
    s.created_at, CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name";

/** The filters of a submission list: store, submitted, then person, role, from date and to date (0 or '' for any). */
const SURVEY_MEDIA_LIST_WHERE = "WHERE s.store_id = ? AND s.status = 'submitted' AND (? = 0 OR s.user_id = ?) AND (? = '' OR s.submitter_role = ?)
    AND (? = '' OR s.submitted_at >= ?) AND (? = '' OR s.submitted_at < DATE_ADD(?, INTERVAL 1 DAY))";

/**
 * The typed public fields of a submission list row.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function survey_media_list_row(array $row): array
{
    foreach (['id', 'store_id', 'user_id', 'version_id', 'version_number'] as $key) {
        $row[$key] = $row[$key] === null ? null : (int)$row[$key];
    }
    $row['overall_avg_score'] = $row['overall_avg_score'] === null ? null : (float)$row['overall_avg_score'];
    return $row;
}

/**
 * One page of a store's submitted surveys: the caller's own, or with surveys.view_all everyone's (optionally one
 * person's), filtered by role and submission date and sorted by submission time.
 *
 * @param array<string, mixed> $in parsed list_submissions input
 * @return array{submissions: list<array<string, mixed>>, pagination: array<string, int>}
 * @throws ClientErrorException
 */
function survey_media_list(PDO $pdo, SurveyReportViewer $viewer, array $in): array
{
    survey_media_require_store($pdo, $in['store_id']);
    if (!$viewer->viewAll && isset($in['user_id']) && $in['user_id'] !== $viewer->userId) {
        throw new ForbiddenException('You can only list your own submissions.');
    }
    $user = $viewer->viewAll ? ($in['user_id'] ?? 0) : $viewer->userId;
    $role = $in['submitter_role'] ?? '';
    $from = $in['date_from'] ?? '';
    $to = $in['date_to'] ?? '';
    $params = [$in['store_id'], $user, $user, $role, $role, $from, $from, $to, $to];
    $count = $pdo->prepare('SELECT COUNT(*) FROM survey_submissions s ' . SURVEY_MEDIA_LIST_WHERE);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $rows = $pdo->prepare(
        'SELECT ' . SURVEY_MEDIA_LIST_COLUMNS . ' FROM survey_submissions s LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN survey_template_versions v ON v.id = s.version_id ' . SURVEY_MEDIA_LIST_WHERE . "
         ORDER BY CASE WHEN ? = 'oldest' THEN UNIX_TIMESTAMP(s.submitted_at) ELSE -UNIX_TIMESTAMP(s.submitted_at) END,
                  CASE WHEN ? = 'oldest' THEN CAST(s.id AS SIGNED) ELSE -CAST(s.id AS SIGNED) END
         LIMIT ? OFFSET ?"
    );
    $rows->execute(array_merge($params, [$in['sort'], $in['sort'], $in['per_page'], ($in['page'] - 1) * $in['per_page']]));
    return [
        'submissions' => array_map('survey_media_list_row', $rows->fetchAll(PDO::FETCH_ASSOC)),
        'pagination' => ['page' => $in['page'], 'per_page' => $in['per_page'], 'total' => $total, 'pages' => (int)ceil($total / $in['per_page'])],
    ];
}

/**
 * A submission the caller may read: their own (any status), or with surveys.view_all anyone's submitted one. Another
 * person's draft does not exist for anyone else.
 *
 * @return array{id: int, user_id: int, status: string, submitter_role: string, store_id: int, version_id: int}
 * @throws NotFoundException|ForbiddenException
 */
function survey_media_visible(PDO $pdo, SurveyReportViewer $viewer, int $submissionId): array
{
    $stmt = $pdo->prepare('SELECT id, user_id, status, submitter_role, store_id, version_id FROM survey_submissions WHERE id = ?');
    $stmt->execute([$submissionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $own = $row !== false && (int)$row['user_id'] === $viewer->userId;
    if ($row === false || (!$own && $row['status'] !== 'submitted')) {
        throw new NotFoundException('Submission not found.');
    }
    if (!$own && !$viewer->viewAll) {
        throw new ForbiddenException('You do not have permission to view this submission.');
    }
    foreach (['id', 'user_id', 'store_id', 'version_id'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    return $row;
}

/**
 * A submission with its questions, answers and photos.
 *
 * @return array{submission: array<string, mixed>, categories: list<array<string, mixed>>}
 * @throws NotFoundException|ForbiddenException
 */
function survey_media_submission(PDO $pdo, SurveyReportViewer $viewer, int $submissionId): array
{
    survey_media_visible($pdo, $viewer, $submissionId);
    $submission = survey_get_submission($submissionId);
    if ($submission === null) {
        throw new NotFoundException('Submission not found.');
    }
    $categories = $submission['categories'] ?? [];
    unset($submission['categories'], $submission['user_first_name'], $submission['user_last_name']);
    foreach ($categories as &$category) {
        foreach ($category['questions'] as &$question) {
            $question['photos'] = array_map('survey_photo_public', $question['photos']);
        }
        unset($question);
    }
    unset($category);
    return ['submission' => $submission, 'categories' => $categories];
}

/**
 * A submitted survey of the expected role that the caller may read, for one side of a comparison.
 *
 * @return array{id: int, user_id: int, status: string, submitter_role: string, store_id: int, version_id: int}
 * @throws ClientErrorException
 */
function survey_media_comparable(PDO $pdo, SurveyReportViewer $viewer, int $submissionId, string $role): array
{
    $row = survey_media_visible($pdo, $viewer, $submissionId);
    if ($row['status'] !== 'submitted') {
        throw new UnprocessableException('Only submitted surveys can be compared.');
    }
    if ($row['submitter_role'] !== $role) {
        throw new UnprocessableException('Submission ' . $submissionId . ' is not a ' . $role . ' survey.');
    }
    return $row;
}

/**
 * The submitted tech survey of the manager survey's store nearest in time (within 30 days either side) that the caller
 * may read, preferring the same survey version; null when there is none.
 *
 * @param array{id: int, store_id: int, version_id: int} $manager
 */
function survey_media_nearest_tech(PDO $pdo, SurveyReportViewer $viewer, array $manager): ?int
{
    $stmt = $pdo->prepare(
        "SELECT t.id FROM survey_submissions t JOIN survey_submissions m ON m.id = ?
         WHERE t.store_id = m.store_id AND t.submitter_role = 'tech' AND t.status = 'submitted' AND (? = 1 OR t.user_id = ?)
           AND t.submitted_at BETWEEN DATE_SUB(m.submitted_at, INTERVAL ? DAY) AND DATE_ADD(m.submitted_at, INTERVAL ? DAY)
         ORDER BY t.version_id = m.version_id DESC, ABS(TIMESTAMPDIFF(SECOND, t.submitted_at, m.submitted_at)) ASC, t.id DESC
         LIMIT 1"
    );
    $stmt->execute([$manager['id'], (int)$viewer->viewAll, $viewer->userId, SURVEY_MEDIA_PAIR_WINDOW_DAYS, SURVEY_MEDIA_PAIR_WINDOW_DAYS]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Compare a manager survey with a tech survey of the same store: the one given, or the nearest one. Without a tech
 * survey to compare with, the answer carries the manager side and a notice.
 *
 * @return array<string, mixed>
 * @throws ClientErrorException
 */
function survey_media_comparison(PDO $pdo, SurveyReportViewer $viewer, int $managerId, ?int $techId): array
{
    $manager = survey_media_comparable($pdo, $viewer, $managerId, 'manager');
    if ($techId !== null) {
        $tech = survey_media_comparable($pdo, $viewer, $techId, 'tech');
        if ($tech['store_id'] !== $manager['store_id']) {
            throw new UnprocessableException('The tech survey is of another store.');
        }
    }
    $techId ??= survey_media_nearest_tech($pdo, $viewer, $manager);
    return $techId === null ? survey_media_unpaired($managerId) : survey_get_comparison($managerId, $techId);
}

/**
 * The comparison answer when no tech survey is near the manager survey.
 *
 * @return array<string, mixed>
 */
function survey_media_unpaired(int $managerId): array
{
    $manager = survey_get_submission($managerId) ?? throw new NotFoundException('Submission not found.');
    $average = $manager['overall_avg_score'] === null ? null : (float)$manager['overall_avg_score'];
    return [
        'cross_version_warning' => false,
        'notice' => 'No tech survey found within the comparison window.',
        'manager_submission' => [
            'id' => (int)$manager['id'], 'store_id' => (int)$manager['store_id'], 'version_id' => (int)$manager['version_id'],
            'submitted_at' => $manager['submitted_at'], 'submitted_by_name' => $manager['submitted_by_name'], 'overall_avg_score' => $average,
        ],
        'tech_submission' => null,
        'overall' => ['manager_avg' => $average, 'tech_avg' => null, 'diff' => null],
        'categories' => [],
        'questions' => [],
    ];
}

/**
 * A store's submitted tech surveys the caller may read, optionally of one version, newest first.
 *
 * @return list<array<string, mixed>>
 * @throws NotFoundException
 */
function survey_media_tech_surveys(PDO $pdo, SurveyReportViewer $viewer, int $storeId, int $versionId): array
{
    survey_media_require_store($pdo, $storeId);
    $stmt = $pdo->prepare(
        'SELECT ' . SURVEY_MEDIA_LIST_COLUMNS . " FROM survey_submissions s LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN survey_template_versions v ON v.id = s.version_id
         WHERE s.store_id = ? AND s.submitter_role = 'tech' AND s.status = 'submitted' AND (? = 0 OR s.version_id = ?) AND (? = 1 OR s.user_id = ?)
         ORDER BY s.submitted_at DESC, s.id DESC LIMIT 500"
    );
    $stmt->execute([$storeId, $versionId, $versionId, (int)$viewer->viewAll, $viewer->userId]);
    return array_map('survey_media_list_row', $stmt->fetchAll(PDO::FETCH_ASSOC));
}
