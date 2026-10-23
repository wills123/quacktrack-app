<?php

/**
 * Survey drafts (api/surveys.php): start or resume, read, save answers, submit and delete. Every change runs in one
 * transaction that first locks the submission row and checks owner and status under that lock, so a save, submit or
 * delete that races another one sees what the other left. Only the caller's own draft is ever written.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/survey_service.php';
require_once __DIR__ . '/survey_design_service.php';
require_once __DIR__ . '/survey_photo_files.php';

const SURVEY_RESPONSES_ROW_SQL = 'SELECT id, user_id, status, version_id FROM survey_submissions WHERE id = ?';
const SURVEY_RESPONSES_LOCKED_ROW_SQL = 'SELECT id, user_id, status, version_id FROM survey_submissions WHERE id = ? FOR UPDATE';

/**
 * The submission, which must exist (404), belong to $userId (403) and still be a draft (409). $sql is one of the two
 * row queries above: the locked one inside a change.
 *
 * @return array{id: int, version_id: int}
 * @throws ClientErrorException
 */
function survey_responses_own_draft(PDO $pdo, string $sql, int $submissionId, int $userId): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$submissionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new NotFoundException('Submission not found.');
    }
    if ((int)$row['user_id'] !== $userId) {
        throw new ForbiddenException('You do not own this submission.');
    }
    if ($row['status'] !== 'draft') {
        throw new ConflictException('This submission is no longer a draft.');
    }
    return ['id' => (int)$row['id'], 'version_id' => (int)$row['version_id']];
}

/**
 * Start a survey at a store, or resume the caller's newest draft there. Returns the submission id and whether it
 * was resumed.
 *
 * @return array{0: int, 1: bool}
 * @throws NotFoundException no such store, or no published version to start from
 */
function survey_responses_start(PDO $pdo, int $userId, int $storeId, string $role): array
{
    return survey_design_transaction($pdo, static function () use ($pdo, $userId, $storeId, $role): array {
        // One start at a time per person, so two quick clicks resume one draft instead of making two. The lock comes
        // before any read: the transaction's snapshot must include a draft the previous start committed.
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $store = $pdo->prepare('SELECT region_id FROM stores WHERE id = ?');
        $store->execute([$storeId]);
        $regionId = $store->fetchColumn();
        if ($regionId === false) {
            throw new NotFoundException('Store not found.');
        }
        $draft = $pdo->prepare("SELECT id FROM survey_submissions WHERE user_id = ? AND store_id = ? AND status = 'draft' ORDER BY created_at DESC, id DESC LIMIT 1");
        $draft->execute([$userId, $storeId]);
        $existing = $draft->fetchColumn();
        if ($existing !== false) {
            return [(int)$existing, true];
        }
        return [survey_responses_create($pdo, $userId, $storeId, $regionId === null ? null : (int)$regionId, $role), false];
    });
}

/**
 * A new draft on the newest published version, with an empty answer row for each of that version's questions.
 *
 * @throws NotFoundException when no version is published
 */
function survey_responses_create(PDO $pdo, int $userId, int $storeId, ?int $regionId, string $role): int
{
    $version = $pdo->query(
        "SELECT v.id FROM survey_template_versions v INNER JOIN survey_templates t ON t.id = v.template_id
         WHERE t.status = 'published' ORDER BY v.published_at DESC, v.id DESC LIMIT 1"
    );
    $versionId = $version === false ? false : $version->fetchColumn();
    if ($versionId === false) {
        throw new NotFoundException('No published survey version available.');
    }
    $pdo->prepare("INSERT INTO survey_submissions (store_id, region_id, user_id, submitter_role, version_id, status) VALUES (?, ?, ?, ?, ?, 'draft')")
        ->execute([$storeId, $regionId, $userId, $role, (int)$versionId]);
    $submissionId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO survey_answers (submission_id, version_question_id, score, notes) SELECT ?, id, NULL, NULL FROM survey_version_questions WHERE version_id = ?')
        ->execute([$submissionId, (int)$versionId]);
    return $submissionId;
}

/**
 * The draft as the submit page reads it: the submission, its version's categories with questions, answers and
 * photos, and the answers again as a flat list.
 *
 * @return array{submission: array<string, mixed>, categories: list<array<string, mixed>>, answers: list<array<string, mixed>>}
 */
function survey_responses_view(int $submissionId): array
{
    $submission = survey_get_submission($submissionId) ?? throw new NotFoundException('Submission not found.');
    $categories = $submission['categories'] ?? [];
    unset($submission['categories']);
    $answers = [];
    foreach ($categories as &$category) {
        foreach ($category['questions'] as &$question) {
            $question['photos'] = array_map('survey_photo_public', $question['photos']);
            if (!empty($question['answer'])) {
                $answers[] = [
                    'question_id' => (int)$question['id'],
                    'score' => $question['answer']['score'] ?? null,
                    'notes' => $question['answer']['notes'] ?? '',
                    'photos' => $question['photos'],
                ];
            }
        }
        unset($question);
    }
    unset($category);
    return ['submission' => $submission, 'categories' => array_values($categories), 'answers' => $answers];
}

/**
 * Store the given answers of the caller's draft (other answers are kept). Every question must belong to the draft's
 * version; nothing is written otherwise.
 *
 * @param array<int, array{score: int|null, notes: string|null}> $answers keyed by version question id
 * @throws ClientErrorException
 */
function survey_responses_save(PDO $pdo, int $userId, int $submissionId, array $answers): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $userId, $submissionId, $answers): void {
        $draft = survey_responses_own_draft($pdo, SURVEY_RESPONSES_LOCKED_ROW_SQL, $submissionId, $userId);
        if ($answers === []) {
            return;
        }
        $questions = $pdo->prepare('SELECT id FROM survey_version_questions WHERE version_id = ?');
        $questions->execute([$draft['version_id']]);
        $foreign = array_values(array_diff(array_keys($answers), array_map('intval', $questions->fetchAll(PDO::FETCH_COLUMN))));
        if ($foreign !== []) {
            throw new UnprocessableException('Some answers are for questions that are not in this survey.', ['version_question_ids' => $foreign]);
        }
        $save = $pdo->prepare(
            'INSERT INTO survey_answers (submission_id, version_question_id, score, notes) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score), notes = VALUES(notes)'
        );
        foreach ($answers as $questionId => $answer) {
            $save->execute([$submissionId, $questionId, $answer['score'], $answer['notes']]);
        }
    });
}

/**
 * Submit the caller's draft once every question of its version has a score; stores the average score.
 *
 * @throws ClientErrorException
 */
function survey_responses_submit(PDO $pdo, int $userId, int $submissionId): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $userId, $submissionId): void {
        $draft = survey_responses_own_draft($pdo, SURVEY_RESPONSES_LOCKED_ROW_SQL, $submissionId, $userId);
        $total = $pdo->prepare('SELECT COUNT(*) FROM survey_version_questions WHERE version_id = ?');
        $total->execute([$draft['version_id']]);
        $questionCount = (int)$total->fetchColumn();
        // Only answers to this version's questions count.
        $scored = $pdo->prepare(
            'SELECT COUNT(*) AS answered, AVG(a.score) AS average FROM survey_answers a
             INNER JOIN survey_version_questions q ON q.id = a.version_question_id AND q.version_id = ?
             WHERE a.submission_id = ? AND a.score BETWEEN 1 AND 4'
        );
        $scored->execute([$draft['version_id'], $submissionId]);
        $scores = $scored->fetch(PDO::FETCH_ASSOC) ?: ['answered' => 0, 'average' => null];
        $answered = (int)$scores['answered'];
        if ($answered < $questionCount) {
            throw new UnprocessableException("All questions must have a score (1-4). Answered {$answered} of {$questionCount}.");
        }
        $average = $scores['average'] === null ? null : round((float)$scores['average'], 2);
        $pdo->prepare("UPDATE survey_submissions SET status = 'submitted', submitted_at = NOW(), overall_avg_score = ? WHERE id = ?")
            ->execute([$average, $submissionId]);
    });
}

/**
 * Delete the caller's draft with its answers and photo rows, then its photo files. Returns the photo files that
 * could not be removed (they are logged, the draft is gone either way).
 *
 * @return list<string>
 * @throws ClientErrorException
 */
function survey_responses_delete(PDO $pdo, int $userId, int $submissionId): array
{
    $paths = survey_design_transaction($pdo, static function () use ($pdo, $userId, $submissionId): array {
        survey_responses_own_draft($pdo, SURVEY_RESPONSES_LOCKED_ROW_SQL, $submissionId, $userId);
        $photos = $pdo->prepare('SELECT file_path FROM survey_photos WHERE submission_id = ?');
        $photos->execute([$submissionId]);
        $paths = $photos->fetchAll(PDO::FETCH_COLUMN);
        // Answers and photo rows go with the submission (ON DELETE CASCADE).
        $pdo->prepare('DELETE FROM survey_submissions WHERE id = ?')->execute([$submissionId]);
        return $paths;
    });
    // Only after the rows are gone, so a failed delete never loses a file that is still referenced.
    return survey_photo_remove_files($submissionId, $paths);
}
