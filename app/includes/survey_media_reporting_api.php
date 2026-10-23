<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/survey_media_reporting.php';
require_once __DIR__ . '/survey_media_reporting_service.php';

/**
 * The photo and reporting actions of api/surveys.php. Order of checks: method (POST for a photo change, GET or HEAD
 * for a report; 405), permission (surveys.submit for photos, surveys.view_own or surveys.view_all for submissions,
 * surveys.compare for comparisons; 403), CSRF for a photo change (403), input (400 wrong type or missing, 422 rule),
 * the photo file (400 missing or incomplete, 422 too large, empty or not a JPEG/PNG image), then rows: no such
 * submission, photo or store (404), someone else's (403), no longer a draft (409), a question of another survey or a
 * limit reached (422). Anything unexpected is a 500.
 */

/** Handle one action; every answer ends the request. */
function survey_media_reporting_dispatch(string $action, int $userId): void
{
    try {
        $write = in_array($action, SURVEY_MEDIA_REPORTING_WRITES, true);
        $allowed = $write ? ['POST'] : ['GET', 'HEAD'];
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ClientErrorException('Method not allowed', 405);
        }
        survey_media_reporting_permit($action);
        if ($write && !csrf_verify_api()) {
            throw new ForbiddenException('Invalid or missing CSRF token. Please refresh and try again.');
        }
        $in = survey_media_reporting_input($action);
        [$data, $message] = survey_media_reporting_answer($action, $userId, $in);
        api_success($data, $message);
    } catch (Throwable $e) {
        api_fail($e, 'surveys_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}

/**
 * Refuse a caller without the action's permission.
 *
 * @throws ForbiddenException
 */
function survey_media_reporting_permit(string $action): void
{
    $allowed = match ($action) {
        'upload_photo', 'delete_photo' => has_permission('surveys.submit'),
        'list_submissions', 'get_submission' => has_permission('surveys.view_own') || has_permission('surveys.view_all'),
        default => has_permission('surveys.compare'),
    };
    if (!$allowed) {
        throw new ForbiddenException('Access denied. Insufficient permissions.');
    }
}

/**
 * Run the action and return its data and message.
 *
 * @param array<string, mixed> $in parsed input
 * @return array{0: mixed, 1: string}
 */
function survey_media_reporting_answer(string $action, int $userId, array $in): array
{
    $pdo = db();
    $viewer = SurveyReportViewer::current($userId);
    return match ($action) {
        'upload_photo' => [
            ['photo' => survey_media_upload($pdo, $userId, $in['submission_id'], $in['question_id'], survey_media_photo($_FILES))],
            'Photo uploaded.',
        ],
        'delete_photo' => survey_media_reporting_deleted($pdo, $userId, $in['photo_id']),
        'list_submissions' => [survey_media_list($pdo, $viewer, $in), 'Success'],
        'get_submission' => [survey_media_submission($pdo, $viewer, $in['submission_id']), 'Success'],
        'get_comparison' => [['comparison' => survey_media_comparison($pdo, $viewer, $in['manager_submission_id'], $in['tech_submission_id'] ?? null)], 'Success'],
        'list_tech_surveys' => [['submissions' => survey_media_tech_surveys($pdo, $viewer, $in['store_id'], $in['version_id'] ?? 0)], 'Success'],
        default => throw new UnexpectedValueException('Unknown survey action ' . $action . '.'),
    };
}

/** @return array{0: null, 1: string} */
function survey_media_reporting_deleted(PDO $pdo, int $userId, int $photoId): array
{
    survey_media_delete_photo($pdo, $userId, $photoId);
    return [null, 'Photo deleted.'];
}
