<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/form_builder_service.php';
require_once __DIR__ . '/csv_export.php';

/** Days a new public share link stays valid. */
const FORMS_SHARE_LINK_DAYS = 30;

/** Seconds a request waits for another request creating the same campaign. */
const FORMS_CAMPAIGN_LOCK_WAIT = 10;

/** Most share links listed for one campaign. */
const FORMS_SHARE_LINK_LIST_LIMIT = 50;

/**
 * @return list<array<string, mixed>>
 */
function forms_list(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, title, status FROM forms WHERE status IN ('draft','published') ORDER BY updated_at DESC, id DESC LIMIT 500");
    return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param 'forms'|'form_campaigns'|'form_share_links' $table
 * @throws NotFoundException when no such row
 */
function forms_require_row(PDO $pdo, string $table, int $id, string $label): void
{
    $sql = match ($table) {
        'forms' => 'SELECT id FROM forms WHERE id = ?',
        'form_campaigns' => 'SELECT id FROM form_campaigns WHERE id = ?',
        'form_share_links' => 'SELECT id FROM form_share_links WHERE id = ?',
    };
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new NotFoundException("{$label} not found.");
    }
}

/**
 * The campaign of a form on a deployment: the newest existing one, else a new one. Requests for the same
 * pair are serialized by a database lock so two clicks cannot create two campaigns.
 *
 * @return array{campaign_id: int, created: bool}
 * @throws NotFoundException|ConflictException
 */
function forms_resolve_campaign(PDO $pdo, int $userId, int $formId, int $deploymentId): array
{
    forms_require_row($pdo, 'forms', $formId, 'Form');
    $deployment = $pdo->prepare('SELECT site_name, project_code FROM deployment_projects WHERE id = ?');
    $deployment->execute([$deploymentId]);
    $project = $deployment->fetch(PDO::FETCH_ASSOC);
    if (!$project) {
        throw new NotFoundException('Deployment not found.');
    }

    $lockName = "quacktrack_form_campaign_{$formId}_{$deploymentId}";
    $lock = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $lock->execute([$lockName, FORMS_CAMPAIGN_LOCK_WAIT]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new ConflictException('This campaign is being created by another request. Please try again.');
    }
    try {
        return forms_resolve_campaign_locked($pdo, $userId, $formId, $deploymentId, $project);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

/**
 * @param array<string, mixed> $project the deployment's site_name and project_code
 * @return array{campaign_id: int, created: bool}
 */
function forms_resolve_campaign_locked(PDO $pdo, int $userId, int $formId, int $deploymentId, array $project): array
{
    $stmt = $pdo->prepare('SELECT id FROM form_campaigns
        WHERE form_id = ? AND deployment_project_id = ? AND deployment_quest_id IS NULL
        ORDER BY id DESC LIMIT 1');
    $stmt->execute([$formId, $deploymentId]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return ['campaign_id' => $existingId, 'created' => false];
    }

    $siteName = (string)($project['site_name'] ?? '');
    $name = trim($siteName) !== '' ? trim($siteName) : 'Deployment Progress Report';
    $campaignId = form_campaign_create_and_snapshot($formId, $userId, [
        'name' => $name,
        'deployment_project_id' => $deploymentId,
        'deployment_quest_id' => null,
        'site_name' => $siteName,
        'external_project_id' => (string)($project['project_code'] ?? ''),
    ]);
    AuditLogger::log('forms.campaigns.manage', [
        'entity_type' => 'form_campaign',
        'entity_id' => $campaignId,
        'description' => 'Created form campaign for deployment',
        'metadata' => ['form_id' => $formId, 'deployment_project_id' => $deploymentId],
    ]);
    return ['campaign_id' => $campaignId, 'created' => true];
}

/**
 * Create a public share link and return its URL (the token is never stored, only its hash).
 *
 * @return array{url: string, expires_at: string}
 * @throws NotFoundException
 */
function forms_create_share_link(PDO $pdo, int $userId, int $campaignId): array
{
    forms_require_row($pdo, 'form_campaigns', $campaignId, 'Campaign');
    // A missing production URL must fail before persisting an unusable token/link.
    $baseUrl = require_app_base_url();
    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . FORMS_SHARE_LINK_DAYS . ' days')->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO form_share_links (campaign_id, token_hash, expires_at, created_by_user_id) VALUES (?, ?, ?, ?)')
        ->execute([$campaignId, hash('sha256', $token), $expiresAt, $userId]);
    AuditLogger::log('forms.invites.send', [
        'entity_type' => 'form_share_link',
        'entity_id' => (int)$pdo->lastInsertId(),
        'description' => 'Created public form share link',
        'metadata' => ['campaign_id' => $campaignId, 'expires_at' => $expiresAt],
    ]);
    return ['url' => $baseUrl . '/public_form?t=' . urlencode($token), 'expires_at' => $expiresAt];
}

/**
 * @return list<array<string, mixed>>
 * @throws NotFoundException
 */
function forms_list_share_links(PDO $pdo, int $campaignId): array
{
    forms_require_row($pdo, 'form_campaigns', $campaignId, 'Campaign');
    $stmt = $pdo->prepare('SELECT id, expires_at, revoked_at, created_at FROM form_share_links
        WHERE campaign_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . FORMS_SHARE_LINK_LIST_LIMIT);
    $stmt->execute([$campaignId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Revoke a link. Revoking a link that is already revoked changes nothing (the first revocation time stays).
 *
 * @throws NotFoundException
 */
function forms_revoke_share_link(PDO $pdo, int $linkId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT revoked_at FROM form_share_links WHERE id = ? FOR UPDATE');
        $stmt->execute([$linkId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            throw new NotFoundException('Link not found.');
        }
        $alreadyRevoked = $link['revoked_at'] !== null;
        if (!$alreadyRevoked) {
            $pdo->prepare('UPDATE form_share_links SET revoked_at = NOW() WHERE id = ?')->execute([$linkId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    if (!$alreadyRevoked) {
        AuditLogger::log('forms.invites.send', [
            'entity_type' => 'form_share_link',
            'entity_id' => $linkId,
            'description' => 'Revoked public form share link',
            'metadata' => [],
        ]);
    }
}

/** An answer as one cell: the list of a multiple choice joined with commas, else the text. @param array<string, mixed> $answer */
function forms_answer_text(array $answer): string
{
    if (empty($answer['answer_json'])) {
        return (string)($answer['answer_text'] ?? '');
    }
    $decoded = json_decode((string)$answer['answer_json'], true);
    if (!is_array($decoded)) {
        return (string)$answer['answer_json'];
    }
    return implode(', ', array_map(
        static fn(mixed $part): string => is_scalar($part) ? (string)$part : (string)json_encode($part),
        $decoded
    ));
}

/**
 * Build the wide export (one row per response, one column per question) in a temporary stream, so a failure
 * halfway cannot leave a truncated file with a success status.
 *
 * @return resource
 * @throws NotFoundException
 */
function forms_export_wide(PDO $pdo, int $campaignId)
{
    forms_require_row($pdo, 'form_campaigns', $campaignId, 'Campaign');
    $q = $pdo->prepare('SELECT id, label FROM form_campaign_questions WHERE campaign_id = ? ORDER BY position ASC, id ASC');
    $q->execute([$campaignId]);
    $questions = $q->fetchAll(PDO::FETCH_ASSOC);

    $out = forms_export_stream();
    csv_write_row($out, array_merge(
        ['response_id', 'respondent_type', 'respondent_email', 'respondent_user_id', 'deployment_visit_id', 'submitted_at'],
        array_map(static fn(array $row): string => (string)$row['label'], $questions)
    ));

    $r = $pdo->prepare('SELECT id, respondent_type, respondent_email, respondent_user_id, deployment_visit_id, submitted_at
        FROM form_responses WHERE campaign_id = ? ORDER BY submitted_at DESC, id DESC LIMIT 5000');
    $r->execute([$campaignId]);
    $answerStmt = $pdo->prepare('SELECT campaign_question_id, answer_text, answer_json FROM form_response_answers WHERE response_id = ?');
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $resp) {
        $answerStmt->execute([(int)$resp['id']]);
        $byQuestion = [];
        foreach ($answerStmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $byQuestion[(int)$answer['campaign_question_id']] = forms_answer_text($answer);
        }
        $row = [
            (string)$resp['id'], (string)$resp['respondent_type'], (string)($resp['respondent_email'] ?? ''),
            (string)($resp['respondent_user_id'] ?? ''), (string)($resp['deployment_visit_id'] ?? ''), (string)$resp['submitted_at'],
        ];
        foreach ($questions as $question) {
            $row[] = $byQuestion[(int)$question['id']] ?? '';
        }
        csv_write_row($out, $row);
    }
    return $out;
}

/**
 * Build the long export (one row per answer) in a temporary stream.
 *
 * @return resource
 * @throws NotFoundException
 */
function forms_export_long(PDO $pdo, int $campaignId)
{
    forms_require_row($pdo, 'form_campaigns', $campaignId, 'Campaign');
    $out = forms_export_stream();
    csv_write_row($out, [
        'response_id', 'submitted_at', 'respondent_type', 'respondent_email', 'respondent_user_id',
        'deployment_visit_id', 'question_id', 'question_label', 'question_type', 'answer_value',
    ]);
    $stmt = $pdo->prepare('
        SELECT r.id AS response_id, r.submitted_at, r.respondent_type, r.respondent_email, r.respondent_user_id,
               r.deployment_visit_id, q.id AS question_id, q.label AS question_label, q.type AS question_type,
               a.answer_text, a.answer_json
        FROM form_responses r
        INNER JOIN form_response_answers a ON a.response_id = r.id
        INNER JOIN form_campaign_questions q ON q.id = a.campaign_question_id
        WHERE r.campaign_id = ?
        ORDER BY r.submitted_at DESC, r.id DESC, q.position ASC, q.id ASC
        LIMIT 200000
    ');
    $stmt->execute([$campaignId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csv_write_row($out, [
            (string)$row['response_id'], (string)$row['submitted_at'], (string)$row['respondent_type'],
            (string)($row['respondent_email'] ?? ''), (string)($row['respondent_user_id'] ?? ''),
            (string)($row['deployment_visit_id'] ?? ''), (string)$row['question_id'], (string)$row['question_label'],
            (string)$row['question_type'], forms_answer_text($row),
        ]);
    }
    return $out;
}

/**
 * A temporary stream that stays in memory up to 2 MB and then spills to disk.
 *
 * @return resource
 */
function forms_export_stream()
{
    $stream = fopen('php://temp/maxmemory:2097152', 'w+');
    if ($stream === false) {
        throw new RuntimeException('Could not open a temporary stream for the export.');
    }
    return $stream;
}
