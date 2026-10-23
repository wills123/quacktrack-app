<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/deployment_constants.php';


function deployment_normalize_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return qt_parse_date($value) ?? throw new UnprocessableException('Invalid date provided.');
}

function deployment_normalize_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return qt_parse_datetime($value) ?? throw new UnprocessableException('Invalid date/time provided.');
}

function deployment_stage_is_valid(string $stage): bool
{
    return in_array($stage, DEPLOYMENT_STAGE_VALUES, true);
}

function deployment_task_status_is_valid(string $status): bool
{
    return in_array($status, DEPLOYMENT_TASK_STATUS_VALUES, true);
}

function deployments_list(array $filters, int $limit, int $offset): array
{
    $pdo = db();
    $where = ['dp.is_archived = 0'];
    $params = [];

    if (!empty($filters['stage']) && deployment_stage_is_valid($filters['stage'])) {
        $where[] = 'dp.stage = ?';
        $params[] = $filters['stage'];
    }

    if (!empty($filters['region_id'])) {
        $where[] = 'dp.region_id = ?';
        $params[] = (int)$filters['region_id'];
    }

    if (!empty($filters['q'])) {
        deployment_require_max_length((string)$filters['q'], 100, 'Search text');
        $search = '%' . $filters['q'] . '%';
        $where[] = '(dp.site_name LIKE ? OR dp.site_number LIKE ? OR dp.project_code LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $goLiveStart = deployment_normalize_date($filters['go_live_start'] ?? null);
    if ($goLiveStart !== null) {
        $where[] = 'dp.go_live_date >= ?';
        $params[] = $goLiveStart;
    }

    $goLiveEnd = deployment_normalize_date($filters['go_live_end'] ?? null);
    if ($goLiveEnd !== null) {
        $where[] = 'dp.go_live_date <= ?';
        $params[] = $goLiveEnd;
    }

    $whereSql = '';
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // arch-ok: sql-interpolation - $whereSql joins fixed fragments; every value is a ? parameter
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM deployment_projects dp
        $whereSql
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $limitClause = '';
    if ($limit > 0) {
        $limitClause = 'LIMIT ? OFFSET ?';
    }

    // arch-ok: sql-interpolation - $whereSql and $limitClause are fixed fragments; values are ? parameters
    $stmt = $pdo->prepare("
        SELECT dp.*, s.name AS store_name, r.name AS region_name
        FROM deployment_projects dp
        LEFT JOIN stores s ON dp.store_id = s.id
        LEFT JOIN regions r ON dp.region_id = r.id
        $whereSql
        ORDER BY dp.go_live_date IS NULL, dp.go_live_date ASC, dp.updated_at DESC
        $limitClause
    ");
    if ($limit > 0) {
        $stmt->execute([...$params, $limit, $offset]);
    } else {
        $stmt->execute($params);
    }
    $rows = $stmt->fetchAll() ?: [];

    return [
        'records' => $rows,
        'total' => $total,
    ];
}

/** The tables deployment_require_row() may look in (it puts the name into the SQL). */
const DEPLOYMENT_REQUIRE_ROW_TABLES = ['deployment_projects', 'deployment_quests', 'deployment_quest_templates', 'regions', 'stores', 'users'];

/**
 * Foreign keys would answer a missing parent with a 500. A missing resource named in the URL or
 * action is a 404; a missing record referenced by a field of the payload is a 422.
 */
function deployment_require_row(string $table, int $id, string $message, bool $inPayload = false): void
{
    if (!in_array($table, DEPLOYMENT_REQUIRE_ROW_TABLES, true)) {
        throw new LogicException("deployment_require_row() does not know the table '{$table}'.");
    }
    // arch-ok: sql-interpolation - the table name was checked against the fixed list above
    $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === false) {
        throw $inPayload ? new UnprocessableException($message) : new NotFoundException($message);
    }
}

/** A VARCHAR value: valid UTF-8 and at most $max characters (the database would reject anything else with a 500). */
function deployment_require_max_length(string $value, int $max, string $field): void
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        throw new UnprocessableException("{$field} contains invalid characters.");
    }
    if (mb_strlen($value) > $max) {
        throw new UnprocessableException("{$field} must be {$max} characters or fewer.");
    }
}

/** A TEXT value: valid UTF-8 and within the 65,535-byte column limit. */
function deployment_require_text_size(string $value, string $field): void
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        throw new UnprocessableException("{$field} contains invalid characters.");
    }
    if (strlen($value) > 65535) {
        throw new UnprocessableException("{$field} is too long.");
    }
}

function deployment_require_int_range(int $value, int $min, int $max, string $field): void
{
    if ($value < $min || $value > $max) {
        throw new UnprocessableException("{$field} must be between {$min} and {$max}.");
    }
}

function deployment_require_deployment(int $deploymentId): void
{
    deployment_require_row('deployment_projects', $deploymentId, 'Deployment not found.');
}

function deployment_require_quest(int $questId): void
{
    deployment_require_row('deployment_quests', $questId, 'Quest not found.');
}

function deployment_require_template(int $templateId): void
{
    deployment_require_row('deployment_quest_templates', $templateId, 'Template not found.');
}

function deployment_require_user(int $userId): void
{
    $stmt = db()->prepare('SELECT 1 FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() === false) {
        throw new UnprocessableException('Assigned user not found.');
    }
}

function deployments_get(int $deploymentId): ?array
{
    if ($deploymentId <= 0) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT dp.*, s.name AS store_name, r.name AS region_name
        FROM deployment_projects dp
        LEFT JOIN stores s ON dp.store_id = s.id
        LEFT JOIN regions r ON dp.region_id = r.id
        WHERE dp.id = ?
        LIMIT 1
    ");
    $stmt->execute([$deploymentId]);
    $deployment = $stmt->fetch();

    if (!$deployment) {
        return null;
    }

    $deployment['tasks'] = deployment_fetch_tasks($deploymentId);
    $deployment['visits'] = deployment_fetch_visits($deploymentId);
    $deployment['notes'] = deployment_fetch_notes($deploymentId);
    $deployment['stage_history'] = deployment_fetch_stage_history($deploymentId);

    return $deployment;
}

function deployment_fetch_tasks(int $deploymentId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT dt.*, u.email AS updated_by_email, u.role AS updated_by_role,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS updated_by_display
        FROM deployment_tasks dt
        LEFT JOIN users u ON dt.updated_by = u.id
        WHERE dt.deployment_id = ? AND dt.is_deleted = 0
        ORDER BY dt.sort_order ASC, dt.id ASC
    ");
    $stmt->execute([$deploymentId]);
    return $stmt->fetchAll() ?: [];
}

function deployment_fetch_stage_history(int $deploymentId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT dsh.*, u.email AS changed_by_email, u.role AS changed_by_role,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS changed_by_display
        FROM deployment_stage_history dsh
        LEFT JOIN users u ON dsh.changed_by = u.id
        WHERE dsh.deployment_id = ?
        ORDER BY dsh.changed_at DESC, dsh.id DESC
    ");
    $stmt->execute([$deploymentId]);
    return $stmt->fetchAll() ?: [];
}

function deployment_fetch_notes(int $deploymentId): array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT dn.*, u.email AS created_by_email, u.role AS created_by_role,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS created_by_display
        FROM deployment_notes dn
        LEFT JOIN users u ON dn.created_by = u.id
        WHERE dn.deployment_id = ?
        ORDER BY dn.created_at DESC, dn.id DESC
    ");
    $stmt->execute([$deploymentId]);
    return $stmt->fetchAll() ?: [];
}

function deployment_fetch_visits(int $deploymentId): array
{
    $pdo = db();
    $visitsStmt = $pdo->prepare("
        SELECT dv.*, u.email AS created_by_email, u.role AS created_by_role,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS created_by_display
        FROM deployment_visits dv
        LEFT JOIN users u ON dv.created_by = u.id
        WHERE dv.deployment_id = ?
        ORDER BY dv.visit_date DESC, dv.id DESC
    ");
    $visitsStmt->execute([$deploymentId]);
    $visits = $visitsStmt->fetchAll() ?: [];

    if (empty($visits)) {
        return [];
    }

    $visitIds = array_column($visits, 'id');
    $techStmt = $pdo->prepare("
        SELECT dvt.visit_id, dvt.user_id, tu.email AS tech_email, tu.role AS tech_role,
               COALESCE(NULLIF(tu.username, ''), NULLIF(CONCAT_WS(' ', tu.first_name, tu.last_name), ''), tu.email) AS tech_display
        FROM deployment_visit_techs dvt
        LEFT JOIN users tu ON dvt.user_id = tu.id
        WHERE dvt.visit_id IN (" . implode(',', array_fill(0, count($visitIds), '?')) . ")
        ORDER BY dvt.visit_id ASC, dvt.id ASC
    ");
    $techStmt->execute($visitIds);
    $techMap = [];
    foreach ($techStmt->fetchAll() ?: [] as $row) {
        $visitId = (int)$row['visit_id'];
        $techMap[$visitId][] = [
            'user_id' => $row['user_id'],
            'email' => $row['tech_email'],
            'display_name' => $row['tech_display'],
            'role' => $row['tech_role'],
        ];
    }

    foreach ($visits as &$visit) {
        $vid = (int)$visit['id'];
        $visit['techs'] = $techMap[$vid] ?? [];
    }

    return $visits;
}

function deployments_create(array $data, int $userId): array
{
    $pdo = db();
    $stage = strtolower(trim($data['stage'] ?? 'planned'));
    if (!deployment_stage_is_valid($stage)) {
        throw new UnprocessableException('Invalid deployment stage provided.');
    }

    $siteName = trim($data['site_name'] ?? '');
    $projectCode = trim($data['project_code'] ?? '');
    $regionId = qt_parse_int($data['region_id'] ?? null) ?? 0;

    if ($siteName === '' || $projectCode === '' || $regionId <= 0) {
        throw new UnprocessableException('Site name, project code, and region are required.');
    }
    deployment_require_max_length($siteName, 150, 'Site name');
    deployment_require_max_length($projectCode, 100, 'Project code');
    foreach (
        [
            'site_number' => ['Site number', 50], 'address_line1' => ['Address line 1', 150],
            'address_line2' => ['Address line 2', 150], 'city' => ['City', 100], 'state' => ['State', 50],
            'postal_code' => ['Postal code', 25], 'connectwise_project_id' => ['ConnectWise project ID', 50],
        ] as $field => [$label, $max]
    ) {
        deployment_require_max_length(trim((string)($data[$field] ?? '')), $max, $label);
    }
    deployment_require_text_size(trim((string)($data['current_summary'] ?? '')), 'Summary');
    deployment_require_row('regions', $regionId, 'Region not found.', true);

    $storeId = null;
    $storeInput = trim((string)($data['store_id'] ?? ''));
    if ($storeInput !== '' && $storeInput !== '0') {
        $storeId = qt_parse_int($storeInput);
        if ($storeId === null || $storeId <= 0) {
            throw new UnprocessableException('Store is invalid.');
        }
        deployment_require_row('stores', $storeId, 'Store not found.', true);
    }

    $goLiveDate = deployment_normalize_date($data['go_live_date'] ?? null);
    // Optional back-reference to the ConnectWise project this deployment was
    // looked up from, if the "New Campaign" form's CW search was used.
    $connectwiseProjectId = trim($data['connectwise_project_id'] ?? '') ?: null;

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO deployment_projects
            (store_id, site_name, site_number, address_line1, address_line2, city, state, postal_code,
             region_id, project_code, connectwise_project_id, go_live_date, stage, overall_percent, current_summary,
             created_by, updated_by)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?)
        ");

            $stmt->execute([
                $storeId,
                $siteName,
                trim($data['site_number'] ?? '') ?: null,
            trim($data['address_line1'] ?? '') ?: null,
            trim($data['address_line2'] ?? '') ?: null,
            trim($data['city'] ?? '') ?: null,
            trim($data['state'] ?? '') ?: null,
            trim($data['postal_code'] ?? '') ?: null,
            $regionId,
            $projectCode,
            $connectwiseProjectId,
            $goLiveDate,
            $stage,
            trim($data['current_summary'] ?? '') ?: null,
            $userId,
            $userId,
            ]);

        $deploymentId = (int)$pdo->lastInsertId();

        deployment_seed_tasks_from_templates($deploymentId);

        deployment_log_stage_change($deploymentId, null, $stage, $userId, 'Deployment created');

        AuditLogger::log('deployment.create', [
            'entity_type' => 'deployment',
            'entity_id' => $deploymentId,
            'description' => 'Deployment created',
            'metadata' => [
                'site_name' => $siteName,
                'project_code' => $projectCode,
                'region_id' => $regionId,
                'stage' => $stage,
            ],
        ]);

        $pdo->commit();
        return deployments_get($deploymentId) ?? [];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deployment_seed_tasks_from_templates(int $deploymentId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO deployment_tasks (deployment_id, template_id, label, sort_order, status, percent_complete)
        SELECT ?, dtt.id, dtt.label, dtt.default_sort, 'not_started', 0.00
        FROM deployment_task_templates dtt
        WHERE dtt.is_active = 1
        ORDER BY dtt.default_sort ASC, dtt.id ASC
    ");
    $stmt->execute([$deploymentId]);
}

function deployment_log_stage_change(int $deploymentId, ?string $fromStage, string $toStage, int $userId, ?string $notes = null): void
{
    $pdo = db();
    $insert = $pdo->prepare("
        INSERT INTO deployment_stage_history (deployment_id, from_stage, to_stage, notes, changed_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insert->execute([$deploymentId, $fromStage, $toStage, $notes, $userId]);

    AuditLogger::log('deployment.stage_change', [
        'entity_type' => 'deployment',
        'entity_id' => $deploymentId,
        'description' => 'Stage updated',
        'metadata' => [
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'notes' => $notes,
        ],
    ]);
}

function deployments_update_stage(int $deploymentId, string $newStage, int $userId, ?string $notes = null): array
{
    if (!deployment_stage_is_valid($newStage)) {
        throw new UnprocessableException('Invalid deployment stage.');
    }
    if ($notes !== null) {
        deployment_require_max_length($notes, 255, 'Notes');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $currentStmt = $pdo->prepare("SELECT stage FROM deployment_projects WHERE id = ? FOR UPDATE");
        $currentStmt->execute([$deploymentId]);
        $current = (string)$currentStmt->fetchColumn();

        if ($current === '') {
            throw new NotFoundException('Deployment not found.');
        }

        if ($current === $newStage) {
            $pdo->rollBack();
            return deployments_get($deploymentId) ?? [];
        }

        $update = $pdo->prepare("UPDATE deployment_projects SET stage = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update->execute([$newStage, $userId, $deploymentId]);

        deployment_log_stage_change($deploymentId, $current, $newStage, $userId, $notes);

        $pdo->commit();
        return deployments_get($deploymentId) ?? [];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deployments_update_task(int $deploymentId, int $taskId, string $status, float $percentComplete, ?string $notes, int $userId): array
{
    if (!deployment_task_status_is_valid($status)) {
        throw new UnprocessableException('Invalid task status.');
    }

    if (!is_finite($percentComplete)) {
        throw new UnprocessableException('Percent complete must be a number.');
    }
    if ($notes !== null) {
        deployment_require_text_size($notes, 'Notes');
    }
    $percentComplete = max(0, min(100, $percentComplete));

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $taskStmt = $pdo->prepare("
            SELECT id, status, percent_complete
            FROM deployment_tasks
            WHERE id = ? AND deployment_id = ? AND is_deleted = 0
            FOR UPDATE
        ");
        $taskStmt->execute([$taskId, $deploymentId]);
        $task = $taskStmt->fetch();

        if (!$task) {
            throw new NotFoundException('Task not found.');
        }

        $update = $pdo->prepare("
            UPDATE deployment_tasks
            SET status = ?, percent_complete = ?, notes = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([$status, $percentComplete, $notes, $userId, $taskId]);

        deployment_recalculate_progress($deploymentId);

        AuditLogger::log('deployment.task_update', [
            'entity_type' => 'deployment',
            'entity_id' => $deploymentId,
            'description' => 'Task updated',
            'metadata' => [
                'task_id' => $taskId,
                'status' => $status,
                'percent_complete' => $percentComplete,
            ],
        ]);

        $pdo->commit();
        return deployment_fetch_tasks($deploymentId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deployment_recalculate_progress(int $deploymentId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_tasks,
            AVG(
                CASE
                    WHEN percent_complete > 0 THEN percent_complete
                    WHEN status = 'complete' THEN 100
                    WHEN status = 'in_progress' THEN 50
                    ELSE 0
                END
            ) AS avg_percent
        FROM deployment_tasks
        WHERE deployment_id = ? AND is_deleted = 0
    ");
    $stmt->execute([$deploymentId]);
    $row = $stmt->fetch();

    $avg = $row && $row['avg_percent'] !== null ? round((float)$row['avg_percent'], 2) : 0.00;

    $update = $pdo->prepare("UPDATE deployment_projects SET overall_percent = ? WHERE id = ?");
    $update->execute([$avg, $deploymentId]);
}

function deployments_add_task(int $deploymentId, string $label, ?int $sortOrder, int $userId): array
{
    $label = trim($label);
    if ($label === '') {
        throw new UnprocessableException('Task label is required.');
    }
    deployment_require_max_length($label, 150, 'Task label');
    if ($sortOrder !== null) {
        deployment_require_int_range($sortOrder, -2147483648, 2147483647, 'Sort order');
    }
    deployment_require_deployment($deploymentId);

    $pdo = db();
    $sortOrder = $sortOrder ?? null;

    if ($sortOrder === null) {
        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM deployment_tasks WHERE deployment_id = ?");
        $sortStmt->execute([$deploymentId]);
        $sortOrder = min((int)$sortStmt->fetchColumn(), 2147483637) ?: 10;
    }

    $insert = $pdo->prepare("
        INSERT INTO deployment_tasks (deployment_id, label, sort_order, status, percent_complete, updated_by)
        VALUES (?, ?, ?, 'not_started', 0.00, ?)
    ");
    $insert->execute([$deploymentId, $label, $sortOrder, $userId]);

    $taskId = (int)$pdo->lastInsertId();

    deployment_recalculate_progress($deploymentId);

    AuditLogger::log('deployment.task_customize', [
        'entity_type' => 'deployment',
        'entity_id' => $deploymentId,
        'description' => 'Task added',
        'metadata' => [
            'task_id' => $taskId,
            'label' => $label,
            'sort_order' => $sortOrder,
        ],
    ]);

    return deployment_fetch_tasks($deploymentId);
}

function deployments_remove_task(int $deploymentId, int $taskId, int $userId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the task so two overlapping removals cannot both log; a task that is already
        // removed is a successful no-op, only an unknown one is a 404.
        $stmt = $pdo->prepare('SELECT is_deleted FROM deployment_tasks WHERE id = ? AND deployment_id = ? FOR UPDATE');
        $stmt->execute([$taskId, $deploymentId]);
        $isDeleted = $stmt->fetchColumn();
        if ($isDeleted === false) {
            throw new NotFoundException('Task not found.');
        }
        $removed = (int)$isDeleted === 0;
        if ($removed) {
            $pdo->prepare('UPDATE deployment_tasks SET is_deleted = 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$userId, $taskId]);
            deployment_recalculate_progress($deploymentId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($removed) {
        AuditLogger::log('deployment.task_customize', [
            'entity_type' => 'deployment',
            'entity_id' => $deploymentId,
            'description' => 'Task removed',
            'metadata' => [
                'task_id' => $taskId,
            ],
        ]);
    }

    return deployment_fetch_tasks($deploymentId);
}

function deployments_add_visit(int $deploymentId, array $data, int $userId): array
{
    $visitDate = deployment_normalize_datetime($data['visit_date'] ?? null);
    if ($visitDate === null) {
        throw new UnprocessableException('Visit date is required.');
    }
    deployment_require_deployment($deploymentId);

    $stageAtVisit = null;
    if (!empty($data['stage_at_visit'])) {
        $stageAtVisitCandidate = strtolower(trim($data['stage_at_visit']));
        if (!deployment_stage_is_valid($stageAtVisitCandidate)) {
            throw new UnprocessableException('Invalid deployment stage.');
        }
        $stageAtVisit = $stageAtVisitCandidate;
    }
    $summary = trim($data['summary'] ?? '');
    deployment_require_text_size($summary, 'Summary');

    $hoursSpent = null;
    if (isset($data['hours_spent']) && $data['hours_spent'] !== '') {
        $hoursSpent = qt_parse_float($data['hours_spent']);
        if ($hoursSpent === null || $hoursSpent < 0 || $hoursSpent > 999.99) {
            throw new UnprocessableException('Hours spent must be a number between 0 and 999.99.');
        }
    }

    $techUserIds = $data['tech_user_ids'] ?? [];
    if (!is_array($techUserIds)) {
        $techUserIds = [$techUserIds];
    }
    $techIds = [];
    foreach ($techUserIds as $rawTechId) {
        if ($rawTechId === '' || $rawTechId === null) {
            continue;
        }
        $techId = qt_parse_int($rawTechId);
        if ($techId === null || $techId <= 0) {
            throw new UnprocessableException('Technician IDs must be whole numbers.');
        }
        $techIds[$techId] = $techId;
    }
    $techUserIds = array_values($techIds);
    foreach ($techUserIds as $techId) {
        deployment_require_row('users', $techId, 'Technician not found.', true);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $insert = $pdo->prepare("
            INSERT INTO deployment_visits (deployment_id, visit_date, stage_at_visit, summary, hours_spent, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $deploymentId,
            $visitDate,
            $stageAtVisit,
            $summary !== '' ? $summary : null,
            $hoursSpent,
            $userId,
        ]);

        $visitId = (int)$pdo->lastInsertId();

        if (!empty($techUserIds)) {
            $placeholders = [];
            $params = [];
            foreach ($techUserIds as $techUserId) {
                $placeholders[] = '(?, ?)';
                $params[] = $visitId;
                $params[] = $techUserId;
            }

            $techInsert = $pdo->prepare("
                INSERT IGNORE INTO deployment_visit_techs (visit_id, user_id)
                VALUES " . implode(',', $placeholders));
            $techInsert->execute($params);
        }

        AuditLogger::log('deployment.visit_log', [
            'entity_type' => 'deployment',
            'entity_id' => $deploymentId,
            'description' => 'Visit logged',
            'metadata' => [
                'visit_id' => $visitId,
                'visit_date' => $visitDate,
                'stage' => $stageAtVisit,
                'tech_user_ids' => $techUserIds,
            ],
        ]);

        $pdo->commit();
        return deployment_fetch_visits($deploymentId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deployments_add_note(int $deploymentId, string $noteText, int $userId): array
{
    $noteText = trim($noteText);
    if ($noteText === '') {
        throw new UnprocessableException('Note text is required.');
    }
    deployment_require_text_size($noteText, 'Note');
    deployment_require_deployment($deploymentId);

    $pdo = db();
    $insert = $pdo->prepare("
        INSERT INTO deployment_notes (deployment_id, note_text, created_by)
        VALUES (?, ?, ?)
    ");
    $insert->execute([$deploymentId, $noteText, $userId]);

    AuditLogger::log('deployment.note_create', [
        'entity_type' => 'deployment',
        'entity_id' => $deploymentId,
        'description' => 'Note added',
        'metadata' => [
            'note_id' => (int)$pdo->lastInsertId(),
        ],
    ]);

    return deployment_fetch_notes($deploymentId);
}

function deployments_export(array $filters, int $userId): array
{
    $result = deployments_list($filters, 0, 0);

    $pdo = db();
    $insert = $pdo->prepare("INSERT INTO deployment_exports (user_id, filters_json) VALUES (?, ?)");
    $insert->execute([$userId, json_encode($filters)]);

    AuditLogger::log('deployment.export', [
        'entity_type' => 'deployment_export',
        'entity_id' => null,
        'description' => 'Deployment export generated',
        'metadata' => [
            'user_id' => $userId,
            'filters' => $filters,
            'row_count' => count($result['records']),
        ],
    ]);

    return $result['records'];
}

// Quest helpers

function deployment_quests_list(int $deploymentId, array $filters = []): array
{
    $pdo = db();
    $statusFilter = $filters['status'] ?? null;
    $assigneeFilter = isset($filters['assigned_user_id']) ? (int)$filters['assigned_user_id'] : null;

    $where = ['dq.deployment_id = ?'];
    $params = [$deploymentId];

    if ($statusFilter && in_array($statusFilter, DEPLOYMENT_QUEST_STATUS_VALUES, true)) {
        $where[] = 'dq.status = ?';
        $params[] = $statusFilter;
    }

    if ($assigneeFilter) {
        $where[] = 'dq.assigned_user_id = ?';
        $params[] = $assigneeFilter;
    }

    $sql = "
        SELECT dq.*, u.email AS assigned_email, u.role AS assigned_role,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS assigned_display
        FROM deployment_quests dq
        LEFT JOIN users u ON dq.assigned_user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY dq.priority DESC, dq.updated_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quests = $stmt->fetchAll() ?: [];

    if (empty($quests)) {
        return [];
    }

    $questIds = array_column($quests, 'id');

    $objectives = deployment_quest_objectives_map($questIds);
    $logs = deployment_quest_logs_map($questIds, 5);

    foreach ($quests as &$quest) {
        $qid = (int)$quest['id'];
        $quest['objectives'] = $objectives[$qid] ?? [];
        $quest['recent_logs'] = $logs[$qid] ?? [];
        $quest['tags'] = $quest['tags'] ? json_decode($quest['tags'], true) : [];
    }

    return $quests;
}

function deployment_quest_objectives_map(array $questIds): array
{
    if (empty($questIds)) {
        return [];
    }
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($questIds), '?'));
    // arch-ok: sql-interpolation - only the ? placeholder list is put into the SQL
    $stmt = $pdo->prepare("
        SELECT dqo.*, u.email AS updated_by_email,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS updated_by_display
        FROM deployment_quest_objectives dqo
        LEFT JOIN users u ON dqo.updated_by = u.id
        WHERE dqo.quest_id IN ($placeholders)
        ORDER BY dqo.quest_id, dqo.sort_order, dqo.id
    ");
    $stmt->execute($questIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map[(int)$row['quest_id']][] = $row;
    }
    return $map;
}

function deployment_quest_logs_map(array $questIds, int $limitPerQuest = 5): array
{
    if (empty($questIds)) {
        return [];
    }
    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($questIds), '?'));
    // arch-ok: sql-interpolation - only the ? placeholder list is put into the SQL
    $stmt = $pdo->prepare("
        SELECT dql.*, u.email AS user_email,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS user_display
        FROM deployment_quest_logs dql
        LEFT JOIN users u ON dql.user_id = u.id
        WHERE dql.quest_id IN ($placeholders)
        ORDER BY dql.quest_id, dql.id DESC
    ");
    $stmt->execute($questIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $qid = (int)$row['quest_id'];
        if (count($map[$qid] ?? []) >= $limitPerQuest) {
            continue;
        }
        $map[$qid][] = $row;
    }
    return $map;
}

function deployment_quest_get(int $questId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT dq.*, u.email AS assigned_email, u.role AS assigned_role,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS assigned_display
        FROM deployment_quests dq
        LEFT JOIN users u ON dq.assigned_user_id = u.id
        WHERE dq.id = ?
        LIMIT 1
    ");
    $stmt->execute([$questId]);
    $quest = $stmt->fetch();
    if (!$quest) {
        return null;
    }
    $quest['objectives'] = deployment_quest_objectives_map([$questId])[$questId] ?? [];
    $quest['logs'] = deployment_quest_logs_map([$questId], 50)[$questId] ?? [];
    $quest['tags'] = $quest['tags'] ? json_decode($quest['tags'], true) : [];
    return $quest;
}

/**
 * An optional record id from a payload field: null when empty or 0, the id when it is a
 * positive whole number, a 422 for anything else ("12abc", "1.5", out of range).
 */
function deployment_parse_optional_id(mixed $value, string $field): ?int
{
    if ($value === null || $value === '' || $value === 0 || $value === '0') {
        return null;
    }
    $id = qt_parse_int($value);
    if ($id === null || $id <= 0) {
        throw new UnprocessableException("{$field} must be a whole number.");
    }
    return $id;
}

function deployment_quest_create(array $data, int $userId): array
{
    $deploymentId = deployment_parse_optional_id($data['deployment_id'] ?? null, 'Deployment') ?? 0;
    $title = trim($data['title'] ?? '');
    if ($deploymentId <= 0 || $title === '') {
        throw new UnprocessableException('Deployment and title are required.');
    }
    deployment_require_max_length($title, 160, 'Quest title');
    $brief = trim($data['brief'] ?? '');
    deployment_require_text_size($brief, 'Brief');
    $templateId = deployment_parse_optional_id($data['template_id'] ?? null, 'Template');
    $assignedUserId = deployment_parse_optional_id($data['assigned_user_id'] ?? null, 'Assigned user');
    $priorityInput = $data['priority'] ?? null;
    $priority = 0;
    if ($priorityInput !== null && $priorityInput !== '') {
        $priority = qt_parse_int($priorityInput);
        if ($priority === null) {
            throw new UnprocessableException('Priority must be a whole number.');
        }
        deployment_require_int_range($priority, -128, 127, 'Priority');
    }
    deployment_require_deployment($deploymentId);
    if ($templateId !== null) {
        deployment_require_template($templateId);
    }
    if ($assignedUserId !== null) {
        deployment_require_user($assignedUserId);
    }

    $status = strtolower(trim((string)($data['status'] ?? '')));
    if ($status === '') {
        $status = 'available';
    } elseif (!in_array($status, DEPLOYMENT_QUEST_STATUS_VALUES, true)) {
        throw new UnprocessableException('Invalid quest status.');
    }
    $dueDate = !empty($data['due_date']) ? deployment_normalize_date($data['due_date']) : null;
    $questTags = normalize_template_tags($data['tags'] ?? []);
    $objectiveRows = [];
    if (!empty($data['objectives']) && is_array($data['objectives'])) {
        foreach (array_values($data['objectives']) as $idx => $objective) {
            if (!is_array($objective)) {
                throw new UnprocessableException('Each objective must be an object with a label.');
            }
            $label = trim((string)($objective['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            deployment_require_max_length($label, 160, 'Objective label');
            $description = trim((string)($objective['description'] ?? ''));
            deployment_require_text_size($description, 'Objective description');
            $sortOrder = isset($objective['sort_order']) ? qt_parse_int($objective['sort_order']) : (($idx + 1) * 10);
            if ($sortOrder === null) {
                throw new UnprocessableException('Objective sort order must be a whole number.');
            }
            deployment_require_int_range($sortOrder, -2147483648, 2147483647, 'Objective sort order');
            $objectiveStatus = (string)($objective['status'] ?? '');
            $objectiveRows[] = [
                $label,
                $description !== '' ? $description : null,
                $sortOrder,
                in_array($objectiveStatus, DEPLOYMENT_QUEST_OBJECTIVE_STATUS_VALUES, true) ? $objectiveStatus : 'not_started',
            ];
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO deployment_quests
            (deployment_id, template_id, title, brief, status, priority, assigned_user_id, tags, due_date, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $tagsJson = $questTags ? json_encode($questTags, JSON_THROW_ON_ERROR) : null;

        $stmt->execute([
            $deploymentId,
            $templateId,
            $title,
            $brief !== '' ? $brief : null,
            $status,
            $priority,
            $assignedUserId,
            $tagsJson,
            $dueDate,
            $userId,
            $userId,
        ]);

        $questId = (int)$pdo->lastInsertId();

        // Clone objectives if provided
        if (!empty($data['objectives']) && is_array($data['objectives'])) {
            $insertObj = $pdo->prepare("
                INSERT INTO deployment_quest_objectives (quest_id, label, description, sort_order, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($objectiveRows as $row) {
                $insertObj->execute([$questId, ...$row]);
            }
        } elseif ($templateId !== null) {
            deployment_clone_template_objectives_to_quest($templateId, $questId);
        }

        deployment_quest_add_log($questId, $userId, 'update', 'Quest created', []);

        AuditLogger::log('deployment.quest_create', [
            'entity_type' => 'deployment_quest',
            'entity_id' => $questId,
            'description' => 'Quest created',
            'metadata' => [
                'deployment_id' => $deploymentId,
                'title' => $title,
                'status' => $status,
            ],
        ]);

        $pdo->commit();
        return deployment_quest_get($questId) ?? [];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function deployment_clone_template_objectives_to_quest(int $templateId, int $questId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO deployment_quest_objectives (quest_id, label, description, sort_order, status)
        SELECT ?, label, description, sort_order, 'not_started'
        FROM deployment_quest_template_objectives
        WHERE template_id = ?
        ORDER BY sort_order, id
    ");
    $stmt->execute([$questId, $templateId]);
}

function deployment_quest_update_status(int $questId, string $status, ?string $blockerReason, int $userId): array
{
    if (!in_array($status, DEPLOYMENT_QUEST_STATUS_VALUES, true)) {
        throw new UnprocessableException('Invalid quest status.');
    }
    if ($blockerReason !== null) {
        deployment_require_text_size($blockerReason, 'Blocker reason');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // The row stays locked from the comparison to the update, so two identical requests
        // cannot both see the old value and both record a transition.
        $currentStmt = $pdo->prepare('SELECT status, blocker_reason FROM deployment_quests WHERE id = ? FOR UPDATE');
        $currentStmt->execute([$questId]);
        $current = $currentStmt->fetch();
        if (!$current) {
            throw new NotFoundException('Quest not found.');
        }
        $changed = $current['status'] !== $status || ($current['blocker_reason'] ?? null) !== $blockerReason;
        if ($changed) {
            $pdo->prepare("UPDATE deployment_quests SET status = ?, blocker_reason = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$status, $blockerReason, $userId, $questId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Re-submitting the current status (a double click) changes nothing: answer 200 with the
    // quest as it is, without a second transition in the activity log or audit trail.
    if ($changed) {
        $logType = $status === 'blocked' ? 'blocker' : ($status === 'complete' ? 'completion' : 'update');
        deployment_quest_add_log($questId, $userId, $logType, "Status changed to {$status}", [
            'blocker_reason' => $blockerReason,
        ]);
        AuditLogger::log('deployment.quest_status', [
            'entity_type' => 'deployment_quest',
            'entity_id' => $questId,
            'description' => 'Quest status updated',
            'metadata' => [
                'status' => $status,
                'blocker_reason' => $blockerReason,
            ],
        ]);
    }
    return deployment_quest_get($questId) ?? [];
}

function deployment_quest_assign(int $questId, ?int $assignedUserId, int $actorId): array
{
    if ($assignedUserId) {
        deployment_require_user($assignedUserId);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Locked comparison and update, as in deployment_quest_update_status().
        $currentStmt = $pdo->prepare('SELECT assigned_user_id FROM deployment_quests WHERE id = ? FOR UPDATE');
        $currentStmt->execute([$questId]);
        $current = $currentStmt->fetch();
        if (!$current) {
            throw new NotFoundException('Quest not found.');
        }
        $currentAssignee = $current['assigned_user_id'] === null ? null : (int)$current['assigned_user_id'];
        $changed = $currentAssignee !== ($assignedUserId ?: null);
        if ($changed) {
            $pdo->prepare("UPDATE deployment_quests SET assigned_user_id = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$assignedUserId ?: null, $actorId, $questId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // The same assignee again (a double click on Take/Leave Quest) is a 200 with no new entry.
    if ($changed) {
        deployment_quest_add_log($questId, $actorId, 'handoff', $assignedUserId ? 'Assigned to user' : 'Assignment cleared', [
            'assigned_user_id' => $assignedUserId,
        ]);
        AuditLogger::log('deployment.quest_assign', [
            'entity_type' => 'deployment_quest',
            'entity_id' => $questId,
            'description' => 'Quest assignment updated',
            'metadata' => [
                'assigned_user_id' => $assignedUserId,
            ],
        ]);
    }
    return deployment_quest_get($questId) ?? [];
}

function deployment_quest_update_objective(int $objectiveId, array $payload, int $userId): array
{
    $statusInput = strtolower(trim((string)($payload['status'] ?? '')));
    if ($statusInput !== '' && !in_array($statusInput, DEPLOYMENT_QUEST_OBJECTIVE_STATUS_VALUES, true)) {
        throw new UnprocessableException('Invalid objective status.');
    }
    $hasDescription = array_key_exists('description', $payload);
    $descriptionInput = trim((string)($payload['description'] ?? ''));
    if ($hasDescription) {
        deployment_require_text_size($descriptionInput, 'Description');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Locked read-compare-write: a repeated identical update (a double click) is a 200 that
        // records nothing, and a field the request did not send keeps its stored value.
        $stmt = $pdo->prepare('SELECT quest_id, status, description FROM deployment_quest_objectives WHERE id = ? FOR UPDATE');
        $stmt->execute([$objectiveId]);
        $current = $stmt->fetch();
        if (!$current) {
            throw new NotFoundException('Objective not found.');
        }
        $questId = (int)$current['quest_id'];
        $status = $statusInput !== '' ? $statusInput : (string)$current['status'];
        $description = $hasDescription ? ($descriptionInput !== '' ? $descriptionInput : null) : $current['description'];
        $changed = $status !== $current['status'] || $description !== $current['description'];
        if ($changed) {
            $pdo->prepare('UPDATE deployment_quest_objectives SET status = ?, description = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$status, $description, $userId, $objectiveId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($changed) {
        deployment_quest_add_log($questId, $userId, 'update', 'Objective updated', [
            'objective_id' => $objectiveId,
            'status' => $status,
        ]);
    }
    return deployment_quest_get($questId) ?? [];
}

function deployment_quest_add_log(int $questId, ?int $userId, string $entryType, string $message, array $metadata = []): void
{
    if (!in_array($entryType, DEPLOYMENT_QUEST_LOG_TYPES, true)) {
        $entryType = 'update';
    }
    deployment_require_text_size($message, 'Message');
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO deployment_quest_logs (quest_id, user_id, entry_type, message, metadata) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $questId,
        $userId ?: null,
        $entryType,
        $message,
        !empty($metadata) ? json_encode($metadata) : null,
    ]);
}

function deployment_quest_templates_list(): array
{
    $pdo = db();
    $stmt = $pdo->query("SELECT * FROM deployment_quest_templates WHERE is_active = 1 ORDER BY title ASC");
    $templates = $stmt->fetchAll() ?: [];
    if (empty($templates)) {
        return [];
    }
    $templateIds = array_column($templates, 'id');
    $objectives = [];
    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    // arch-ok: sql-interpolation - only the ? placeholder list is put into the SQL
    $objStmt = $pdo->prepare("SELECT * FROM deployment_quest_template_objectives WHERE template_id IN ($placeholders) ORDER BY template_id, sort_order, id");
    $objStmt->execute($templateIds);
    foreach ($objStmt->fetchAll() ?: [] as $row) {
        $objectives[(int)$row['template_id']][] = $row;
    }
    foreach ($templates as &$tpl) {
        $tpl['objectives'] = $objectives[(int)$tpl['id']] ?? [];
        $tpl['default_tags'] = $tpl['default_tags'] ? json_decode($tpl['default_tags'], true) : [];
    }
    return $templates;
}

function deployment_quest_template_get(int $templateId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM deployment_quest_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    if (!$template) {
        return null;
    }
    $template['default_tags'] = $template['default_tags'] ? json_decode($template['default_tags'], true) : [];
    $objStmt = $pdo->prepare("SELECT * FROM deployment_quest_template_objectives WHERE template_id = ? ORDER BY sort_order, id");
    $objStmt->execute([$templateId]);
    $template['objectives'] = $objStmt->fetchAll() ?: [];
    return $template;
}

/**
 * The (template_id, label) unique key is also hit by labels the collation treats as equal
 * that the pre-check cannot predict; report those as the same 422.
 */
function deployment_translate_duplicate_label(Throwable $e): Throwable
{
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        return new UnprocessableException('Objective labels must be unique within a template.');
    }
    return $e;
}

function normalize_template_tags(mixed $value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $parts = $decoded;
        } else {
            $parts = explode(',', $trimmed);
        }
    } elseif (is_array($value)) {
        $parts = $value;
    } else {
        return [];
    }
    foreach ($parts as $part) {
        if (!is_string($part)) {
            throw new UnprocessableException('Tags must be text.');
        }
        deployment_require_max_length($part, 100, 'Tag');
    }
    $parts = array_filter(array_map('trim', $parts));
    return array_values(array_unique($parts));
}

function normalize_template_objectives(mixed $raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $objectives = [];
    $seenLabels = [];
    foreach (array_values($raw) as $idx => $objective) {
        if (!is_array($objective)) {
            continue;
        }
        foreach (['label', 'description'] as $textKey) {
            if (isset($objective[$textKey]) && !is_string($objective[$textKey])) {
                throw new UnprocessableException("Objective {$textKey} must be text.");
            }
        }
        $label = trim($objective['label'] ?? '');
        if ($label === '') {
            continue;
        }
        deployment_require_max_length($label, 160, 'Objective label');
        $description = trim($objective['description'] ?? '');
        deployment_require_text_size($description, 'Objective description');
        $sortOrder = isset($objective['sort_order']) ? qt_parse_int($objective['sort_order']) : (($idx + 1) * 10);
        if ($sortOrder === null) {
            throw new UnprocessableException('Objective sort order must be a whole number.');
        }
        deployment_require_int_range($sortOrder, -2147483648, 2147483647, 'Objective sort order');
        $labelKey = mb_strtolower($label);
        if (isset($seenLabels[$labelKey])) {
            throw new UnprocessableException("Objective labels must be unique within a template (duplicate: {$label}).");
        }
        $seenLabels[$labelKey] = true;
        $objectives[] = [
            'label' => $label,
            'description' => $description !== '' ? $description : null,
            'sort_order' => $sortOrder,
            'requires_review' => !empty($objective['requires_review']) ? 1 : 0,
        ];
    }
    return $objectives;
}

function deployment_quest_template_create(array $data, int $userId): array
{
    $title = trim($data['title'] ?? '');
    if ($title === '') {
        throw new UnprocessableException('Template title required.');
    }
    deployment_require_max_length($title, 160, 'Template title');
    deployment_require_max_length(trim($data['recommended_role'] ?? ''), 80, 'Recommended role');
    deployment_require_max_length(trim($data['auto_assign_role'] ?? ''), 80, 'Auto-assign role');
    deployment_require_text_size(trim($data['brief'] ?? ''), 'Brief');
    $tags = normalize_template_tags($data['default_tags'] ?? []);
    $objectives = normalize_template_objectives($data['objectives'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO deployment_quest_templates (title, brief, default_tags, recommended_role, auto_assign_role, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $title,
            trim($data['brief'] ?? '') ?: null,
            $tags ? json_encode($tags, JSON_THROW_ON_ERROR) : null,
            trim($data['recommended_role'] ?? '') ?: null,
            trim($data['auto_assign_role'] ?? '') ?: null,
            $userId,
            $userId,
        ]);
        $templateId = (int)$pdo->lastInsertId();
        if (!empty($objectives)) {
            $insertObj = $pdo->prepare("INSERT INTO deployment_quest_template_objectives (template_id, label, description, sort_order, requires_review) VALUES (?, ?, ?, ?, ?)");
            foreach ($objectives as $objective) {
                $insertObj->execute([
                    $templateId,
                    $objective['label'],
                    $objective['description'],
                    (int)$objective['sort_order'],
                    (int)$objective['requires_review'],
                ]);
            }
        }
        $pdo->commit();
        return deployment_quest_templates_list();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw deployment_translate_duplicate_label($e);
    }
}

function deployment_quest_template_update(int $templateId, array $data, int $userId): array
{
    $title = trim($data['title'] ?? '');
    if ($templateId <= 0 || $title === '') {
        throw new UnprocessableException('Template ID and title required.');
    }
    deployment_require_max_length($title, 160, 'Template title');
    deployment_require_max_length(trim($data['recommended_role'] ?? ''), 80, 'Recommended role');
    deployment_require_max_length(trim($data['auto_assign_role'] ?? ''), 80, 'Auto-assign role');
    deployment_require_text_size(trim($data['brief'] ?? ''), 'Brief');
    $tags = normalize_template_tags($data['default_tags'] ?? []);
    $objectives = normalize_template_objectives($data['objectives'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $exists = $pdo->prepare('SELECT 1 FROM deployment_quest_templates WHERE id = ? FOR UPDATE');
        $exists->execute([$templateId]);
        if ($exists->fetchColumn() === false) {
            throw new NotFoundException('Template not found.');
        }
        $stmt = $pdo->prepare("UPDATE deployment_quest_templates SET title = ?, brief = ?, default_tags = ?, recommended_role = ?, auto_assign_role = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([
            $title,
            trim($data['brief'] ?? '') ?: null,
            $tags ? json_encode($tags, JSON_THROW_ON_ERROR) : null,
            trim($data['recommended_role'] ?? '') ?: null,
            trim($data['auto_assign_role'] ?? '') ?: null,
            $userId,
            $templateId,
        ]);
        $pdo->prepare("DELETE FROM deployment_quest_template_objectives WHERE template_id = ?")->execute([$templateId]);
        if (!empty($objectives)) {
            $insertObj = $pdo->prepare("INSERT INTO deployment_quest_template_objectives (template_id, label, description, sort_order, requires_review) VALUES (?, ?, ?, ?, ?)");
            foreach ($objectives as $objective) {
                $insertObj->execute([
                    $templateId,
                    $objective['label'],
                    $objective['description'],
                    (int)$objective['sort_order'],
                    (int)$objective['requires_review'],
                ]);
            }
        }
        $pdo->commit();
        return deployment_quest_templates_list();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw deployment_translate_duplicate_label($e);
    }
}

function deployment_quest_template_archive(int $templateId, int $userId): array
{
    if ($templateId <= 0) {
        throw new UnprocessableException('Template ID required.');
    }
    // Existence is checked separately: archiving an already archived template changes no row.
    deployment_require_template($templateId);
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE deployment_quest_templates SET is_active = 0, updated_by = ? WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId, $templateId]);
    return deployment_quest_templates_list();
}

function deployment_quest_template_duplicate(int $templateId, array $data, int $userId): array
{
    $template = deployment_quest_template_get($templateId);
    if (!$template || (int)$template['is_active'] === 0) {
        throw new NotFoundException('Template not found.');
    }
    $newTitle = trim($data['title'] ?? '') ?: ($template['title'] . ' Copy');
    $payload = [
        'title' => $newTitle,
        'brief' => $data['brief'] ?? $template['brief'],
        'default_tags' => !empty($data['default_tags']) ? $data['default_tags'] : ($template['default_tags'] ?? []),
        'recommended_role' => $data['recommended_role'] ?? $template['recommended_role'],
        'auto_assign_role' => $data['auto_assign_role'] ?? $template['auto_assign_role'],
        'objectives' => $data['objectives'] ?? array_map(function ($objective) {
            return [
                'label' => $objective['label'],
                'description' => $objective['description'] ?? null,
                'requires_review' => $objective['requires_review'] ?? 0,
                'sort_order' => $objective['sort_order'] ?? 0,
            ];
        }, $template['objectives'] ?? []),
    ];
    return deployment_quest_template_create($payload, $userId);
}

function deployment_quest_create_from_template(int $deploymentId, int $templateId, int $userId, array $overrides = []): array
{
    $pdo = db();
    $tplStmt = $pdo->prepare("SELECT * FROM deployment_quest_templates WHERE id = ? AND is_active = 1");
    $tplStmt->execute([$templateId]);
    $template = $tplStmt->fetch();
    if (!$template) {
        throw new NotFoundException('Template not found.');
    }

    $data = [
        'deployment_id' => $deploymentId,
        'template_id' => $templateId,
        'title' => $overrides['title'] ?? $template['title'],
        'brief' => $overrides['brief'] ?? $template['brief'],
        'status' => $template['default_status'] ?? 'available',
        'tags' => json_decode($template['default_tags'] ?? '[]', true) ?: [],
        'assigned_user_id' => $overrides['assigned_user_id'] ?? null,
        'due_date' => $overrides['due_date'] ?? null,
    ];

    return deployment_quest_create($data, $userId);
}

/**
 * Stores whose name, store number or project number contains $query (at most 15, by name).
 *
 * @return list<array<string, mixed>>
 */
function deployments_search_local_stores(string $query): array
{
    $term = '%' . $query . '%';
    $stmt = db()->prepare('SELECT id, name, store_number, project_number, address, region_id
        FROM stores
        WHERE name LIKE ? OR store_number LIKE ? OR project_number LIKE ?
        ORDER BY name ASC
        LIMIT 15');
    $stmt->execute([$term, $term, $term]);
    return $stmt->fetchAll() ?: [];
}
