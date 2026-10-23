<?php

/**
 * Snipe-IT reports: asset activity history, the combined supply/equipment summary and its CSV export. The checks,
 * reports and answers are in includes/snipe_reports_api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_login();
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/snipe_reports_api.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = strtolower(trim(is_string($_GET['action'] ?? null) ? $_GET['action'] : ''));
$userId = (int)($_SESSION['uid'] ?? 0);
match ($action) {
    'asset_history' => snipe_reports_dispatch($action, $userId),
    'combined_summary' => snipe_reports_dispatch($action, $userId),
    'export_csv' => snipe_reports_dispatch($action, $userId),
    // An unknown action is answered as a 400 by the dispatcher.
    default => snipe_reports_dispatch($action, $userId),
};
