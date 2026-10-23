<?php

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/input_schemas/survey_design.php';
require_once __DIR__ . '/survey_service.php';

/**
 * Survey template design: templates, categories, questions, ordering and published versions.
 *
 * Every change runs in one transaction that first locks the row it belongs to (the template or the
 * category), so two editors never interleave, and nothing is written unless the whole change is valid.
 * A row that does not exist is a NotFoundException; a list that is not exactly the parent's current
 * members is an UnprocessableException. Published versions are copies and are never touched here.
 */

/**
 * Run $work in a transaction and return its result; any failure rolls everything back.
 *
 * @template T
 * @param callable(): T $work
 * @return T
 */
function survey_design_transaction(PDO $pdo, callable $work): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Lock one row of a design table and return it. A category or question is locked only after its template, the order
 * publication uses (template, then categories and questions), so concurrent edits and publications cannot deadlock.
 *
 * @param 'survey_templates'|'survey_categories'|'survey_questions' $table
 * @return array<string, mixed>
 * @throws NotFoundException
 */
function survey_design_lock_row(PDO $pdo, string $table, int $id, string $label): array
{
    if ($table !== 'survey_templates') {
        $owner = $pdo->prepare($table === 'survey_categories' ? 'SELECT template_id FROM survey_categories WHERE id = ?' : 'SELECT template_id FROM survey_questions WHERE id = ?');
        $owner->execute([$id]);
        $templateId = $owner->fetchColumn();
        if ($templateId === false) {
            throw new NotFoundException("{$label} not found.");
        }
        survey_design_lock_row($pdo, 'survey_templates', (int)$templateId, 'Template');
    }
    $sql = match ($table) {
        'survey_templates' => 'SELECT * FROM survey_templates WHERE id = ? FOR UPDATE',
        'survey_categories' => 'SELECT * FROM survey_categories WHERE id = ? FOR UPDATE',
        'survey_questions' => 'SELECT * FROM survey_questions WHERE id = ? FOR UPDATE',
    };
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new NotFoundException("{$label} not found.");
    }
    return $row;
}

/** Text that is stored as NULL when blank. */
function survey_design_nullable(?string $text): ?string
{
    return $text === null || trim($text) === '' ? null : trim($text);
}

/**
 * @return array<string, mixed> the template row with its categories (and their active questions)
 * @throws NotFoundException
 */
function survey_design_template_with_categories(PDO $pdo, int $templateId): array
{
    $stmt = $pdo->prepare('SELECT * FROM survey_templates WHERE id = ?');
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        throw new NotFoundException('Template not found.');
    }
    $template['categories'] = survey_get_template_categories($templateId);
    return $template;
}

/** @return array<string, mixed> the new template with its (empty) category list */
function survey_design_create_template(PDO $pdo, int $userId, string $title, ?string $description): array
{
    $pdo->prepare('INSERT INTO survey_templates (title, description, status, created_by_user_id) VALUES (?, ?, ?, ?)')
        ->execute([$title, survey_design_nullable($description), 'draft', $userId]);
    return survey_design_template_with_categories($pdo, (int)$pdo->lastInsertId());
}

/**
 * Change a template's title and, when one was sent, its description (blank clears it).
 *
 * @throws NotFoundException
 */
function survey_design_update_template(PDO $pdo, int $templateId, string $title, ?string $description): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $templateId, $title, $description): void {
        $template = survey_design_lock_row($pdo, 'survey_templates', $templateId, 'Template');
        $kept = $description === null ? $template['description'] : survey_design_nullable($description);
        $pdo->prepare('UPDATE survey_templates SET title = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$title, $kept, $templateId]);
    });
}

/**
 * Append a category to a template.
 *
 * @throws NotFoundException
 */
function survey_design_add_category(PDO $pdo, int $templateId, string $name): int
{
    return survey_design_transaction($pdo, static function () use ($pdo, $templateId, $name): int {
        survey_design_lock_row($pdo, 'survey_templates', $templateId, 'Template');
        $next = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM survey_categories WHERE template_id = ?');
        $next->execute([$templateId]);
        $pdo->prepare('INSERT INTO survey_categories (template_id, name, position) VALUES (?, ?, ?)')
            ->execute([$templateId, $name, (int)$next->fetchColumn()]);
        return (int)$pdo->lastInsertId();
    });
}

/** @throws NotFoundException */
function survey_design_update_category(PDO $pdo, int $categoryId, string $name): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $categoryId, $name): void {
        survey_design_lock_row($pdo, 'survey_categories', $categoryId, 'Category');
        $pdo->prepare('UPDATE survey_categories SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$name, $categoryId]);
    });
}

/**
 * Delete a category and its questions; published versions keep their copies.
 *
 * @throws NotFoundException
 */
function survey_design_delete_category(PDO $pdo, int $categoryId): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $categoryId): void {
        survey_design_lock_row($pdo, 'survey_categories', $categoryId, 'Category');
        $pdo->prepare('DELETE FROM survey_categories WHERE id = ?')->execute([$categoryId]);
    });
}

/**
 * Set the positions of a template's categories to the given order.
 *
 * @param list<int> $categoryIds
 * @throws NotFoundException|UnprocessableException
 */
function survey_design_reorder_categories(PDO $pdo, int $templateId, array $categoryIds): void
{
    survey_design_reorder($pdo, 'category', $templateId, $categoryIds);
}

/**
 * Set the positions of a category's active questions to the given order.
 *
 * @param list<int> $questionIds
 * @throws NotFoundException|UnprocessableException
 */
function survey_design_reorder_questions(PDO $pdo, int $categoryId, array $questionIds): void
{
    survey_design_reorder($pdo, 'question', $categoryId, $questionIds);
}

/**
 * Replace the order of a parent's members: lock the parent, require the list to be exactly its current members, then
 * write every position or none.
 *
 * @param 'category'|'question' $kind categories of a template, or active questions of a category
 * @param list<int> $ids the requested order
 * @throws NotFoundException|UnprocessableException
 */
function survey_design_reorder(PDO $pdo, string $kind, int $parentId, array $ids): void
{
    $categories = $kind === 'category';
    survey_design_transaction($pdo, static function () use ($pdo, $categories, $kind, $parentId, $ids): void {
        if ($categories) {
            survey_design_lock_row($pdo, 'survey_templates', $parentId, 'Template');
        } else {
            survey_design_lock_row($pdo, 'survey_categories', $parentId, 'Category');
        }
        $members = $pdo->prepare($categories
            ? 'SELECT id FROM survey_categories WHERE template_id = ? FOR UPDATE'
            : 'SELECT id FROM survey_questions WHERE category_id = ? AND is_active = 1 FOR UPDATE');
        $members->execute([$parentId]);
        survey_design_require_permutation($ids, array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)), $kind);
        $update = $pdo->prepare($categories
            ? 'UPDATE survey_categories SET position = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND template_id = ?'
            : 'UPDATE survey_questions SET position = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND category_id = ?');
        foreach ($ids as $position => $id) {
            $update->execute([$position, $id, $parentId]);
        }
    });
}

/**
 * Append a question to a category.
 *
 * @throws NotFoundException
 */
function survey_design_add_question(PDO $pdo, int $categoryId, string $text, ?string $guidance): int
{
    // Resolve the immutable parent before beginning the transaction so this read does not
    // create a stale repeatable-read snapshot while waiting for another editor.
    $parent = $pdo->prepare('SELECT template_id FROM survey_categories WHERE id = ?');
    $parent->execute([$categoryId]);
    $templateId = $parent->fetchColumn();
    if ($templateId === false) {
        throw new NotFoundException('Category not found.');
    }
    return survey_design_transaction($pdo, static function () use ($pdo, $templateId, $categoryId, $text, $guidance): int {
        // Publication locks template then category through its snapshot foreign keys.
        survey_design_lock_row($pdo, 'survey_templates', (int)$templateId, 'Template');
        $category = survey_design_lock_row($pdo, 'survey_categories', $categoryId, 'Category');
        $next = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM survey_questions WHERE category_id = ?');
        $next->execute([$categoryId]);
        $pdo->prepare('INSERT INTO survey_questions (template_id, category_id, question_text, scoring_guidance, position) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int)$category['template_id'], $categoryId, $text, survey_design_nullable($guidance), (int)$next->fetchColumn()]);
        return (int)$pdo->lastInsertId();
    });
}

/**
 * Change a question's text and, when guidance was sent, its scoring guidance (blank clears it).
 *
 * @throws NotFoundException a question that was deleted counts as missing
 */
function survey_design_update_question(PDO $pdo, int $questionId, string $text, ?string $guidance): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $questionId, $text, $guidance): void {
        $question = survey_design_lock_row($pdo, 'survey_questions', $questionId, 'Question');
        if ((int)$question['is_active'] !== 1) {
            throw new NotFoundException('Question not found.');
        }
        $kept = $guidance === null ? $question['scoring_guidance'] : survey_design_nullable($guidance);
        $pdo->prepare('UPDATE survey_questions SET question_text = ?, scoring_guidance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$text, $kept, $questionId]);
    });
}

/**
 * Soft-delete a question (published versions keep their copy). Deleting it again changes nothing.
 *
 * @throws NotFoundException
 */
function survey_design_delete_question(PDO $pdo, int $questionId): void
{
    survey_design_transaction($pdo, static function () use ($pdo, $questionId): void {
        survey_design_lock_row($pdo, 'survey_questions', $questionId, 'Question');
        $pdo->prepare('UPDATE survey_questions SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND is_active = 1')->execute([$questionId]);
    });
}

/**
 * Publish the template's current categories and active questions as the next version. Publications of
 * one template are serialized by the template row lock, so version numbers are consecutive.
 *
 * @return int the new version id
 * @throws NotFoundException
 */
function survey_design_publish_version(PDO $pdo, int $templateId, int $userId): int
{
    return survey_design_transaction($pdo, static function () use ($pdo, $templateId, $userId): int {
        survey_design_lock_row($pdo, 'survey_templates', $templateId, 'Template');
        $active = $pdo->prepare('SELECT COUNT(*) FROM survey_questions q JOIN survey_categories c ON c.id = q.category_id WHERE c.template_id = ? AND q.is_active = 1');
        $active->execute([$templateId]);
        if ((int)$active->fetchColumn() > SURVEY_MAX_QUESTIONS) {
            throw new UnprocessableException('A survey can have at most ' . SURVEY_MAX_QUESTIONS . ' questions; remove some before publishing.');
        }
        $max = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM survey_template_versions WHERE template_id = ?');
        $max->execute([$templateId]);
        $pdo->prepare('INSERT INTO survey_template_versions (template_id, version_number, published_by_user_id) VALUES (?, ?, ?)')
            ->execute([$templateId, (int)$max->fetchColumn() + 1, $userId]);
        $versionId = (int)$pdo->lastInsertId();
        survey_design_copy_into_version($pdo, $templateId, $versionId);
        $pdo->prepare("UPDATE survey_templates SET status = 'published', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$templateId]);
        return $versionId;
    });
}

/** Copy the template's categories and active questions into the version (inside the publish transaction). */
function survey_design_copy_into_version(PDO $pdo, int $templateId, int $versionId): void
{
    $categories = $pdo->prepare('SELECT id, name, position FROM survey_categories WHERE template_id = ? ORDER BY position ASC, id ASC');
    $categories->execute([$templateId]);
    $copyCategory = $pdo->prepare('INSERT INTO survey_version_categories (version_id, source_category_id, name, position) VALUES (?, ?, ?, ?)');
    $questions = $pdo->prepare('SELECT id, question_text, scoring_guidance, position FROM survey_questions WHERE category_id = ? AND is_active = 1 ORDER BY position ASC, id ASC');
    $copyQuestion = $pdo->prepare(
        'INSERT INTO survey_version_questions (version_id, version_category_id, source_question_id, question_text, scoring_guidance, position)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($categories->fetchAll(PDO::FETCH_ASSOC) as $category) {
        $copyCategory->execute([$versionId, (int)$category['id'], $category['name'], (int)$category['position']]);
        $versionCategoryId = (int)$pdo->lastInsertId();
        $questions->execute([(int)$category['id']]);
        foreach ($questions->fetchAll(PDO::FETCH_ASSOC) as $question) {
            $copyQuestion->execute([$versionId, $versionCategoryId, (int)$question['id'], $question['question_text'], $question['scoring_guidance'], (int)$question['position']]);
        }
    }
}

/**
 * @return list<array<string, mixed>> the template's versions, newest first
 * @throws NotFoundException
 */
function survey_design_list_versions(PDO $pdo, int $templateId): array
{
    $exists = $pdo->prepare('SELECT id FROM survey_templates WHERE id = ?');
    $exists->execute([$templateId]);
    if (!$exists->fetchColumn()) {
        throw new NotFoundException('Template not found.');
    }
    $stmt = $pdo->prepare(
        "SELECT v.id, v.template_id, v.version_number, v.published_at, v.published_by_user_id,
                CONCAT(u.first_name, ' ', u.last_name) AS published_by_name,
                (SELECT COUNT(*) FROM survey_version_questions WHERE version_id = v.id) AS question_count
         FROM survey_template_versions v
         LEFT JOIN users u ON u.id = v.published_by_user_id
         WHERE v.template_id = ?
         ORDER BY v.version_number DESC"
    );
    $stmt->execute([$templateId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
