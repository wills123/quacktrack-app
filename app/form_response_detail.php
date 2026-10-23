<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

require_login();
require_permission('forms.view_responses');

$pdo = db();
$responseId = req_int($_GET, 'response_id');
if ($responseId <= 0) {
    http_response_code(400);
    echo 'response_id is required';
    exit;
}

$stmt = $pdo->prepare('
    SELECT r.*, c.name AS campaign_name, c.id AS campaign_id, f.title AS form_title
    FROM form_responses r
    INNER JOIN form_campaigns c ON c.id = r.campaign_id
    INNER JOIN forms f ON f.id = c.form_id
    WHERE r.id = ?
');
$stmt->execute([$responseId]);
$response = $stmt->fetch();
if (!$response) {
    http_response_code(404);
    echo 'Response not found';
    exit;
}

$answersStmt = $pdo->prepare('
    SELECT q.label, q.type, q.is_required, a.answer_text, a.answer_json
    FROM form_response_answers a
    INNER JOIN form_campaign_questions q ON q.id = a.campaign_question_id
    WHERE a.response_id = ?
    ORDER BY q.position ASC, q.id ASC
');
$answersStmt->execute([$responseId]);
$answers = $answersStmt->fetchAll() ?: [];

$title = 'Response Detail';
$extra_styles = [];
require __DIR__ . '/views/form_response_detail.view.php';
