<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/form_answer_validation.php';
require_once __DIR__ . '/includes/form_builder_service.php';

require_login();
require_permission('forms.submit_internal');

$pdo = db();
$campaignId = req_int($_GET, 'campaign_id');
if ($campaignId <= 0) {
    http_response_code(400);
    echo 'campaign_id is required';
    exit;
}

// Load campaign and deployment context
$stmt = $pdo->prepare('
    SELECT c.*, f.title AS form_title
    FROM form_campaigns c
    INNER JOIN forms f ON f.id = c.form_id
    WHERE c.id = ?
');
$stmt->execute([$campaignId]);
$campaign = $stmt->fetch();
if (!$campaign) {
    http_response_code(404);
    echo 'Campaign not found';
    exit;
}

// Resnapshot questions only when the campaign is configured as live AND still active.
// Once a campaign is closed/completed, it should stop updating.
$snapshotMode = (string)($campaign['snapshot_mode'] ?? 'frozen');
$status = (string)($campaign['status'] ?? '');
if ($snapshotMode === 'live' && $status === 'active') {
    try {
        form_campaign_resnapshot_questions($campaignId);
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::error('form_campaign_resnapshot_failed', $e, ['campaign_id' => $campaignId]);
    }
}

// Load campaign questions, but only those still active on the source form
$q = $pdo->prepare('
    SELECT cq.id, cq.question_key, cq.type, cq.label, cq.help_text, cq.is_required, cq.position, cq.options_json
    FROM form_campaign_questions cq
    INNER JOIN form_campaigns c ON c.id = cq.campaign_id
    INNER JOIN form_questions fq
      ON fq.form_id = c.form_id
     AND fq.question_key = cq.question_key
     AND fq.is_deleted = 0
    WHERE cq.campaign_id = ?
    ORDER BY fq.position ASC, cq.id ASC
');
$q->execute([$campaignId]);
$questions = $q->fetchAll() ?: [];

csrf_verify();

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;
    $deploymentVisitId = !empty($_POST['deployment_visit_id']) ? (int)$_POST['deployment_visit_id'] : null;

    $validated = [];
    foreach ($questions as $question) {
        $qid = (int)$question['id'];
        $fieldName = 'q_' . $qid;
        $raw = $_POST[$fieldName] ?? null;
        $res = form_validate_answer($question, $raw);
        if (!$res['ok']) {
            $errors[$fieldName] = $res['error'] ?? 'Invalid value';
        }
        $validated[] = ['qid' => $qid, 'text' => $res['text'], 'json' => $res['json']];
    }

    if (empty($errors)) {
        $pdo->beginTransaction();

        $insResp = $pdo->prepare('
        INSERT INTO form_responses
            (campaign_id, invite_id, respondent_type, respondent_user_id, respondent_email, deployment_visit_id, ip_address, user_agent, submitted_at)
        VALUES
            (?, NULL, ?, ?, ?, ?, ?, ?, NOW())
    ');
        $insResp->execute([
        $campaignId,
        'internal',
        (int)($_SESSION['uid'] ?? 0),
        (string)($_SESSION['email'] ?? ''),
        $deploymentVisitId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        $responseId = (int)$pdo->lastInsertId();

        $insAns = $pdo->prepare('
        INSERT INTO form_response_answers (response_id, campaign_question_id, answer_text, answer_json)
        VALUES (?, ?, ?, ?)
    ');

        foreach ($validated as $v) {
            $insAns->execute([$responseId, (int)$v['qid'], $v['text'], $v['json']]);
        }

        $pdo->commit();

        AuditLogger::log('forms.submit_internal', [
        'entity_type' => 'form_response',
        'entity_id' => $responseId,
        'description' => 'Submitted internal form response',
        'metadata' => [
            'campaign_id' => $campaignId,
            'deployment_visit_id' => $deploymentVisitId,
        ]
        ]);

        flash_message('Report submitted.', 'success');
        redirect('/deployments');
    }
}

// Visits dropdown (optional)
$visits = [];
if (!empty($campaign['deployment_project_id'])) {
    $stmt = $pdo->prepare('SELECT id, visit_date, summary FROM deployment_visits WHERE deployment_id = ? ORDER BY visit_date DESC LIMIT 200');
    $stmt->execute([(int)$campaign['deployment_project_id']]);
    $visits = $stmt->fetchAll() ?: [];
}

$title = 'Submit Report';
$extra_styles = [];
require __DIR__ . '/views/form_submit.view.php';
