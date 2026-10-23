<?php

/**
 * Key checks, usage bookkeeping and the tables behind api/powerbi.php (read-only survey data for the Power BI web
 * connector). Only submitted surveys and their answers are exported; drafts never are. Every query is fixed SQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';

const POWERBI_SCOPE = 'powerbi:read';

/**
 * Why a key may not read the export at $now ('' when it may): unknown, inactive, expired (an unreadable expiry counts
 * as expired) or without the exact powerbi:read scope in its comma-separated list (entries trimmed, case kept).
 *
 * @param array<string, mixed>|false $record
 */
function powerbi_key_refusal(array|false $record, int $now): string
{
    if ($record === false) {
        return 'key_not_found';
    }
    if ((int)$record['is_active'] !== 1) {
        return 'key_inactive';
    }
    if ($record['expires_at'] !== null) {
        $expires = strtotime((string)$record['expires_at']);
        if ($expires === false || $expires < $now) {
            return 'key_expired';
        }
    }
    $scopes = array_map('trim', explode(',', (string)($record['scopes'] ?? '')));
    return in_array(POWERBI_SCOPE, $scopes, true) ? '' : 'scope_missing';
}

/**
 * Refuse a request that sends no key at all, before the database is opened: a burst of keyless requests during a
 * database incident must not wait on MySQL.
 *
 * @throws ClientErrorException
 */
function powerbi_require_key(string $apiKey): void
{
    if ($apiKey === '') {
        Logger::security('powerbi_auth_failed', ['reason' => 'key_missing']);
        throw new ClientErrorException('Unauthorized.', 401);
    }
}

/**
 * The id of the key sent in $apiKey. Every refusal is the same 401, so an answer never says which check failed; the
 * reason is in the security log.
 *
 * @throws ClientErrorException
 */
function powerbi_authenticate(PDO $pdo, string $apiKey, int $now): int
{
    $record = false;
    if ($apiKey !== '') {
        $find = $pdo->prepare('SELECT id, scopes, is_active, expires_at FROM api_keys WHERE key_hash = ?');
        $find->execute([hash('sha256', $apiKey)]);
        $record = $find->fetch(PDO::FETCH_ASSOC);
    }
    $refusal = $apiKey === '' ? 'key_missing' : powerbi_key_refusal($record, $now);
    if ($refusal !== '') {
        Logger::security('powerbi_auth_failed', ['reason' => $refusal] + ($record === false ? [] : ['key_id' => (int)$record['id']]));
        throw new ClientErrorException('Unauthorized.', 401);
    }
    return (int)$record['id'];
}

/**
 * Count an authenticated request against its key (request count, last use) and its per-minute bucket. This happens
 * before the table is read, so a request that then fails still counts. Returns the requests in this minute.
 */
function powerbi_record_use(PDO $pdo, int $keyId, string $address, int $now): int
{
    $pdo->prepare('UPDATE api_keys SET last_used_at = NOW(), last_used_ip = ?, request_count = request_count + 1 WHERE id = ?')
        ->execute([$address, $keyId]);
    // LAST_INSERT_ID(expr) hands back this connection's count without a second, racing read: 1 for a new minute,
    // the incremented count otherwise.
    $pdo->prepare(
        'INSERT INTO api_key_rate_limits (key_id, minute_bucket, count, updated_at) VALUES (?, ?, LAST_INSERT_ID(1), NOW())
         ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1), updated_at = NOW()'
    )->execute([$keyId, intdiv($now, 60)]);
    $count = $pdo->query('SELECT LAST_INSERT_ID()');
    return $count === false ? 0 : (int)$count->fetchColumn();
}

/**
 * The answer of one table.
 *
 * @param array{limit: int, offset: int, date_from: ?string, date_to: ?string} $in
 * @return array<string, mixed>
 */
function powerbi_table_answer(PDO $pdo, string $table, array $in): array
{
    return match ($table) {
        'meta' => powerbi_meta($pdo),
        'submissions' => powerbi_paged($pdo, 'submissions', $in),
        'answers' => powerbi_paged($pdo, 'answers', $in),
        'stores' => powerbi_dimension('stores', powerbi_rows($pdo, 'SELECT id AS store_id, name AS store_name, region_id, address, contact_info FROM stores ORDER BY name, id')),
        'regions' => powerbi_dimension('regions', powerbi_rows($pdo, 'SELECT id AS region_id, name AS region_name FROM regions ORDER BY name, id')),
        'versions' => powerbi_dimension('versions', powerbi_rows($pdo, POWERBI_VERSIONS_SQL)),
        'categories' => powerbi_dimension('categories', powerbi_rows($pdo, POWERBI_CATEGORIES_SQL)),
        default => powerbi_dimension('questions', powerbi_rows($pdo, POWERBI_QUESTIONS_SQL)),
    };
}

const POWERBI_VERSIONS_SQL = 'SELECT tv.id AS version_id, tv.template_id, t.title AS template_title, tv.version_number, tv.published_at,
        CONCAT(u.first_name, \' \', u.last_name) AS published_by
    FROM survey_template_versions tv
    JOIN survey_templates t ON tv.template_id = t.id
    LEFT JOIN users u ON tv.published_by_user_id = u.id
    ORDER BY tv.published_at DESC, tv.id DESC';
const POWERBI_CATEGORIES_SQL = 'SELECT vc.id AS category_id, vc.version_id, vc.name AS category_name, vc.position
    FROM survey_version_categories vc ORDER BY vc.version_id, vc.position, vc.id';
const POWERBI_QUESTIONS_SQL = 'SELECT vq.id AS question_id, vq.version_id, vq.version_category_id AS category_id, vq.question_text,
        vq.scoring_guidance, vq.position
    FROM survey_version_questions vq ORDER BY vq.version_id, vq.version_category_id, vq.position, vq.id';

/** Columns of each table that are whole numbers, and those that may be NULL, as the export has always typed them. */
const POWERBI_INT_COLUMNS = [
    'submission_id', 'store_id', 'user_id', 'version_id', 'answer_id', 'category_id', 'category_position', 'question_id',
    'question_position', 'template_id', 'version_number', 'position', 'region_id', 'score',
];

/**
 * Rows of a fixed query with the export's types: ids and positions as integers, the average score as a float, NULL kept.
 *
 * @param list<int|string|null> $params
 * @return list<array<string, mixed>>
 */
function powerbi_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach ($row as $column => $value) {
            if ($value !== null && in_array($column, POWERBI_INT_COLUMNS, true)) {
                $row[$column] = (int)$value;
            } elseif ($value !== null && $column === 'overall_avg_score') {
                $row[$column] = (float)$value;
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * A dimension table: every row.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function powerbi_dimension(string $table, array $rows): array
{
    return ['success' => true, 'table' => $table, 'total_rows' => count($rows), 'rows' => $rows];
}

/** The table list with row counts. @return array<string, mixed> */
function powerbi_meta(PDO $pdo): array
{
    $counts = [
        'submissions' => 'SELECT COUNT(*) FROM survey_submissions WHERE status = \'submitted\'',
        'answers' => 'SELECT COUNT(*) FROM survey_answers a JOIN survey_submissions s ON a.submission_id = s.id WHERE s.status = \'submitted\'',
        'stores' => 'SELECT COUNT(*) FROM stores',
        'regions' => 'SELECT COUNT(*) FROM regions',
        'versions' => 'SELECT COUNT(*) FROM survey_template_versions',
        'categories' => 'SELECT COUNT(*) FROM survey_version_categories',
        'questions' => 'SELECT COUNT(*) FROM survey_version_questions',
    ];
    $tables = [];
    foreach ($counts as $name => $sql) {
        $count = $pdo->prepare($sql);
        $count->execute();
        $tables[] = ['table' => $name, 'row_count' => (int)$count->fetchColumn(), 'endpoint' => '?table=' . $name];
    }
    return [
        'success' => true,
        'api' => 'Quack Track Power BI Export',
        'version' => '1.0',
        'tables' => $tables,
        'pagination' => 'Use ?limit=N&offset=N for large tables (max 10,000 rows per request).',
        'filters' => 'Use ?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD on submissions and answers tables.',
    ];
}

/** Submitted surveys in the date window (inclusive whole days); the ? pairs make an unset bound no filter. */
const POWERBI_SUBMITTED_SQL = "s.status = 'submitted' AND (? IS NULL OR s.submitted_at >= ?) AND (? IS NULL OR s.submitted_at <= ?)";

const POWERBI_PAGED_SQL = [
    'submissions' => [
        'count' => 'SELECT COUNT(*) FROM survey_submissions s WHERE ' . POWERBI_SUBMITTED_SQL,
        'rows' => "SELECT s.id AS submission_id, s.store_id, st.name AS store_name, s.region_id, r.name AS region_name, s.user_id,
                CONCAT(u.first_name, ' ', u.last_name) AS submitter_name, s.submitter_role, s.version_id, tv.version_number,
                t.title AS template_title, s.overall_avg_score, s.submitted_at, s.created_at
            FROM survey_submissions s
            LEFT JOIN stores st ON s.store_id = st.id
            LEFT JOIN regions r ON s.region_id = r.id
            LEFT JOIN users u ON s.user_id = u.id
            LEFT JOIN survey_template_versions tv ON s.version_id = tv.id
            LEFT JOIN survey_templates t ON tv.template_id = t.id
            WHERE " . POWERBI_SUBMITTED_SQL . ' ORDER BY s.submitted_at DESC, s.id DESC LIMIT ? OFFSET ?',
    ],
    'answers' => [
        'count' => 'SELECT COUNT(*) FROM survey_answers a JOIN survey_submissions s ON a.submission_id = s.id WHERE ' . POWERBI_SUBMITTED_SQL,
        'rows' => 'SELECT a.id AS answer_id, a.submission_id, s.store_id, s.region_id, s.user_id, s.submitter_role, s.submitted_at,
                vc.id AS category_id, vc.name AS category_name, vc.position AS category_position, vq.id AS question_id,
                vq.question_text, vq.position AS question_position, a.score, a.notes
            FROM survey_answers a
            JOIN survey_submissions s ON a.submission_id = s.id
            JOIN survey_version_questions vq ON a.version_question_id = vq.id
            JOIN survey_version_categories vc ON vq.version_category_id = vc.id
            WHERE ' . POWERBI_SUBMITTED_SQL . ' ORDER BY a.submission_id, vc.position, vq.position, a.id LIMIT ? OFFSET ?',
    ],
];

/**
 * One page of submissions or answers of submitted surveys, with the total in the window.
 *
 * @param 'submissions'|'answers' $table
 * @param array{limit: int, offset: int, date_from: ?string, date_to: ?string} $in
 * @return array<string, mixed>
 */
function powerbi_paged(PDO $pdo, string $table, array $in): array
{
    $from = $in['date_from'] === null ? null : $in['date_from'] . ' 00:00:00';
    $to = $in['date_to'] === null ? null : $in['date_to'] . ' 23:59:59';
    $window = [$from, $from, $to, $to];
    $count = $pdo->prepare(POWERBI_PAGED_SQL[$table]['count']);
    $count->execute($window);
    return [
        'success' => true,
        'table' => $table,
        'total_rows' => (int)$count->fetchColumn(),
        'limit' => $in['limit'],
        'offset' => $in['offset'],
        'rows' => powerbi_rows($pdo, POWERBI_PAGED_SQL[$table]['rows'], [...$window, $in['limit'], $in['offset']]),
    ];
}
