<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Form Builder service layer (MVP)
 *
 * Security notes:
 * - All DB access uses prepared statements.
 * - Validate and normalize all inputs.
 * - Never store raw invite tokens; only store SHA-256 token_hash.
 */

function uuid_v4(): string
{
    $data = random_bytes(16);
    // Set version to 0100
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function form_field_types(): array
{
    return [
        'short_text',
        'long_text',
        'number',
        'date',
        'yes_no',
        'single_choice',
        'multi_choice',
    ];
}

function form_create(int $ownerUserId, string $title, ?string $description = null): int
{
    $title = trim($title);
    if ($title === '') {
        throw new InvalidArgumentException('Form title is required.');
    }

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO forms (owner_user_id, title, description, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([$ownerUserId, $title, $description !== '' ? $description : null, 'draft']);
    return (int)$pdo->lastInsertId();
}

/**
 * Copy questions/options from one form to another.
 * Only copies non-deleted questions.
 */
function form_clone_questions(int $sourceFormId, int $targetFormId): void
{
    $sourceFormId = (int)$sourceFormId;
    $targetFormId = (int)$targetFormId;
    if ($sourceFormId <= 0 || $targetFormId <= 0) {
        throw new InvalidArgumentException('Invalid form id.');
    }

    $pdo = db();
    $q = $pdo->prepare('
        SELECT type, label, help_text, is_required, position, options_json, validation_json
        FROM form_questions
        WHERE form_id = ? AND is_deleted = 0
        ORDER BY position ASC, id ASC
    ');
    $q->execute([$sourceFormId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        form_add_question($targetFormId, [
            'type' => (string)$r['type'],
            'label' => (string)$r['label'],
            'help_text' => $r['help_text'] !== null ? (string)$r['help_text'] : '',
            'is_required' => (int)$r['is_required'] === 1,
            'position' => (int)$r['position'],
            // options_json/validation_json are stored as JSON strings in DB; form_add_question accepts strings.
            'options_json' => $r['options_json'],
            'validation_json' => $r['validation_json'],
        ]);
    }
}

function form_add_question(int $formId, array $data): int
{
    $type = strtolower(trim((string)($data['type'] ?? '')));
    $label = trim((string)($data['label'] ?? ''));
    $help = trim((string)($data['help_text'] ?? ''));
    $required = !empty($data['is_required']) ? 1 : 0;
    $position = (int)($data['position'] ?? 0);

    if ($label === '') {
        throw new InvalidArgumentException('Question label is required.');
    }
    if (!in_array($type, form_field_types(), true)) {
        throw new InvalidArgumentException('Invalid question type.');
    }

    $optionsJson = $data['options_json'] ?? null;
    $validationJson = $data['validation_json'] ?? null;

    // Normalize JSON inputs
    if (is_array($optionsJson)) {
        $optionsJson = json_encode($optionsJson, JSON_UNESCAPED_SLASHES);
    }
    if (is_array($validationJson)) {
        $validationJson = json_encode($validationJson, JSON_UNESCAPED_SLASHES);
    }

    $questionKey = uuid_v4();

    $pdo = db();
    $stmt = $pdo->prepare('
        INSERT INTO form_questions
            (form_id, question_key, type, label, help_text, is_required, position, options_json, validation_json)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $formId,
        $questionKey,
        $type,
        $label,
        $help !== '' ? $help : null,
        $required,
        $position,
        $optionsJson,
        $validationJson
    ]);

    return (int)$pdo->lastInsertId();
}

function form_update_question(int $questionId, array $data): void
{
    $questionId = (int)$questionId;
    if ($questionId <= 0) {
        throw new InvalidArgumentException('Invalid question.');
    }

    $type = strtolower(trim((string)($data['type'] ?? '')));
    $label = trim((string)($data['label'] ?? ''));
    $help = trim((string)($data['help_text'] ?? ''));
    $required = !empty($data['is_required']) ? 1 : 0;
    $position = isset($data['position']) ? (int)$data['position'] : 0;

    if ($label === '') {
        throw new InvalidArgumentException('Question label is required.');
    }
    if (!in_array($type, form_field_types(), true)) {
        throw new InvalidArgumentException('Invalid question type.');
    }

    $optionsJson = $data['options_json'] ?? null;
    $validationJson = $data['validation_json'] ?? null;

    if (is_array($optionsJson)) {
        $optionsJson = json_encode($optionsJson, JSON_UNESCAPED_SLASHES);
    }
    if (is_array($validationJson)) {
        $validationJson = json_encode($validationJson, JSON_UNESCAPED_SLASHES);
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        UPDATE form_questions
        SET type = ?, label = ?, help_text = ?, is_required = ?, position = ?, options_json = ?, validation_json = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $stmt->execute([
        $type,
        $label,
        $help !== '' ? $help : null,
        $required,
        $position,
        $optionsJson,
        $validationJson,
        $questionId,
    ]);
}

function form_delete_question(int $questionId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE form_questions SET is_deleted = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([(int)$questionId]);
}

function form_move_question(int $formId, int $questionId, string $direction): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, position FROM form_questions WHERE id = ? AND form_id = ? AND is_deleted = 0');
    $stmt->execute([$questionId, $formId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        throw new RuntimeException('Question not found.');
    }

    $pos = (int)$current['position'];

    if ($direction === 'up') {
        $stmt = $pdo->prepare('SELECT id, position FROM form_questions WHERE form_id = ? AND is_deleted = 0 AND (position < ? OR (position = ? AND id < ?)) ORDER BY position DESC, id DESC LIMIT 1');
        $stmt->execute([$formId, $pos, $pos, $questionId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, position FROM form_questions WHERE form_id = ? AND is_deleted = 0 AND (position > ? OR (position = ? AND id > ?)) ORDER BY position ASC, id ASC LIMIT 1');
        $stmt->execute([$formId, $pos, $pos, $questionId]);
    }

    $neighbor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$neighbor) {
        return; // Already at edge
    }

    $pdo->beginTransaction();
    // Swap positions
    $upd = $pdo->prepare('UPDATE form_questions SET position = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $upd->execute([(int)$neighbor['position'], $questionId]);
    $upd->execute([$pos, (int)$neighbor['id']]);
    $pdo->commit();
}

function form_campaign_create_and_snapshot(int $formId, int $createdByUserId, array $data): int
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Campaign name is required.');
    }

    $deploymentProjectId = isset($data['deployment_project_id']) && $data['deployment_project_id'] !== ''
        ? (int)$data['deployment_project_id']
        : null;

    $deploymentQuestId = isset($data['deployment_quest_id']) && $data['deployment_quest_id'] !== ''
        ? (int)$data['deployment_quest_id']
        : null;

    $externalProjectId = trim((string)($data['external_project_id'] ?? ''));
    $siteName = trim((string)($data['site_name'] ?? ''));

    $dueAt = trim((string)($data['due_at'] ?? ''));
    $dueAt = $dueAt !== '' ? $dueAt : null;

    $pdo = db();

    $pdo->beginTransaction();
    try {
        $campaignId = form_campaign_insert_with_questions($pdo, $formId, $createdByUserId, [
            $deploymentProjectId, $deploymentQuestId, $externalProjectId, $siteName, $name, $dueAt,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $campaignId;
}

/**
 * Insert a campaign and snapshot the form's active questions (the caller owns the transaction).
 *
 * @param array{0: ?int, 1: ?int, 2: string, 3: string, 4: string, 5: ?string} $context deployment id, quest id,
 *        external project id, site name, campaign name and due date
 */
function form_campaign_insert_with_questions(PDO $pdo, int $formId, int $createdByUserId, array $context): int
{
    [$deploymentProjectId, $deploymentQuestId, $externalProjectId, $siteName, $name, $dueAt] = $context;
    $stmt = $pdo->prepare('
        INSERT INTO form_campaigns
            (form_id, created_by_user_id, deployment_project_id, deployment_quest_id, external_project_id, site_name, name, status, snapshot_mode, due_at, close_grace_days)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $formId,
        $createdByUserId,
        $deploymentProjectId,
        $deploymentQuestId,
        $externalProjectId !== '' ? $externalProjectId : null,
        $siteName !== '' ? $siteName : null,
        $name,
        'active',
        // New campaigns default to live snapshots while active.
        'live',
        $dueAt,
        30,
    ]);

    $campaignId = (int)$pdo->lastInsertId();

    // Snapshot active questions (non-deleted)
    $q = $pdo->prepare('
        SELECT id, question_key, type, label, help_text, is_required, position, options_json, validation_json
        FROM form_questions
        WHERE form_id = ? AND is_deleted = 0
        ORDER BY position ASC, id ASC
    ');
    $q->execute([$formId]);
    $questions = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare('
        INSERT INTO form_campaign_questions
            (campaign_id, source_question_id, question_key, type, label, help_text, is_required, position, options_json, validation_json)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($questions as $row) {
        $ins->execute([
            $campaignId,
            (int)$row['id'],
            (string)$row['question_key'],
            (string)$row['type'],
            (string)$row['label'],
            $row['help_text'] !== null ? (string)$row['help_text'] : null,
            (int)$row['is_required'],
            (int)$row['position'],
            $row['options_json'],
            $row['validation_json'],
        ]);
    }

    return $campaignId;
}

/**
 * Resnapshot (refresh) campaign questions from the source form.
 *
 * NOTE: This intentionally breaks the "frozen snapshot" behavior.
 * It keeps campaign questions aligned with the latest form definition.
 *
 * Strategy:
 * - Upsert by (campaign_id, question_key) so campaign_question_id stays stable when possible.
 * - Only active (non-deleted) form questions are inserted/updated.
 * - We do NOT delete old campaign questions because responses may reference them.
 */
function form_campaign_resnapshot_questions(int $campaignId): void
{
    $pdo = db();

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT form_id FROM form_campaigns WHERE id = ?');
    $stmt->execute([$campaignId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        throw new RuntimeException('Campaign not found');
    }
    $formId = (int)$row['form_id'];

    $q = $pdo->prepare('
        SELECT id, question_key, type, label, help_text, is_required, position, options_json, validation_json
        FROM form_questions
        WHERE form_id = ? AND is_deleted = 0
        ORDER BY position ASC, id ASC
    ');
    $q->execute([$formId]);
    $questions = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $upsert = $pdo->prepare('
        INSERT INTO form_campaign_questions
            (campaign_id, source_question_id, question_key, type, label, help_text, is_required, position, options_json, validation_json)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            source_question_id = VALUES(source_question_id),
            type = VALUES(type),
            label = VALUES(label),
            help_text = VALUES(help_text),
            is_required = VALUES(is_required),
            position = VALUES(position),
            options_json = VALUES(options_json),
            validation_json = VALUES(validation_json)
    ');

    foreach ($questions as $r) {
        $upsert->execute([
            $campaignId,
            (int)$r['id'],
            (string)$r['question_key'],
            (string)$r['type'],
            (string)$r['label'],
            $r['help_text'] !== null ? (string)$r['help_text'] : null,
            (int)$r['is_required'],
            (int)$r['position'],
            $r['options_json'],
            $r['validation_json'],
        ]);
    }

    $pdo->commit();
}
