<?php

/**
 * The reports of api/snipe-it-reports.php: Snipe-IT asset activity (read with the shared service-account client) and
 * van stock usage from the local database, each limited to the viewer's own rows unless they hold assets.reports.
 * Only reads are sent to Snipe-IT; an answer it does not give, or gives in an unusable shape, is a
 * SnipeProviderException (502), never an empty report.
 */

declare(strict_types=1);

require_once __DIR__ . '/api_errors.php';
require_once __DIR__ . '/csv_export.php';
require_once __DIR__ . '/snipe_catalog_service.php';

/** Activity rows Snipe-IT is asked for per request when a report needs more than one page. */
const SNIPE_REPORTS_PAGE = 500;

/** Who is asking: their Quack Track and Snipe-IT ids, and whether they may see everyone's rows (assets.reports). */
final class SnipeReportsViewer
{
    // qm-ok: flag-param - the viewer's reports permission is a fact about them, read once at the boundary
    public function __construct(public readonly int $userId, public readonly int $snipeUserId, public readonly bool $canViewAll)
    {
    }

    /** Whether an activity row is about this viewer (its target, or its user when there is no target). */
    public function sees(array $row): bool
    {
        if ($this->canViewAll) {
            return true;
        }
        $target = is_array($row['target'] ?? null) ? ($row['target']['id'] ?? null) : null;
        $target ??= $row['user_id'] ?? null;
        return $this->snipeUserId > 0 && is_numeric($target) && (int)$target === $this->snipeUserId;
    }
}

/**
 * The Snipe-IT id linked to the user, from the account (not the session, which only a Snipe-IT sign-in fills), or 0.
 */
function snipe_reports_linked_id(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT snipe_user_id FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Activity rows Snipe-IT holds for the window, as the report shows them, from $offset on: one request when
 * $max fits a page, otherwise pages of SNIPE_REPORTS_PAGE until $max rows were read or the list ends.
 *
 * @param array{start_date?: string, end_date?: string} $window
 * @return list<array{item_name: string, action_type: string, user_name: string, created_at: string}>
 * @throws SnipeProviderException
 */
function snipe_reports_activity(SnipeItClient $client, SnipeReportsViewer $viewer, array $window, int $max, int $offset = 0): array
{
    $rows = [];
    $read = 0;
    do {
        $limit = min($max - $read, SNIPE_REPORTS_PAGE);
        $page = snipe_reports_activity_page($client->listActivities(['limit' => $limit, 'offset' => $offset + $read] + $window));
        $read += count($page['rows']);
        foreach ($page['rows'] as $row) {
            if ($viewer->sees($row)) {
                $rows[] = snipe_reports_activity_row($row);
            }
        }
    } while ($page['rows'] !== [] && $read < $max && $offset + $read < $page['total']);
    return $rows;
}

/**
 * The rows and total of one activity answer, which must hold a list of records and a whole total.
 *
 * @param array<string, mixed>|null $answer
 * @return array{rows: list<array<string, mixed>>, total: int}
 * @throws SnipeProviderException
 */
function snipe_reports_activity_page(?array $answer): array
{
    $rows = $answer['rows'] ?? null;
    $records = is_array($rows) && array_is_list($rows) && $rows === array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) && !array_is_list($row)));
    if (!$records || !is_int($answer['total'] ?? null) || $answer['total'] < 0) {
        throw new SnipeProviderException('Snipe-IT did not return the activity report.');
    }
    return ['rows' => $rows, 'total' => $answer['total']];
}

/**
 * One activity row as the report shows it: the asset, the action, who it was for (the target, or the admin who acted
 * when there is no named target; a bare number is an id, not a name) and when.
 *
 * @param array<string, mixed> $row
 * @return array{item_name: string, action_type: string, user_name: string, created_at: string}
 */
function snipe_reports_activity_row(array $row): array
{
    $created = $row['created_at'] ?? '';
    if (is_array($created)) {
        $created = $created['datetime'] ?? $created['formatted'] ?? '';
    }
    return [
        'item_name' => snipe_reports_text($row['item']['name'] ?? $row['item_name'] ?? $row['asset_name'] ?? null, 'Unknown'),
        'action_type' => snipe_reports_text($row['action_type'] ?? $row['action'] ?? null, 'Unknown'),
        'user_name' => snipe_reports_person($row),
        'created_at' => snipe_reports_text($created, ''),
    ];
}

/** The person an activity row names. @param array<string, mixed> $row */
function snipe_reports_person(array $row): string
{
    foreach ([$row['target']['name'] ?? null, $row['admin']['name'] ?? null] as $name) {
        if (is_string($name) && trim($name) !== '' && !is_numeric(trim($name))) {
            return $name;
        }
    }
    return snipe_reports_text($row['user_name'] ?? null, '—');
}

/** $value when it is text or a number, $default otherwise (a nested value is never shown). */
function snipe_reports_text(mixed $value, string $default): string
{
    return is_string($value) || is_int($value) || is_float($value) ? (string)$value : $default;
}

/**
 * Van stock usage in the window, newest first, the viewer's own unless they may see everyone's.
 *
 * @param array{start_date?: string, end_date?: string} $window
 * @return list<array<string, mixed>>
 */
function snipe_reports_supply(PDO $pdo, SnipeReportsViewer $viewer, array $window, int $limit): array
{
    $owner = $viewer->canViewAll ? 0 : $viewer->userId;
    $from = isset($window['start_date']) ? $window['start_date'] . ' 00:00:00' : null;
    $to = isset($window['end_date']) ? $window['end_date'] . ' 23:59:59' : null;
    $stmt = $pdo->prepare(
        'SELECT vst.id, vst.product_id, p.name AS product_name, vst.quantity_change AS quantity, vst.reason AS transaction_type,
                vst.created_at, vst.user_id, u.first_name, u.last_name
           FROM van_stock_transactions vst
           LEFT JOIN products p ON vst.product_id = p.id
           LEFT JOIN users u ON vst.user_id = u.id
          WHERE (? = 0 OR vst.user_id = ?) AND (? IS NULL OR vst.created_at >= ?) AND (? IS NULL OR vst.created_at <= ?)
          ORDER BY vst.created_at DESC, vst.id DESC
          LIMIT ?'
    );
    $stmt->execute([$owner, $owner, $from, $from, $to, $to, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Write the CSV export: the supply section, two blank rows, then the equipment section. Cells go through
 * csv_escape(), which neutralises spreadsheet formulas.
 *
 * @param resource $output
 * @param list<array<string, mixed>> $supply
 * @param list<array{item_name: string, action_type: string, user_name: string, created_at: string}> $equipment
 */
function snipe_reports_write_csv($output, array $supply, array $equipment): void
{
    csv_write_row($output, ['SUPPLY USAGE REPORT']);
    csv_write_row($output, ['Product', 'Quantity', 'Type', 'User', 'Date']);
    foreach ($supply as $row) {
        csv_write_row($output, [
            $row['product_name'] ?? 'Unknown', $row['quantity'] ?? 0, $row['transaction_type'] ?? '',
            trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')), $row['created_at'] ?? '',
        ]);
    }
    csv_write_row($output, []);
    csv_write_row($output, []);
    csv_write_row($output, ['EQUIPMENT CHECKOUT/CHECKIN REPORT']);
    csv_write_row($output, ['Asset', 'Action', 'User', 'Date']);
    foreach ($equipment as $row) {
        csv_write_row($output, [$row['item_name'], $row['action_type'], $row['user_name'], $row['created_at']]);
    }
}
