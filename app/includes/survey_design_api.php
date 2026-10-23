<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/survey_design.php';
require_once __DIR__ . '/survey_design_service.php';

/**
 * The survey design actions of api/surveys.php. Order of checks: method (405), permission (403),
 * CSRF (403), input (400 wrong type, 422 rule), then rows (404). Anything unexpected is a 500.
 */

/** Actions that only read; every other design action changes data (POST with a CSRF token). */
const SURVEY_DESIGN_READ_ACTIONS = ['get_active_template', 'get_template', 'list_versions'];

/** Handle a design action; every answer ends the request. */
function survey_design_dispatch(string $action, int $userId): void
{
    try {
        $reads = in_array($action, SURVEY_DESIGN_READ_ACTIONS, true);
        $allowed = $reads ? ['GET', 'HEAD'] : ['POST'];
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new ClientErrorException('Method not allowed', 405);
        }
        survey_design_require_access($action);
        if (!$reads && !csrf_verify_api()) {
            throw new ForbiddenException('Invalid or missing CSRF token. Please refresh and try again.');
        }
        survey_design_respond($action, $userId, db());
    } catch (Throwable $e) {
        api_fail($e, 'surveys_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
    }
}

/** Templates may be read by submitters and managers; versions and every change need the manage permission. */
function survey_design_require_access(string $action): void
{
    if (in_array($action, ['get_active_template', 'get_template'], true)) {
        if (!has_permission('surveys.submit') && !has_permission('surveys.templates.manage')) {
            require_permission('surveys.submit');
        }
        return;
    }
    require_permission('surveys.templates.manage');
}

/** Parse, run and answer one design action. */
function survey_design_respond(string $action, int $userId, PDO $pdo): void
{
    if ($action === 'get_active_template') {
        api_success(['template' => survey_get_active_template()]);
    }
    $in = survey_design_input($action);
    $handlers = survey_design_handlers($userId);
    [$data, $message] = $handlers[$action]($pdo, $in);
    api_success($data, $message);
}

/**
 * What each remaining action does and answers with: [data, message].
 *
 * @return array<string, callable(PDO, array<string, mixed>): array{0: mixed, 1: string}>
 */
function survey_design_handlers(int $userId): array
{
    return [
        'get_template' => static function (PDO $pdo, array $in): array {
            $template = survey_get_template($in['template_id']);
            return $template === null ? throw new NotFoundException('Template not found.') : [['template' => $template], 'Success'];
        },
        'create_template' => static fn(PDO $pdo, array $in): array => [
            ['template' => survey_design_create_template($pdo, $userId, $in['title'], $in['description'] ?? null)], 'Template created.',
        ],
        'update_template' => static function (PDO $pdo, array $in): array {
            survey_design_update_template($pdo, $in['template_id'], $in['title'], $in['description'] ?? null);
            return [null, 'Template updated.'];
        },
        'add_category' => static fn(PDO $pdo, array $in): array => [
            ['category_id' => survey_design_add_category($pdo, $in['template_id'], $in['name'])], 'Category added.',
        ],
        'update_category' => static function (PDO $pdo, array $in): array {
            survey_design_update_category($pdo, $in['category_id'], $in['name']);
            return [null, 'Category updated.'];
        },
        'delete_category' => static function (PDO $pdo, array $in): array {
            survey_design_delete_category($pdo, $in['category_id']);
            return [null, 'Category deleted.'];
        },
        'reorder_categories' => static function (PDO $pdo, array $in): array {
            survey_design_reorder_categories($pdo, $in['template_id'], $in['category_ids']);
            return [null, 'Categories reordered.'];
        },
        'add_question' => static fn(PDO $pdo, array $in): array => [
            ['question_id' => survey_design_add_question($pdo, $in['category_id'], $in['question_text'], $in['scoring_guidance'] ?? null)], 'Question added.',
        ],
        'update_question' => static function (PDO $pdo, array $in): array {
            survey_design_update_question($pdo, $in['question_id'], $in['question_text'], $in['scoring_guidance'] ?? null);
            return [null, 'Question updated.'];
        },
        'delete_question' => static function (PDO $pdo, array $in): array {
            survey_design_delete_question($pdo, $in['question_id']);
            return [null, 'Question deleted.'];
        },
        'reorder_questions' => static function (PDO $pdo, array $in): array {
            survey_design_reorder_questions($pdo, $in['category_id'], $in['question_ids']);
            return [null, 'Questions reordered.'];
        },
        'publish_version' => static fn(PDO $pdo, array $in): array => [
            ['version' => survey_design_publish_version($pdo, $in['template_id'], $userId)], 'Version published.',
        ],
        'list_versions' => static fn(PDO $pdo, array $in): array => [
            ['versions' => survey_design_list_versions($pdo, $in['template_id'])], 'Success',
        ],
    ];
}
