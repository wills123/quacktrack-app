<?php

/**
 * Request input of the survey photo and reporting actions of api/surveys.php (upload and delete a draft's photo, list
 * and read submissions, compare a manager survey with a tech survey, list tech surveys), one schema per action (see
 * includes/input_schema.php). The photo file itself is checked in survey_media_reporting_service.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** The actions this file describes. */
const SURVEY_MEDIA_REPORTING_ACTIONS = ['upload_photo', 'delete_photo', 'list_submissions', 'get_submission', 'get_comparison', 'list_tech_surveys'];

/** The actions that change something (POST, CSRF, surveys.submit). */
const SURVEY_MEDIA_REPORTING_WRITES = ['upload_photo', 'delete_photo'];

/** stores.id, survey_template_versions.id and survey_version_questions.id are INT columns. */
const SURVEY_MEDIA_REPORTING_MAX_INT_ID = 2147483647;

/** The most submissions one page lists. */
const SURVEY_MEDIA_REPORTING_MAX_PER_PAGE = 100;

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function survey_media_reporting_input_schemas(): array
{
    $store = ['store_id' => in_int(min: 1, max: SURVEY_MEDIA_REPORTING_MAX_INT_ID, required: true, label: 'Store')];
    // survey_submissions.id and survey_photos.id are BIGINT UNSIGNED: any positive PHP integer fits.
    $bigId = static fn(string $label): array => in_int(min: 1, required: true, label: $label);
    return [
        'upload_photo' => [
            'submission_id' => $bigId('Submission'),
            'question_id' => in_int(min: 1, max: SURVEY_MEDIA_REPORTING_MAX_INT_ID, required: true, label: 'Question'),
        ],
        'delete_photo' => ['photo_id' => $bigId('Photo')],
        'list_submissions' => $store + [
            'submitter_role' => in_enum(['tech', 'manager'], label: 'Submitter role'),
            'date_from' => in_date(label: 'From date'),
            'date_to' => in_date(label: 'To date'),
            'user_id' => in_int(min: 1, max: SURVEY_MEDIA_REPORTING_MAX_INT_ID, label: 'User'),
            'sort' => in_enum(['newest', 'oldest'], default: 'newest', label: 'Sort'),
            'page' => in_int(min: 1, max: 1000000, default: 1, label: 'Page'),
            'per_page' => in_int(min: 1, max: SURVEY_MEDIA_REPORTING_MAX_PER_PAGE, default: 25, label: 'Per page'),
        ],
        'get_submission' => ['submission_id' => $bigId('Submission')],
        'get_comparison' => [
            'manager_submission_id' => $bigId('Manager submission'),
            'tech_submission_id' => in_int(min: 1, label: 'Tech submission'),
        ],
        'list_tech_surveys' => $store + ['version_id' => in_int(min: 1, max: SURVEY_MEDIA_REPORTING_MAX_INT_ID, label: 'Version')],
    ];
}

/**
 * Parse the request for $action: the body for a change, the query for a read. A date range must not end before it
 * starts.
 *
 * @return array<string, mixed>
 * @throws BadRequestException|UnprocessableException
 */
function survey_media_reporting_input(string $action): array
{
    static $schemas = null;
    $schemas ??= survey_media_reporting_input_schemas();
    $in = input_parse($schemas[$action], in_array($action, SURVEY_MEDIA_REPORTING_WRITES, true) ? $_POST : $_GET);
    if (isset($in['date_from'], $in['date_to']) && $in['date_to'] < $in['date_from']) {
        throw new UnprocessableException('The to date must not be before the from date.', ['fields' => ['date_to' => 'Must not be before the from date.']]);
    }
    return $in;
}
