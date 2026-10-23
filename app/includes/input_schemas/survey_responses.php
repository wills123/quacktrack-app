<?php

/**
 * Request input of the survey draft actions of api/surveys.php (start, read, save, submit and delete a draft), one
 * schema per action (see includes/input_schema.php). Column limits mirror db/migrations/053-create-survey-tables.sql;
 * tests/schema_drift_test.php checks them against a real database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';
require_once __DIR__ . '/survey_design.php';

/** The actions this file describes. */
const SURVEY_RESPONSES_ACTIONS = ['start_survey', 'get_draft', 'save_draft', 'submit_survey', 'delete_draft'];

/** survey_version_questions.id and stores.id are INT columns. */
const SURVEY_RESPONSES_MAX_INT_ID = 2147483647;

/** Most answers one save may carry: every question of the largest version publication allows. */
const SURVEY_RESPONSES_MAX_ANSWERS = SURVEY_MAX_QUESTIONS;

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function survey_responses_input_schemas(): array
{
    // survey_submissions.id is BIGINT UNSIGNED: any positive PHP integer fits.
    $submission = ['submission_id' => in_int(min: 1, required: true, label: 'Submission')];
    return [
        'start_survey' => [
            'store_id' => in_int(min: 1, max: SURVEY_RESPONSES_MAX_INT_ID, required: true, label: 'Store'),
            'submitter_role' => in_enum(['tech', 'manager'], required: true, label: 'Submitter role'),
        ],
        'get_draft' => $submission,
        'save_draft' => $submission + [
            'answers' => in_records([
                'version_question_id' => in_int(min: 1, max: SURVEY_RESPONSES_MAX_INT_ID, required: true, label: 'Question'),
                'score' => in_int(min: 1, max: 4, label: 'Score'),
                'notes' => in_text(label: 'Notes', column: 'survey_answers.notes'),
            ], max: SURVEY_RESPONSES_MAX_ANSWERS, label: 'Answers'),
        ],
        'submit_survey' => $submission,
        'delete_draft' => $submission,
    ];
}

/**
 * Parse the request for $action: get_draft reads the query, every other action the body.
 *
 * @return array<string, mixed>
 */
function survey_responses_input(string $action): array
{
    static $schemas = null;
    $schemas ??= survey_responses_input_schemas();
    $values = input_parse($schemas[$action], $action === 'get_draft' ? $_GET : $_POST);
    // An empty list is a save of nothing, as before; only a missing field is refused.
    if ($action === 'save_draft' && !array_key_exists('answers', $values)) {
        throw new BadRequestException('Answers is required.', ['fields' => ['answers' => 'Answers is required.']]);
    }
    return $values;
}

/**
 * The answers of one save, keyed by question id. A question named twice is refused rather than letting the last
 * entry win; blank notes are stored as NULL, as before.
 *
 * @param list<array<string, mixed>> $answers parsed save_draft records
 * @return array<int, array{score: int|null, notes: string|null}>
 * @throws UnprocessableException
 */
function survey_responses_answers_by_question(array $answers): array
{
    $byQuestion = [];
    foreach ($answers as $answer) {
        $questionId = (int)$answer['version_question_id'];
        if (isset($byQuestion[$questionId])) {
            throw new UnprocessableException('Each question may be answered only once per save.', ['version_question_id' => $questionId]);
        }
        $notes = trim((string)($answer['notes'] ?? ''));
        $score = isset($answer['score']) ? (int)$answer['score'] : null;
        $byQuestion[$questionId] = ['score' => $score, 'notes' => $notes === '' ? null : $notes];
    }
    return $byQuestion;
}
