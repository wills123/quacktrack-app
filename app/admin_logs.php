<?php

require_once __DIR__ . '/config.php';
require_login();
require_permission('admin.logs.view');

$pdo = db();

// Handle log actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = req_raw_string($_POST, 'action');

    switch ($action) {
        case 'clear_logs':
            require_permission('admin.logs.clear');
            $logType = req_raw_string($_POST, 'log_type');
            if (in_array($logType, ['error', 'warning', 'security', 'performance', 'info', 'debug'])) {
                $logFile = '/var/www/logs/' . $logType . '_' . date('Y-m-d') . '.log';
                if (file_exists($logFile)) {
                    unlink($logFile);
                    Logger::info('admin_action', 'Log file cleared', [
                        'log_type' => $logType,
                        'admin_user' => $_SESSION['uid']
                    ]);
                    flash_message("$logType logs cleared successfully", 'success');
                } else {
                    flash_message("$logType log file not found", 'warning');
                }
            }
            break;

        case 'download_logs':
            $logType = req_raw_string($_POST, 'log_type');
            if (in_array($logType, ['error', 'warning', 'security', 'performance', 'info', 'debug'])) {
                $logFile = '/var/www/logs/' . $logType . '_' . date('Y-m-d') . '.log';
                if (file_exists($logFile)) {
                    Logger::info('admin_action', 'Log file downloaded', [
                        'log_type' => $logType,
                        'admin_user' => $_SESSION['uid']
                    ]);

                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($logFile) . '"');
                    header('Content-Length: ' . filesize($logFile));
                    readfile($logFile);
                    exit;
                }
            }
            break;
    }

    redirect('/admin_logs.php');
}

// Get log statistics
$logStats = Logger::getLogStats();

// Get recent error entries
$recentErrors = [];
$errorLogFile = '/var/www/logs/error_' . date('Y-m-d') . '.log';
if (file_exists($errorLogFile)) {
    $lines = file($errorLogFile);
    $recentErrors = array_slice(array_reverse($lines), 0, 10);
    $recentErrors = array_map('json_decode', $recentErrors);
    $recentErrors = array_filter($recentErrors); // Remove invalid JSON
}

// Get recent security events
$recentSecurity = [];
$securityLogFile = '/var/www/logs/security_' . date('Y-m-d') . '.log';
if (file_exists($securityLogFile)) {
    $lines = file($securityLogFile);
    $recentSecurity = array_slice(array_reverse($lines), 0, 10);
    $recentSecurity = array_map('json_decode', $recentSecurity);
    $recentSecurity = array_filter($recentSecurity); // Remove invalid JSON
}

// Get system information
$systemInfo = [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'current_memory_usage' => memory_get_usage(true),
    'peak_memory_usage' => memory_get_peak_usage(true),
    'disk_free_space' => disk_free_space('/var/www'),
    'disk_total_space' => disk_total_space('/var/www')
];

require __DIR__ . '/views/admin_logs.view.php';
