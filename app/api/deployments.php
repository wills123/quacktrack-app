<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csv_export.php';
require_login();

require_once __DIR__ . '/../includes/deployment_service.php';
require_once __DIR__ . '/../includes/input_schemas/deployments.php';
require_once __DIR__ . '/../includes/QuickQueueClient.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$action = req_action('list');
$action = strtolower(trim($action));
$userId = (int)($_SESSION['uid'] ?? 0);

if ($action !== 'export') {
    header('Content-Type: application/json');
}

try {
    switch ($action) {
        case 'list':
            require_permission('deployment.view');
            handle_list();
            break;
        case 'get':
            require_permission('deployment.view');
            handle_get();
            break;
        case 'create':
            require_permission('deployment.manage');
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_create($userId);
            break;
        case 'cw_project_search':
            require_permission('deployment.manage');
            handle_cw_project_search();
            break;
        case 'local_store_search':
            require_permission('deployment.view');
            handle_local_store_search();
            break;
        case 'update_stage':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_update_stage($userId);
            break;
        case 'update_task':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_update_task($userId);
            break;
        case 'add_task':
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_add_task($userId);
            break;
        case 'remove_task':
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_remove_task($userId);
            break;
        case 'add_visit':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_add_visit($userId);
            break;
        case 'add_note':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_add_note($userId);
            break;
        case 'export':
            require_permission('deployment.reports.export');
            handle_export($userId);
            break;
        case 'quest_board':
            require_permission('deployment.view');
            handle_quest_board();
            break;
        case 'quest_get':
            require_permission('deployment.view');
            handle_quest_get();
            break;
        case 'quest_create':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_quest_create($userId);
            break;
        case 'quest_update_status':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_quest_update_status($userId);
            break;
        case 'quest_update_objective':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_quest_update_objective($userId);
            break;
        case 'quest_assign':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_quest_assign($userId);
            break;
        case 'quest_log':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_quest_log($userId);
            break;
        case 'quest_templates':
            require_permission('deployment.tasks.customize');
            handle_quest_templates();
            break;
        case 'quest_template_create':
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_quest_template_create($userId);
            break;
        case 'quest_template_update':
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_quest_template_update($userId);
            break;
        case 'quest_template_duplicate':
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_quest_template_duplicate($userId);
            break;
        case 'quest_template_archive':
            require_permission('deployment.tasks.customize');
            csrf_verify_or_fail();
            handle_quest_template_archive($userId);
            break;
        case 'quest_template_apply':
            require_permission('deployment.manage');
            csrf_verify_or_fail();
            handle_quest_template_apply($userId);
            break;
        default:
            api_error('Unsupported action', 400);
    }
} catch (Throwable $e) {
    // api_fail() answers typed client errors with their own 4xx and everything else with 500.
    api_fail($e, 'deployment_api_error', [
        'action' => $action,
        'user_id' => $userId,
    ], 'Unable to complete request.');
}

function csrf_verify_or_fail(): void
{
    if (!csrf_verify_api()) {
        api_error('Invalid or missing CSRF token. Please refresh and try again.', 419);
    }
}

/**
 * The list/export filters as the response echoes them (every key present, empty when not sent).
 *
 * @param array<string, mixed> $in parsed list or export input
 * @return array<string, mixed>
 */
function deployment_api_filters(array $in): array
{
    return [
        'stage' => $in['stage'] ?? null,
        'region_id' => ($in['region_id'] ?? 0) ?: null,
        'q' => $in['q'] ?? '',
        'go_live_start' => $in['go_live_start'] ?? '',
        'go_live_end' => $in['go_live_end'] ?? '',
    ];
}

function handle_list(): void
{
    $in = deployment_input('list', $_GET);
    $limit = $in['limit'];
    $page = $in['page'];
    $offset = ($page - 1) * $limit;
    $filters = deployment_api_filters($in);

    $result = deployments_list($filters, $limit, $offset);

    api_success([
        'records' => $result['records'],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $result['total'],
            'has_more' => ($offset + $limit) < $result['total'],
        ],
        'filters' => $filters,
    ]);
}

function handle_get(): void
{
    $in = deployment_input('get', isset($_GET['deployment_id']) ? $_GET : $_POST);

    $deployment = deployments_get($in['deployment_id']);
    if (!$deployment) {
        throw new NotFoundException('Deployment not found.');
    }

    api_success(['deployment' => $deployment]);
}

function handle_create(int $userId): void
{
    $deployment = deployments_create(deployment_input('create', $_POST), $userId);
    api_success(['deployment' => $deployment], 'Deployment created.');
}

function handle_local_store_search(): void
{
    $query = deployment_input('local_store_search', $_GET)['q'] ?? '';
    if (strlen($query) < 2) {
        api_success(['stores' => []]);
    }

    api_success(['stores' => deployments_search_local_stores($query)]);
}

/**
 * Proxies a ConnectWise project search to QuickQueue for the "New Campaign"
 * modal's lookup field. Quack Track holds the QuickQueue credentials
 * server-side only — the browser never gets them or talks to QuickQueue
 * directly. Failures (missing config, QuickQueue/ConnectWise down) degrade
 * to an empty result with a warning rather than an error, since this lookup
 * is optional — a deployment can always be created by typing fields in.
 */
function handle_cw_project_search(): void
{
    $query = deployment_input('cw_project_search', $_GET)['q'] ?? '';
    if ($query === '') {
        api_success(['projects' => []]);
    }

    try {
        $projects = QuickQueueClient::getInstance()->searchConnectWiseProjects($query);
    } catch (RuntimeException $e) {
        api_success(['projects' => [], 'warning' => 'ConnectWise lookup is unavailable right now.']);
        return;
    }

    api_success(['projects' => $projects]);
}

function handle_update_stage(int $userId): void
{
    $in = deployment_input('update_stage', $_POST);
    $deployment = deployments_update_stage($in['deployment_id'], $in['stage'], $userId, ($in['notes'] ?? '') ?: null);
    api_success(['deployment' => $deployment], 'Stage updated.');
}

function handle_update_task(int $userId): void
{
    $in = deployment_input('update_task', $_POST);
    $tasks = deployments_update_task(
        $in['deployment_id'],
        $in['task_id'],
        $in['status'],
        $in['percent_complete'] ?? 0.0,
        ($in['notes'] ?? '') ?: null,
        $userId
    );
    api_success(['tasks' => $tasks], 'Task updated.');
}

function handle_add_task(int $userId): void
{
    $in = deployment_input('add_task', $_POST);
    $tasks = deployments_add_task($in['deployment_id'], $in['label'], $in['sort_order'] ?? null, $userId);
    api_success(['tasks' => $tasks], 'Task added.');
}

function handle_remove_task(int $userId): void
{
    $in = deployment_input('remove_task', $_POST);
    $tasks = deployments_remove_task($in['deployment_id'], $in['task_id'], $userId);
    api_success(['tasks' => $tasks], 'Task removed.');
}

function handle_add_visit(int $userId): void
{
    $in = deployment_input('add_visit', $_POST);
    $visits = deployments_add_visit($in['deployment_id'], $in, $userId);
    api_success(['visits' => $visits], 'Visit logged.');
}

function handle_add_note(int $userId): void
{
    $in = deployment_input('add_note', $_POST);
    $notes = deployments_add_note($in['deployment_id'], $in['note_text'], $userId);
    api_success(['notes' => $notes], 'Note added.');
}

function handle_export(int $userId): void
{
    $filters = deployment_api_filters(deployment_input('export', $_GET));

    $records = deployments_export($filters, $userId);

    // Opened before the CSV headers go out, so a failure still answers with the JSON error envelope.
    $output = csv_open_output();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deployment_export_' . date('Ymd_His') . '.csv"');

    csv_write_row($output, ['Site', 'Project Code', 'Region', 'Stage', 'Percent Complete', 'Go-Live Date', 'Updated At']);

    foreach ($records as $row) {
        csv_write_row($output, [
            $row['site_name'],
            $row['project_code'],
            $row['region_name'],
            $row['stage'],
            $row['overall_percent'],
            $row['go_live_date'],
            $row['updated_at'],
        ]);
    }

    fclose($output);
    exit;
}

function handle_quest_board(): void
{
    $in = deployment_input('quest_board', $_GET);
    $filters = array_filter(
        ['status' => $in['status'] ?? null, 'assigned_user_id' => $in['assigned_user_id'] ?? null],
        static fn (mixed $value): bool => $value !== null && $value !== 0
    );
    api_success(['quests' => deployment_quests_list($in['deployment_id'], $filters)]);
}

function handle_quest_get(): void
{
    $in = deployment_input('quest_get', $_GET);
    $quest = deployment_quest_get($in['quest_id']);
    if (!$quest) {
        throw new NotFoundException('Quest not found.');
    }
    api_success(['quest' => $quest]);
}

function handle_quest_create(int $userId): void
{
    $quest = deployment_quest_create(deployment_input('quest_create', $_POST), $userId);
    api_success(['quest' => $quest], 'Quest created.');
}

function handle_quest_update_status(int $userId): void
{
    $in = deployment_input('quest_update_status', $_POST);
    $quest = deployment_quest_update_status($in['quest_id'], $in['status'], ($in['blocker_reason'] ?? '') ?: null, $userId);
    api_success(['quest' => $quest], 'Quest updated.');
}

function handle_quest_update_objective(int $userId): void
{
    $in = deployment_input('quest_update_objective', $_POST);
    $quest = deployment_quest_update_objective($in['objective_id'], $in, $userId);
    api_success(['quest' => $quest], 'Objective updated.');
}

function handle_quest_assign(int $userId): void
{
    $in = deployment_input('quest_assign', $_POST);
    $quest = deployment_quest_assign($in['quest_id'], ($in['assigned_user_id'] ?? 0) ?: null, $userId);
    api_success(['quest' => $quest], 'Assignment updated.');
}

function handle_quest_log(int $userId): void
{
    $in = deployment_input('quest_log', $_POST);
    deployment_require_quest($in['quest_id']);
    deployment_quest_add_log($in['quest_id'], $userId, $in['entry_type'], $in['message'], []);
    api_success(['quest' => deployment_quest_get($in['quest_id'])], 'Log added.');
}

function handle_quest_templates(): void
{
    api_success(['templates' => deployment_quest_templates_list()]);
}

function handle_quest_template_create(int $userId): void
{
    $templates = deployment_quest_template_create(deployment_input('quest_template_create', $_POST), $userId);
    api_success(['templates' => $templates], 'Template saved.');
}

function handle_quest_template_update(int $userId): void
{
    $in = deployment_input('quest_template_update', $_POST);
    $templates = deployment_quest_template_update($in['template_id'], $in, $userId);
    api_success(['templates' => $templates], 'Template updated.');
}

function handle_quest_template_duplicate(int $userId): void
{
    $in = deployment_input('quest_template_duplicate', $_POST);
    $templates = deployment_quest_template_duplicate($in['template_id'], $in, $userId);
    api_success(['templates' => $templates], 'Template duplicated.');
}

function handle_quest_template_archive(int $userId): void
{
    $in = deployment_input('quest_template_archive', $_POST);
    api_success(['templates' => deployment_quest_template_archive($in['template_id'], $userId)], 'Template archived.');
}

function handle_quest_template_apply(int $userId): void
{
    $in = deployment_input('quest_template_apply', $_POST);
    $quest = deployment_quest_create_from_template($in['deployment_id'], $in['template_id'], $userId, $in);
    api_success(['quest' => $quest], 'Quest added from template.');
}
