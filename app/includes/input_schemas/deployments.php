<?php

/**
 * Request input of api/deployments.php, one schema per action (see includes/input_schema.php).
 * The handlers parse the request with deployment_input(); services keep their own business-rule
 * checks (state, existence, uniqueness) as defence in depth for other callers.
 *
 * Column limits mirror db/migrations/026 and 027, 073; tests/schema_drift_test.php checks them
 * against a real database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';
require_once __DIR__ . '/../deployment_constants.php';

/** The fields of a quest, shared by create and template-apply. @return array<string, array<string, mixed>> */
function deployment_quest_fields(): array
{
    return [
        'deployment_id' => in_int(min: 1, required: true, label: 'Deployment ID'),
        'template_id' => in_int(min: 0),
        'title' => in_string(max: 160, required: true, column: 'deployment_quests.title'),
        'brief' => in_text(column: 'deployment_quests.brief'),
        'status' => in_enum(DEPLOYMENT_QUEST_STATUS_VALUES),
        'priority' => in_int(min: -128, max: 127, column: 'deployment_quests.priority'),
        'due_date' => in_date(),
        'assigned_user_id' => in_int(min: 0),
        'tags' => deployment_tags_field(),
        'objectives' => in_records([
            'label' => in_string(max: 160, column: 'deployment_quest_objectives.label'),
            'description' => in_text(column: 'deployment_quest_objectives.description'),
            'status' => in_enum(DEPLOYMENT_QUEST_OBJECTIVE_STATUS_VALUES),
            'sort_order' => in_int(min: -2147483648, max: 2147483647),
            'requires_review' => in_flag(default: false),
        ], max: 100, skipBlank: 'label'),
    ];
}

/** @return array<string, mixed> */
function deployment_tags_field(): array
{
    return in_list(in_string(max: 100), max: 50, unique: true, csv: true, json: true, label: 'Tags');
}

/** The fields of a quest template. @return array<string, array<string, mixed>> */
function deployment_template_fields(): array
{
    return [
        'title' => in_string(max: 160, required: true, column: 'deployment_quest_templates.title'),
        'brief' => in_text(column: 'deployment_quest_templates.brief'),
        'default_tags' => deployment_tags_field(),
        'recommended_role' => in_string(max: 80, column: 'deployment_quest_templates.recommended_role'),
        'auto_assign_role' => in_string(max: 80, column: 'deployment_quest_templates.auto_assign_role'),
        'objectives' => in_records([
            'label' => in_string(max: 160, column: 'deployment_quest_template_objectives.label'),
            'description' => in_text(column: 'deployment_quest_template_objectives.description'),
            'sort_order' => in_int(min: -2147483648, max: 2147483647),
            'requires_review' => in_flag(default: false),
        ], max: 100, skipBlank: 'label'),
    ];
}

/** Filters shared by the list and export actions. @return array<string, array<string, mixed>> */
function deployment_list_filter_fields(): array
{
    return [
        'stage' => in_enum(DEPLOYMENT_STAGE_VALUES),
        'region_id' => in_int(min: 0),
        'q' => in_string(max: 100, label: 'Search text'),
        'go_live_start' => in_date(),
        'go_live_end' => in_date(),
    ];
}

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function deployment_input_schemas(): array
{
    return deployment_project_input_schemas() + deployment_quest_input_schemas();
}

/**
 * The deployment, task, visit and note actions.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function deployment_project_input_schemas(): array
{
    $deploymentId = ['deployment_id' => in_int(min: 1, required: true, label: 'Deployment ID')];

    return [
        'list' => deployment_list_filter_fields() + [
            'limit' => in_int(min: 1, max: 100, default: 25, clamp: true),
            'page' => in_int(min: 1, max: 1000000, default: 1, clamp: true),
        ],
        'export' => deployment_list_filter_fields(),
        'get' => $deploymentId,
        'local_store_search' => ['q' => in_string(max: 100, label: 'Search text')],
        'cw_project_search' => ['q' => in_string(max: 200, label: 'Search text')],
        'create' => [
            'site_name' => in_string(max: 150, required: true, column: 'deployment_projects.site_name'),
            'site_number' => in_string(max: 50, column: 'deployment_projects.site_number'),
            'project_code' => in_string(max: 100, required: true, column: 'deployment_projects.project_code'),
            'connectwise_project_id' => in_string(max: 50, column: 'deployment_projects.connectwise_project_id'),
            'region_id' => in_int(min: 1, required: true, label: 'Region'),
            'store_id' => in_int(min: 0, label: 'Store'),
            'go_live_date' => in_date(),
            'stage' => in_enum(DEPLOYMENT_STAGE_VALUES, default: 'planned'),
            'address_line1' => in_string(max: 150, column: 'deployment_projects.address_line1'),
            'address_line2' => in_string(max: 150, column: 'deployment_projects.address_line2'),
            'city' => in_string(max: 100, column: 'deployment_projects.city'),
            'state' => in_string(max: 50, column: 'deployment_projects.state'),
            'postal_code' => in_string(max: 25, column: 'deployment_projects.postal_code'),
            'current_summary' => in_text(column: 'deployment_projects.current_summary'),
        ],
        'update_stage' => $deploymentId + [
            'stage' => in_enum(DEPLOYMENT_STAGE_VALUES, required: true),
            'notes' => in_string(max: 255, column: 'deployment_stage_history.notes'),
        ],
        'update_task' => $deploymentId + [
            'task_id' => in_int(min: 1, required: true, label: 'Task ID'),
            'status' => in_enum(DEPLOYMENT_TASK_STATUS_VALUES, required: true),
            'percent_complete' => in_float(min: 0, max: 100),
            'notes' => in_text(column: 'deployment_tasks.notes'),
        ],
        'add_task' => $deploymentId + [
            'label' => in_string(max: 150, required: true, label: 'Task label', column: 'deployment_tasks.label'),
            'sort_order' => in_int(min: -2147483648, max: 2147483647),
        ],
        'remove_task' => $deploymentId + [
            'task_id' => in_int(min: 1, required: true, label: 'Task ID'),
        ],
        'add_visit' => $deploymentId + [
            'visit_date' => in_datetime(required: true),
            'stage_at_visit' => in_enum(DEPLOYMENT_STAGE_VALUES),
            'summary' => in_text(column: 'deployment_visits.summary'),
            'hours_spent' => in_float(min: 0, max: 999.99, column: 'deployment_visits.hours_spent'),
            'tech_user_ids' => in_list(in_int(min: 1), max: 200, unique: true, single: true, label: 'Technician IDs'),
        ],
        'add_note' => $deploymentId + [
            'note_text' => in_text(required: true, label: 'Note', column: 'deployment_notes.note_text'),
        ],
    ];
}

/**
 * The quest and quest-template actions.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function deployment_quest_input_schemas(): array
{
    $deploymentId = ['deployment_id' => in_int(min: 1, required: true, label: 'Deployment ID')];
    $questId = ['quest_id' => in_int(min: 1, required: true, label: 'Quest ID')];
    $templateId = ['template_id' => in_int(min: 1, required: true, label: 'Template ID')];

    return [
        'quest_board' => $deploymentId + [
            'status' => in_enum(DEPLOYMENT_QUEST_STATUS_VALUES),
            'assigned_user_id' => in_int(min: 0),
        ],
        'quest_get' => $questId,
        'quest_create' => deployment_quest_fields(),
        'quest_update_status' => $questId + [
            'status' => in_enum(DEPLOYMENT_QUEST_STATUS_VALUES, required: true),
            'blocker_reason' => in_text(column: 'deployment_quests.blocker_reason'),
        ],
        'quest_update_objective' => [
            'objective_id' => in_int(min: 1, required: true, label: 'Objective ID'),
            'status' => in_enum(DEPLOYMENT_QUEST_OBJECTIVE_STATUS_VALUES),
            'description' => in_text(column: 'deployment_quest_objectives.description'),
        ],
        'quest_assign' => $questId + [
            'assigned_user_id' => in_int(min: 0),
        ],
        'quest_log' => $questId + [
            'message' => in_text(required: true, label: 'Message', column: 'deployment_quest_logs.message'),
            'entry_type' => in_enum(DEPLOYMENT_QUEST_LOG_TYPES, default: 'update'),
        ],
        'quest_template_create' => deployment_template_fields(),
        'quest_template_update' => $templateId + deployment_template_fields(),
        // A copy takes any of the template fields as overrides; the title defaults to "<title> Copy".
        'quest_template_duplicate' => $templateId
            + ['title' => in_string(max: 160, column: 'deployment_quest_templates.title')]
            + deployment_template_fields(),
        'quest_template_archive' => $templateId,
        'quest_template_apply' => $deploymentId + $templateId + [
            'title' => in_string(max: 160, column: 'deployment_quests.title'),
            'brief' => in_text(column: 'deployment_quests.brief'),
            'assigned_user_id' => in_int(min: 0),
            'due_date' => in_date(),
        ],
    ];
}

/**
 * Parse the request for $action against its schema.
 *
 * @param array<int|string, mixed> $source $_GET or $_POST
 * @return array<string, mixed>
 */
function deployment_input(string $action, array $source): array
{
    static $schemas = null;
    $schemas ??= deployment_input_schemas();
    return input_parse($schemas[$action], $source);
}
