<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/survey_photo_files.php';

/**
 * Store IT Standards Survey service layer.
 *
 * Pure functions, stateless. All DB access uses the db() PDO singleton
 * with prepared statements. Input validation throws InvalidArgumentException.
 *
 * Tables: survey_templates, survey_template_versions, survey_categories,
 *         survey_questions, survey_version_categories, survey_version_questions,
 *         survey_submissions, survey_answers, survey_photos.
 */

// ============================================================
// Template management
// ============================================================

/**
 * Get a single template with its version count.
 *
 * @return array|null Template row with `version_count`, or null if not found
 */
function survey_get_template(int $templateId): ?array
{
    if ($templateId <= 0) {
        throw new InvalidArgumentException('Invalid template ID.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM survey_template_versions WHERE template_id = t.id) AS version_count
         FROM survey_templates t
         WHERE t.id = ?'
    );
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Get the first non-archived template with nested categories and questions.
 * For MVP there is a single global template.
 *
 * @return array|null Template with `categories` (each with `questions`), or null
 */
function survey_get_active_template(): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT *
         FROM survey_templates
         WHERE status != 'archived'
         ORDER BY id ASC
         LIMIT 1"
    );
    $stmt->execute();
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        return null;
    }

    $template['categories'] = survey_get_template_categories((int) $template['id']);
    return $template;
}

/**
 * Get all categories for a template with their active questions nested.
 *
 * @return array Categories ordered by position, each with `questions` array
 */
function survey_get_template_categories(int $templateId): array
{
    if ($templateId <= 0) {
        throw new InvalidArgumentException('Invalid template ID.');
    }

    $pdo = db();

    $catStmt = $pdo->prepare(
        'SELECT * FROM survey_categories
         WHERE template_id = ?
         ORDER BY position ASC, id ASC'
    );
    $catStmt->execute([$templateId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($categories)) {
        return [];
    }

    $qStmt = $pdo->prepare(
        'SELECT * FROM survey_questions
         WHERE category_id = ? AND is_active = 1
         ORDER BY position ASC, id ASC'
    );

    foreach ($categories as &$cat) {
        $qStmt->execute([(int) $cat['id']]);
        $cat['questions'] = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($cat);

    return $categories;
}

// ============================================================
// Submission
// ============================================================

/**
 * Get the latest published version for a template.
 *
 * @return array|null Version row or null
 */
function survey_get_latest_published_version(int $templateId): ?array
{
    if ($templateId <= 0) {
        throw new InvalidArgumentException('Invalid template ID.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM survey_template_versions
         WHERE template_id = ?
         ORDER BY version_number DESC
         LIMIT 1'
    );
    $stmt->execute([$templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Get version categories and questions ordered by position, nested.
 *
 * @return array Categories each with nested `questions` sub-array
 */
function survey_get_version_questions(int $versionId): array
{
    if ($versionId <= 0) {
        throw new InvalidArgumentException('Invalid version ID.');
    }

    $pdo = db();

    $catStmt = $pdo->prepare(
        'SELECT * FROM survey_version_categories
         WHERE version_id = ?
         ORDER BY position ASC, id ASC'
    );
    $catStmt->execute([$versionId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($categories)) {
        return [];
    }

    $qStmt = $pdo->prepare(
        'SELECT * FROM survey_version_questions
         WHERE version_category_id = ?
         ORDER BY position ASC, id ASC'
    );

    foreach ($categories as &$cat) {
        $qStmt->execute([(int) $cat['id']]);
        $cat['questions'] = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($cat);

    return $categories;
}

// Starting, saving, submitting and deleting drafts: includes/survey_responses_service.php. Adding and removing
// photos, listing submissions and finding a tech survey to compare with: includes/survey_media_reporting_service.php.

/**
 * Get all photos for a submission, grouped by answer_id.
 *
 * @return array Keyed by answer_id, each value is an array of photo rows
 */
function survey_get_submission_photos(int $submissionId): array
{
    if ($submissionId <= 0) {
        throw new InvalidArgumentException('Invalid submission ID.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT * FROM survey_photos WHERE submission_id = ? ORDER BY answer_id ASC, id ASC'
    );
    $stmt->execute([$submissionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        $key = $row['answer_id'] !== null ? (int) $row['answer_id'] : 0;
        $grouped[$key][] = $row;
    }
    return $grouped;
}

/**
 * Delete stale draft submissions older than $daysOld days.
 *
 * Removes photos from disk, then deletes submissions (cascade deletes answers/photos rows).
 *
 * @return int Number of submissions deleted
 */
function survey_cleanup_stale_drafts(int $daysOld = 30): int
{
    if ($daysOld < 1) {
        throw new InvalidArgumentException('Days must be at least 1.');
    }

    $pdo = db();

    // Find stale draft submissions
    $stmt = $pdo->prepare(
        "SELECT id FROM survey_submissions
         WHERE status = 'draft' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$daysOld]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($submissions)) {
        return 0;
    }

    $count = 0;
    foreach ($submissions as $sub) {
        $subId = (int) $sub['id'];

        $photoStmt = $pdo->prepare('SELECT file_path FROM survey_photos WHERE submission_id = ?');
        $photoStmt->execute([$subId]);
        $paths = $photoStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Delete submission (cascade removes answers and photo rows)
        $delStmt = $pdo->prepare('DELETE FROM survey_submissions WHERE id = ?');
        $delStmt->execute([$subId]);
        // The files go after their rows, so a failed delete never loses a file that is still referenced.
        survey_photo_remove_files($subId, $paths);
        $count++;
    }

    return $count;
}

// ============================================================
// History and comparison
// ============================================================

/**
 * Get full detail for a submission: row joined with user, store, region,
 * template version, with categories/questions/answers/photos nested.
 *
 * @return array|null Full submission detail or null
 */
function survey_get_submission(int $submissionId): ?array
{
    if ($submissionId <= 0) {
        throw new InvalidArgumentException('Invalid submission ID.');
    }

    $pdo = db();

    // Main submission joined with related data
    $stmt = $pdo->prepare(
        "SELECT s.*,
                CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name,
                u.first_name AS user_first_name,
                u.last_name AS user_last_name,
                st.name AS store_name,
                r.name AS region_name,
                v.version_number
         FROM survey_submissions s
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN stores st ON st.id = s.store_id
         LEFT JOIN regions r ON r.id = s.region_id
         LEFT JOIN survey_template_versions v ON v.id = s.version_id
         WHERE s.id = ?"
    );
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$submission) {
        return null;
    }

    $versionId = (int) $submission['version_id'];

    // Fetch photos grouped by answer_id
    $photos = survey_get_submission_photos($submissionId);

    // Fetch answers indexed by version_question_id
    $ansStmt = $pdo->prepare(
        'SELECT * FROM survey_answers WHERE submission_id = ?'
    );
    $ansStmt->execute([$submissionId]);
    $answerRows = $ansStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $answersMap = [];
    foreach ($answerRows as $a) {
        $answersMap[(int) $a['version_question_id']] = $a;
    }

    // Fetch version categories and questions
    $catStmt = $pdo->prepare(
        'SELECT * FROM survey_version_categories
         WHERE version_id = ?
         ORDER BY position ASC, id ASC'
    );
    $catStmt->execute([$versionId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $qStmt = $pdo->prepare(
        'SELECT * FROM survey_version_questions
         WHERE version_category_id = ?
         ORDER BY position ASC, id ASC'
    );

    foreach ($categories as &$cat) {
        $qStmt->execute([(int) $cat['id']]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($questions as &$q) {
            $qId = (int) $q['id'];
            $q['answer'] = $answersMap[$qId] ?? null;
            $answerId = $q['answer'] ? (int) $q['answer']['id'] : 0;
            $q['photos'] = $photos[$answerId] ?? [];
        }
        unset($q);

        $cat['questions'] = $questions;
    }
    unset($cat);

    $submission['categories'] = $categories;
    return $submission;
}

/**
 * Build a comparison between a manager and tech submission.
 *
 * Returns cross_version_warning, per-category averages, per-question diffs,
 * and overall averages.
 *
 * @return array Comparison data structure
 */
function survey_get_comparison(int $managerSubId, int $techSubId): array
{
    $manager = survey_get_submission($managerSubId);
    $tech = survey_get_submission($techSubId);

    if (!$manager) {
        throw new InvalidArgumentException('Manager submission not found.');
    }
    if (!$tech) {
        throw new InvalidArgumentException('Tech submission not found.');
    }

    $crossVersionWarning = ((int) $manager['version_id'] !== (int) $tech['version_id']);

    $result = [
        'cross_version_warning' => $crossVersionWarning,
        'manager_submission' => [
            'id' => (int) $manager['id'],
            'store_id' => (int) $manager['store_id'],
            'version_id' => (int) $manager['version_id'],
            'submitted_at' => $manager['submitted_at'],
            'submitted_by_name' => $manager['submitted_by_name'],
            'overall_avg_score' => $manager['overall_avg_score'] !== null ? (float) $manager['overall_avg_score'] : null,
        ],
        'tech_submission' => [
            'id' => (int) $tech['id'],
            'submitted_at' => $tech['submitted_at'],
            'submitted_by_name' => $tech['submitted_by_name'],
            'overall_avg_score' => $tech['overall_avg_score'] !== null ? (float) $tech['overall_avg_score'] : null,
        ],
        'overall' => [
            'manager_avg' => $manager['overall_avg_score'] !== null ? (float) $manager['overall_avg_score'] : null,
            'tech_avg' => $tech['overall_avg_score'] !== null ? (float) $tech['overall_avg_score'] : null,
            'diff' => null,
        ],
        'categories' => [],
        'questions' => [],
    ];

    // Compute overall diff
    if ($result['overall']['manager_avg'] !== null && $result['overall']['tech_avg'] !== null) {
        $result['overall']['diff'] = round($result['overall']['manager_avg'] - $result['overall']['tech_avg'], 2);
    }

    // If versions differ, skip per-question detail (questions may not align)
    if ($crossVersionWarning) {
        return $result;
    }

    // Build maps of scores by source_question_id for alignment
    // Manager answers indexed by version_question_id
    $managerAnswers = [];
    foreach ($manager['categories'] as $cat) {
        foreach ($cat['questions'] as $q) {
            $managerAnswers[(int) $q['id']] = $q['answer'];
        }
    }

    // Tech answers indexed by version_question_id
    $techAnswers = [];
    foreach ($tech['categories'] as $cat) {
        foreach ($cat['questions'] as $q) {
            $techAnswers[(int) $q['id']] = $q['answer'];
        }
    }

    // Use manager's categories/questions as the reference structure
    // (same version, so structure matches)
    foreach ($manager['categories'] as $cat) {
        $managerCatScores = [];
        $techCatScores = [];

        foreach ($cat['questions'] as $q) {
            $qId = (int) $q['id'];

            $mScore = isset($managerAnswers[$qId]) && $managerAnswers[$qId]['score'] !== null
                ? (float) $managerAnswers[$qId]['score']
                : null;
            $tScore = isset($techAnswers[$qId]) && $techAnswers[$qId]['score'] !== null
                ? (float) $techAnswers[$qId]['score']
                : null;

            $diff = null;
            $diffClass = 'match';
            if ($mScore !== null && $tScore !== null) {
                $diff = round($mScore - $tScore, 2);
                $absDiff = abs($diff);
                if ($absDiff == 0) {
                    $diffClass = 'match';
                } elseif ($absDiff <= 1) {
                    $diffClass = 'minor';
                } else {
                    $diffClass = 'major';
                }
            }

            $result['questions'][] = [
                'question_text' => $q['question_text'],
                'category_name' => $cat['name'],
                'manager_score' => $mScore,
                'tech_score' => $tScore,
                'diff' => $diff,
                'diff_class' => $diffClass,
            ];

            if ($mScore !== null) {
                $managerCatScores[] = $mScore;
            }
            if ($tScore !== null) {
                $techCatScores[] = $tScore;
            }
        }

        $managerCatAvg = !empty($managerCatScores)
            ? round(array_sum($managerCatScores) / count($managerCatScores), 2)
            : null;
        $techCatAvg = !empty($techCatScores)
            ? round(array_sum($techCatScores) / count($techCatScores), 2)
            : null;
        $catDiff = ($managerCatAvg !== null && $techCatAvg !== null)
            ? round($managerCatAvg - $techCatAvg, 2)
            : null;

        $result['categories'][] = [
            'name' => $cat['name'],
            'manager_avg' => $managerCatAvg,
            'tech_avg' => $techCatAvg,
            'diff' => $catDiff,
        ];
    }

    return $result;
}
