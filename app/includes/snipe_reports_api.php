<?php

declare(strict_types=1);

require_once __DIR__ . '/snipe_api_errors.php';
require_once __DIR__ . '/input_schemas/snipe_reports.php';
require_once __DIR__ . '/snipe_reports_service.php';

/**
 * api/snipe-it-reports.php. Order of checks: action (400), method (GET, 405), permission (assets.view; assets.reports
 * for the CSV export; 403), Snipe-IT switched on (503), input (400 wrong type, 422 rule), then the data: Snipe-IT not
 * answering usably is 502. JSON answers keep their {success, data, message} shape; the export answers text/csv, and
 * every refusal or failure before it starts is JSON.
 */

/** The largest report: rows of each section in the summary and in the export. */
const SNIPE_REPORTS_SUMMARY_ROWS = 500;
const SNIPE_REPORTS_EXPORT_ROWS = 5000;

/** Handle one report; every answer ends the request. */
function snipe_reports_dispatch(string $action, int $userId): void
{
    try {
        if (!in_array($action, SNIPE_REPORTS_ACTIONS, true)) {
            throw new BadRequestException('Unsupported action.');
        }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            throw new ClientErrorException('Method not allowed', 405);
        }
        require_permission($action === 'export_csv' ? 'assets.reports' : 'assets.view');
        $client = snipe_catalog_client($userId);
        $in = snipe_reports_input($action);
        $viewer = new SnipeReportsViewer($userId, snipe_reports_linked_id(db(), $userId), has_permission('assets.reports'));
        snipe_reports_answer($action, $client, $viewer, $in);
    } catch (Throwable $e) {
        snipe_api_fail($e, 'snipe_reports_provider_error', 'snipe_it_reports_error', ['action' => $action, 'user_id' => $userId], 'Unable to retrieve reports.');
    }
}

/**
 * Build and send one report.
 *
 * @param array<string, mixed> $in
 */
function snipe_reports_answer(string $action, SnipeItClient $client, SnipeReportsViewer $viewer, array $in): void
{
    $window = array_intersect_key($in, ['start_date' => true, 'end_date' => true]);
    if ($action === 'asset_history') {
        $activities = snipe_reports_activity($client, $viewer, $window, $in['limit'], $in['offset']);
        api_success(['activities' => $activities, 'total' => count($activities), 'can_view_all' => $viewer->canViewAll], 'Asset history retrieved successfully.');
    }
    $rows = $action === 'export_csv' ? SNIPE_REPORTS_EXPORT_ROWS : SNIPE_REPORTS_SUMMARY_ROWS;
    // Both sections are read before anything is sent, so a failure is still a JSON answer.
    $equipment = snipe_reports_activity($client, $viewer, $window, $rows);
    $supply = snipe_reports_supply(db(), $viewer, $window, $rows);
    if ($action === 'export_csv') {
        snipe_reports_send_csv($supply, $equipment);
    }
    api_success([
        'supply_usage' => $supply,
        'equipment_checkouts' => $equipment,
        'total_supply_items' => count($supply),
        'total_equipment_items' => count($equipment),
        'can_view_all' => $viewer->canViewAll,
        'date_range' => ['start' => $window['start_date'] ?? 'all-time', 'end' => $window['end_date'] ?? 'today'],
    ], 'Combined report retrieved successfully.');
}

/**
 * Send the CSV export and end the request.
 *
 * @param list<array<string, mixed>> $supply
 * @param list<array{item_name: string, action_type: string, user_name: string, created_at: string}> $equipment
 */
function snipe_reports_send_csv(array $supply, array $equipment): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="snipe-it-report-' . date('Y-m-d') . '.csv"');
    $output = csv_open_output();
    snipe_reports_write_csv($output, $supply, $equipment);
    fclose($output);
    // arch-ok: exit-in-library - the export has been sent
    exit;
}
