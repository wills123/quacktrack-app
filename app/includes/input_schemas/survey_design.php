<?php

/**
 * Request input of the survey design actions of api/surveys.php (templates, categories, questions,
 * reordering and publication), one schema per action (see includes/input_schema.php). Submission and
 * photo actions are not described here. Column limits mirror db/migrations/053-create-survey-tables.sql;
 * tests/schema_drift_test.php checks them against a real database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_errors.php';
require_once __DIR__ . '/../input_schema.php';

/** survey_templates.id, survey_categories.id and survey_questions.id are INT columns. */
const SURVEY_DESIGN_MAX_ID = 2147483647;

/**
 * Most active questions a published version may have: the survey page saves every answer of a draft in one request,
 * and the draft schema (input_schemas/survey_responses.php) accepts this many.
 */
const SURVEY_MAX_QUESTIONS = 2000;

/** Most ids one reorder request may list (a template with more categories or questions than this cannot be reordered). */
const SURVEY_DESIGN_REORDER_MAX = 500;

/** The actions this file describes. */
const SURVEY_DESIGN_ACTIONS = [
    'get_active_template', 'get_template', 'create_template', 'update_template', 'add_category', 'update_category',
    'delete_category', 'reorder_categories', 'add_question', 'update_question', 'delete_question', 'reorder_questions',
    'publish_version', 'list_versions',
];

/**
 * A row id of an INT column.
 *
 * @return array<string, mixed>
 */
function survey_design_id(string $label): array
{
    return in_int(min: 1, max: SURVEY_DESIGN_MAX_ID, required: true, label: $label);
}

/**
 * Every action's schema, keyed by action name (get_active_template takes no input).
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function survey_design_input_schemas(): array
{
    $templateFields = [
        'title' => in_string(max: 190, required: true, label: 'Title', column: 'survey_templates.title'),
        'description' => in_text(label: 'Description', column: 'survey_templates.description'),
    ];
    $categoryName = in_string(max: 190, required: true, label: 'Category name', column: 'survey_categories.name');
    $questionFields = [
        'question_text' => in_string(max: 500, required: true, label: 'Question text', column: 'survey_questions.question_text'),
        'scoring_guidance' => in_text(label: 'Scoring guidance', column: 'survey_questions.scoring_guidance'),
    ];
    $idList = static fn(string $label): array => in_list(
        in_int(min: 1, max: SURVEY_DESIGN_MAX_ID),
        max: SURVEY_DESIGN_REORDER_MAX,
        required: true,
        json: true,
        label: $label,
    );
    return [
        'get_template' => ['template_id' => survey_design_id('Template')],
        'create_template' => $templateFields,
        'update_template' => ['template_id' => survey_design_id('Template')] + $templateFields,
        'add_category' => ['template_id' => survey_design_id('Template'), 'name' => $categoryName],
        'update_category' => ['category_id' => survey_design_id('Category'), 'name' => $categoryName],
        'delete_category' => ['category_id' => survey_design_id('Category')],
        'reorder_categories' => ['template_id' => survey_design_id('Template'), 'category_ids' => $idList('Category ids')],
        'add_question' => ['category_id' => survey_design_id('Category')] + $questionFields,
        'update_question' => ['question_id' => survey_design_id('Question')] + $questionFields,
        'delete_question' => ['question_id' => survey_design_id('Question')],
        'reorder_questions' => ['category_id' => survey_design_id('Category'), 'question_ids' => $idList('Question ids')],
        'publish_version' => ['template_id' => survey_design_id('Template')],
        'list_versions' => ['template_id' => survey_design_id('Template')],
    ];
}

/**
 * Parse the request for $action. Reads (get_template, list_versions) take the query, changes the body.
 * A reordering list must be a real list with no blank entries: the list parser drops blanks, which here
 * would turn an incomplete replacement into a different, valid-looking one.
 *
 * @return array<string, mixed>
 */
function survey_design_input(string $action): array
{
    static $schemas = null;
    $schemas ??= survey_design_input_schemas();
    $source = in_array($action, ['get_template', 'list_versions'], true) ? $_GET : $_POST;
    $values = input_parse($schemas[$action], $source);
    foreach (['category_ids', 'question_ids'] as $field) {
        if (array_key_exists($field, $values)) {
            survey_design_require_plain_list($source[$field] ?? null, count($values[$field]), $field);
        }
    }
    return $values;
}

/**
 * @param int $parsed how many ids the parser kept
 * @throws BadRequestException when the list had other keys or blank entries
 */
function survey_design_require_plain_list(mixed $raw, int $parsed, string $field): void
{
    $items = is_string($raw) ? json_decode(trim($raw), true) : $raw;
    if (!is_array($items) || !array_is_list($items) || count($items) !== $parsed) {
        throw new BadRequestException(ucfirst(str_replace('_', ' ', $field)) . ' must be a plain list of ids without blank entries.');
    }
}

/**
 * The replacement order must name every current member exactly once.
 *
 * @param list<int> $ids the requested order
 * @param list<int> $members the ids that belong to the parent now
 * @throws UnprocessableException
 */
function survey_design_require_permutation(array $ids, array $members, string $what): void
{
    if (count(array_unique($ids)) !== count($ids)) {
        throw new UnprocessableException("The {$what} list repeats an id.");
    }
    $unknown = array_values(array_diff($ids, $members));
    if ($unknown !== []) {
        throw new UnprocessableException("The {$what} list has ids that do not belong here.", ['ids' => $unknown]);
    }
    $missing = array_values(array_diff($members, $ids));
    if ($missing !== []) {
        throw new UnprocessableException("The {$what} list must contain every id exactly once.", ['missing' => $missing]);
    }
}
