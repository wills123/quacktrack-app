<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

require_login();
require_permission('forms.view_responses');

$pdo = db();
$campaignId = req_int($_GET, 'campaign_id');
if ($campaignId <= 0) {
    http_response_code(400);
    echo 'campaign_id is required';
    exit;
}

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

// Latest contractor/internal submissions
$latestStmt = $pdo->prepare('
    SELECT r.*
    FROM form_responses r
    WHERE r.campaign_id = ? AND r.respondent_type = ?
    ORDER BY r.submitted_at DESC, r.id DESC
    LIMIT 1
');

$latestStmt->execute([$campaignId, 'contractor']);
$latestContractor = $latestStmt->fetch() ?: null;
$latestStmt->execute([$campaignId, 'internal']);
$latestInternal = $latestStmt->fetch() ?: null;

function normalize_answer_value(?string $answerText, mixed $answerJson): string
{
    if ($answerJson !== null && $answerJson !== '') {
        $decoded = json_decode((string)$answerJson, true);
        if (is_array($decoded)) {
            $flat = array_map('strval', $decoded);
            return implode(', ', $flat);
        }
        return (string)$answerJson;
    }
    return trim((string)($answerText ?? ''));
}

// Comparison (latest contractor vs latest internal)
$comparisonRows = [];
if (!empty($latestContractor) && !empty($latestInternal)) {
    $cmpStmt = $pdo->prepare('
        SELECT
            q.id AS question_id,
            q.position,
            q.label,
            q.type,
            ac.answer_text AS contractor_answer_text,
            ac.answer_json AS contractor_answer_json,
            ai.answer_text AS internal_answer_text,
            ai.answer_json AS internal_answer_json
        FROM form_campaign_questions q
        LEFT JOIN form_response_answers ac
            ON ac.campaign_question_id = q.id AND ac.response_id = ?
        LEFT JOIN form_response_answers ai
            ON ai.campaign_question_id = q.id AND ai.response_id = ?
        WHERE q.campaign_id = ?
        ORDER BY q.position ASC, q.id ASC
    ');
    $cmpStmt->execute([(int)$latestContractor['id'], (int)$latestInternal['id'], $campaignId]);
    $comparisonRows = $cmpStmt->fetchAll() ?: [];

    // Pre-compute normalized string values + match flag
    foreach ($comparisonRows as &$row) {
        $cVal = normalize_answer_value($row['contractor_answer_text'] ?? null, $row['contractor_answer_json'] ?? null);
        $iVal = normalize_answer_value($row['internal_answer_text'] ?? null, $row['internal_answer_json'] ?? null);

        $row['contractor_value'] = $cVal;
        $row['internal_value'] = $iVal;

        // Basic match: case-insensitive exact string match after trim
        $row['is_match'] = (strcasecmp(trim($cVal), trim($iVal)) === 0);
    }
    unset($row);
}

// Recent submissions list
$listStmt = $pdo->prepare('
    SELECT r.id, r.respondent_type, r.respondent_user_id, r.respondent_email, r.deployment_visit_id, r.submitted_at
    FROM form_responses r
    WHERE r.campaign_id = ?
    ORDER BY r.submitted_at DESC, r.id DESC
    LIMIT 200
');
$listStmt->execute([$campaignId]);
$responses = $listStmt->fetchAll() ?: [];

$title = 'Form Responses';
$extra_styles = [];
require __DIR__ . '/views/form_responses.view.php';
