<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/form_answer_validation.php';

// Public endpoint: contractor submissions via token.
// Security: treat links as credentials; set headers to minimize token leakage.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$pdo = db();

$token = req_string($_GET, 't');
if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    exit;
}

$tokenHash = hash('sha256', $token);

// Resolve token against share links first, then per-recipient invites.
$share = null;
$invite = null;

$stmt = $pdo->prepare('SELECT * FROM form_share_links WHERE token_hash = ? LIMIT 1');
$stmt->execute([$tokenHash]);
$share = $stmt->fetch() ?: null;

if (!$share) {
    $stmt = $pdo->prepare('SELECT * FROM form_invites WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$tokenHash]);
    $invite = $stmt->fetch() ?: null;
}

if (!$share && !$invite) {
    http_response_code(404);
    echo 'Invalid or expired link.';
    exit;
}

$expiresAt = $share['expires_at'] ?? ($invite['expires_at'] ?? null);
$revokedAt = $share['revoked_at'] ?? ($invite['revoked_at'] ?? null);

if (!empty($revokedAt)) {
    http_response_code(403);
    echo 'This link has been revoked.';
    exit;
}

if (!empty($expiresAt) && strtotime((string)$expiresAt) < time()) {
    http_response_code(403);
    echo 'This link has expired.';
    exit;
}

$campaignId = (int)(($share['campaign_id'] ?? 0) ?: ($invite ? (int)$invite['campaign_id'] : 0));
if ($campaignId <= 0) {
    http_response_code(400);
    echo 'Invalid campaign.';
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
    echo 'Campaign not found.';
    exit;
}

// Campaign closed grace enforcement
if (($campaign['status'] ?? '') === 'closed' && !empty($campaign['closed_at'])) {
    $graceDays = (int)($campaign['close_grace_days'] ?? 30);
    $deadline = strtotime((string)$campaign['closed_at'] . ' +' . $graceDays . ' days');
    if ($deadline !== false && $deadline < time()) {
        http_response_code(403);
        echo 'This campaign is no longer accepting submissions.';
        exit;
    }
}

// Load snapshotted questions
$q = $pdo->prepare('
    SELECT id, type, label, help_text, is_required, position, options_json
    FROM form_campaign_questions
    WHERE campaign_id = ?
    ORDER BY position ASC, id ASC
');
$q->execute([$campaignId]);
$questions = $q->fetchAll() ?: [];

csrf_verify();

$prefillEmail = '';
if ($invite && !empty($invite['recipient_email'])) {
    $prefillEmail = (string)$invite['recipient_email'];
}

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    // Basic rate limit (token + IP)
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $rateKey = 'public_form_submit:' . substr($tokenHash, 0, 16) . ':' . $ip;
    $rl = check_rate_limit($rateKey, 0, 20, 3600); // 20/hour per token+ip
    if (!$rl['allowed']) {
        http_response_code(429);
        echo 'Too many submissions. Please try again later.';
        exit;
    }

    $email = req_string($_POST, 'respondent_email', (string)$prefillEmail);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['respondent_email'] = 'Enter a valid email address.';
    }

    // Validate answers
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
                (campaign_id, invite_id, share_link_id, respondent_type, respondent_user_id, respondent_email, ip_address, user_agent, submitted_at)
            VALUES
                (?, ?, ?, ?, NULL, ?, ?, ?, NOW())
        ');

        $inviteId = $invite ? (int)$invite['id'] : null;
        $shareId = $share ? (int)$share['id'] : null;

        $insResp->execute([
            $campaignId,
            $inviteId,
            $shareId,
            'contractor',
            $email,
            $ip !== '' ? $ip : null,
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

        // Update invite tracking if applicable
        if ($inviteId) {
            $pdo->prepare('UPDATE form_invites SET submission_count = submission_count + 1, last_submitted_at = NOW() WHERE id = ?')
                ->execute([$inviteId]);
        }

        $pdo->commit();

        // Do not log raw tokens
        flash_message('Thanks — your report was submitted.', 'success');
        redirect('/public_form?t=' . urlencode($token));
    }
}

$title = 'Submit Report';
$extra_styles = [];
require __DIR__ . '/views/public_form.view.php';
