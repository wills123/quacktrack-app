<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/survey_responses.php';
require_once __DIR__ . '/survey_responses_service.php';

/**
 * The survey draft actions of api/surveys.php. Order of checks: method (405), permission (403), CSRF (403), input
 * (400 wrong type, 422 rule), then rows: no such submission or store (404), someone else's submission (403), no
 * longer a draft (409), answers that do not fit the survey (422). Anything unexpected is a 500.
 */

/** Handle a draft action; every answer ends the request. */
function survey_responses_dispatch(string $action, int $userId): void
{
    try {
        $read = $action === 'get_draft';
        $allowed = $read ? ['GET', 'HEAD'] : ['POST'];
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ClientErrorException('Method not allowed', 405);
        }
        require_permission('surveys.submit');
        if (!$read && !csrf_verify_api()) {
            throw new ForbiddenException('Invalid or missing CSRF token. Please refresh and try again.');
        }
        $in = survey_responses_input($action);
        [$data, $message] = survey_responses_handlers($userId)[$action](db(), $in);
        api_success($data, $message);
    } catch (Throwable $e) {
        api_fail($e, 'surveys_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}

/**
 * What each action does and answers with: [data, message].
 *
 * @return array<string, callable(PDO, array<string, mixed>): array{0: mixed, 1: string}>
 */
function survey_responses_handlers(int $userId): array
{
    return [
        'start_survey' => static function (PDO $pdo, array $in) use ($userId): array {
            // Only template managers may submit as a manager.
            if ($in['submitter_role'] === 'manager' && !has_permission('surveys.templates.manage')) {
                throw new ForbiddenException('Not authorized to submit as manager.');
            }
            [$submissionId, $resumed] = survey_responses_start($pdo, $userId, $in['store_id'], $in['submitter_role']);
            $view = survey_responses_view($submissionId);
            if (!$resumed) {
                unset($view['answers']);
            }
            return [$view + ['resumed' => $resumed], $resumed ? 'Existing draft found.' : 'Survey started.'];
        },
        'get_draft' => static function (PDO $pdo, array $in) use ($userId): array {
            survey_responses_own_draft($pdo, SURVEY_RESPONSES_ROW_SQL, $in['submission_id'], $userId);
            return [survey_responses_view($in['submission_id']), 'Success'];
        },
        'save_draft' => static function (PDO $pdo, array $in) use ($userId): array {
            survey_responses_save($pdo, $userId, $in['submission_id'], survey_responses_answers_by_question($in['answers']));
            return [null, 'Draft saved.'];
        },
        'submit_survey' => static function (PDO $pdo, array $in) use ($userId): array {
            survey_responses_submit($pdo, $userId, $in['submission_id']);
            return [null, 'Survey submitted.'];
        },
        'delete_draft' => static function (PDO $pdo, array $in) use ($userId): array {
            $left = survey_responses_delete($pdo, $userId, $in['submission_id']);
            if ($left !== []) {
                Logger::warning('survey_photo_files_left', 'Photo files of a deleted draft could not be removed', ['submission_id' => $in['submission_id'], 'files' => $left]);
            }
            return [null, 'Draft deleted.'];
        },
    ];
}
