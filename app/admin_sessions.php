<?php

require_once __DIR__ . '/config.php';
require_login();
require_permission('admin.sessions.manage');

$pdo = db();

// Handle session management actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = req_raw_string($_POST, 'action');

    switch ($action) {
        case 'cleanup_sessions':
            $cleaned = cleanup_expired_sessions();
            flash_message("Cleaned up $cleaned expired sessions", 'success');
            Logger::info('admin_action', 'Manual session cleanup performed', [
                'cleaned_count' => $cleaned,
                'admin_user' => $_SESSION['uid']
            ]);
            break;

        case 'force_logout_user':
            $userId = req_int($_POST, 'user_id');
            if ($userId > 0 && $userId !== $_SESSION['uid']) {
                if (revoke_user_sessions($userId)) {
                    flash_message('All sessions for this user have been revoked. They must sign in again.', 'success');
                } else {
                    flash_message('User not found.', 'error');
                }
            } else {
                flash_message('Invalid user ID or cannot logout yourself', 'error');
            }
            break;
    }

    redirect('/admin_sessions.php');
}

// Get session statistics
$sessionStats = [
    'active_sessions' => get_active_sessions_count(),
    'session_lifetime' => ini_get('session.gc_maxlifetime'),
    'session_save_path' => session_save_path() ?: sys_get_temp_dir(),
    'session_name' => session_name(),
    'current_session_id' => session_id()
];

// Get recent login/logout events
$recentSecurity = [];
$securityLogFile = '/var/www/logs/security_' . date('Y-m-d') . '.log';
if (file_exists($securityLogFile)) {
    $lines = file($securityLogFile);
    $filteredLines = [];

    foreach (array_reverse($lines) as $line) {
        $event = json_decode($line);
        if ($event && in_array($event->event ?? '', ['successful_login', 'user_logout', 'session_timeout', 'session_hijacking_attempt'])) {
            $filteredLines[] = $event;
            if (count($filteredLines) >= 20) {
                break; // Limit to 20 events
            }
        }
    }

    $recentSecurity = $filteredLines;
}

// Get active users (simplified - in production, this would query a sessions table)
$activeUsers = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.first_name, u.last_name, u.username,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS display_name,
               r.name as region_name
        FROM users u
        LEFT JOIN regions r ON u.region_id = r.id
        ORDER BY u.id ASC
    ");
    $stmt->execute();
    $activeUsers = $stmt->fetchAll();
} catch (Exception $e) {
    qt_rethrow_bad_input($e);
    Logger::error('admin_sessions_query', $e);
}

require __DIR__ . '/views/admin_sessions.view.php';
