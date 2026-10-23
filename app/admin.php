<?php

require_once __DIR__ . '/config.php';
require_login();
require_permission('admin.access');
$pdo = db();
// Handle different admin actions via AJAX or form submissions
// Request parameters can arrive as arrays (?tab[]=x, action[]=y); req_string() reads only
// plain strings, so an array can never be used as an array key below.
$action = isset($_POST['action']) ? req_string($_POST, 'action') : req_string($_GET, 'action');
$tab = req_string($_GET, 'tab', 'dashboard');
// Current tab being viewed

// The Logs, Files and Sessions tabs and actions need the same permissions as their
// standalone pages (/admin_logs, /admin_files, /admin_sessions). Note that
// has_permission() currently lets admin.access satisfy any permission, so for admins this
// changes nothing today; it stops the dashboard depending on that blanket rule.
$canViewLogs = has_permission('admin.logs.view');
$canViewFiles = has_permission('admin.files.manage');
$canViewSessions = has_permission('admin.sessions.manage');
$adminActionPermissions = [
    'download_logs' => 'admin.logs.view',
    'clear_logs' => 'admin.logs.clear',
    'cleanup_old_files' => 'admin.files.manage',
    'delete_file' => 'admin.files.manage',
    'cleanup_sessions' => 'admin.sessions.manage',
    'force_logout_user' => 'admin.sessions.manage',
];
$restrictedTabs = ['logs' => $canViewLogs, 'files' => $canViewFiles, 'sessions' => $canViewSessions];
if (isset($restrictedTabs[$tab]) && !$restrictedTabs[$tab]) {
    $tab = 'dashboard';
}

// Handle AJAX requests for admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    csrf_verify();
    if (isset($adminActionPermissions[$action])) {
        require_permission($adminActionPermissions[$action]);
    }
// Route to appropriate admin handler based on action
    // This allows the dashboard to handle all admin operations

    switch ($action) {
// User management
        case 'add_user':
        case 'edit_user':
        case 'delete_user':
        case 'force_password_reset':
        case 'generate_temp_password':
            include __DIR__ . '/admin/users.php';

            exit;
// Store management
        case 'add_store':
        case 'edit_store':
        case 'delete_store':
        case 'bulk_import':
            include __DIR__ . '/admin/stores.php';

            exit;
// Region management
        case 'add_region':
        case 'edit_region':
        case 'delete_region':
            include __DIR__ . '/admin/regions.php';

            exit;
        case 'create_role':
        case 'update_role_permissions':
        case 'delete_role':
        case 'assign_user_roles':
            include __DIR__ . '/admin/roles.php';

            exit;
// Log management
        case 'clear_logs':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $logType = req_string($_POST, 'log_type');
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
            redirect('/admin.php?tab=logs');

            exit;
        case 'download_logs':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $logType = req_string($_POST, 'log_type');
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
// File management
        case 'cleanup_old_files':
            try {
                $maxAgeDays = req_int_strict($_POST, 'max_age', 1, 3650);
            } catch (BadRequestException $e) {
                // A bad age must never fall back to a default: it decides which files are deleted.
                flash_message('Cleanup age must be a whole number of days between 1 and 3650.', 'error');
                redirect('/admin.php?tab=files');
                exit;
            }
            $cleaned = FileManager::cleanupOldFiles($maxAgeDays * 86400);
            flash_message("Cleaned up $cleaned old files", 'success');
            Logger::info('admin_action', 'Manual file cleanup performed', [
                'cleaned_count' => $cleaned,
                'max_age_days' => $maxAgeDays,
                'admin_user' => $_SESSION['uid']
            ]);
            redirect('/admin.php?tab=files');

            exit;
        case 'delete_file':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $filename = req_raw_string($_POST, 'filename');
            if ($filename) {
                if (FileManager::deleteFile($filename, 'admin_delete')) {
                    flash_message("File '$filename' deleted successfully", 'success');
                } else {
                    flash_message("Failed to delete file '$filename'", 'error');
                }
            }
            redirect('/admin.php?tab=files');

            exit;
// Session management
        case 'cleanup_sessions':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $cleaned = cleanup_expired_sessions();
            flash_message("Cleaned up $cleaned expired sessions", 'success');
            Logger::info('admin_action', 'Manual session cleanup performed', [
                'cleaned_count' => $cleaned,
                'admin_user' => $_SESSION['uid']
            ]);
            redirect('/admin.php?tab=sessions');

            exit;
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
            redirect('/admin.php?tab=sessions');

            exit;
        case 'update_settings':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $maxQty = req_int($_POST, 'max_order_quantity', get_max_order_quantity());
            $maxQty = max(1, min(100000, $maxQty));
            // A missing or wrong-typed value leaves the current setting unchanged.
            $incomingValidation = is_string($_POST['checkout_validation_enabled'] ?? null) ? $_POST['checkout_validation_enabled'] : null;
            if ($incomingValidation === null) {
                $validationValue = is_checkout_validation_enabled() ? '1' : '0';
            } else {
                $normalized = strtolower(trim($incomingValidation));
                $validationValue = in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
            }

            $deploymentShipmentApprovalBypassEnabled = isset($_POST['deployment_shipment_approval_bypass_enabled'])
                && $_POST['deployment_shipment_approval_bypass_enabled'] === '1';
            $incomingBypassReason = $_POST['deployment_shipment_approval_bypass_reason'] ?? '';
            if (!is_string($incomingBypassReason)) {
                flash_message('Deployment shipment approval bypass reason must be valid text.', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }

            $deploymentShipmentApprovalBypassReason = trim($incomingBypassReason);
            $reasonLength = function_exists('mb_strlen')
                ? mb_strlen($deploymentShipmentApprovalBypassReason, 'UTF-8')
                : strlen($deploymentShipmentApprovalBypassReason);
            if ($reasonLength > 500) {
                flash_message('Deployment shipment approval bypass reason must be 500 characters or fewer.', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }
            if ($deploymentShipmentApprovalBypassEnabled && $deploymentShipmentApprovalBypassReason === '') {
                flash_message('Provide a reason before enabling deployment shipment approval bypass.', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }

            try {
                $pdo->beginTransaction();
                $settingStmt = $pdo->prepare('
                    INSERT INTO app_settings (setting_key, setting_value, updated_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        setting_value = VALUES(setting_value),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ');
                $settingStmt->execute([
                    'deployment_shipment_approval_bypass_enabled',
                    $deploymentShipmentApprovalBypassEnabled ? '1' : '0',
                    $_SESSION['uid'] ?? null,
                ]);
                $settingStmt->execute([
                    'deployment_shipment_approval_bypass_reason',
                    $deploymentShipmentApprovalBypassReason,
                    $_SESSION['uid'] ?? null,
                ]);
                $pdo->commit();
                app_settings_cache(true);

                AuditLogger::log('settings.manage', [
                    'entity_type' => 'app_setting',
                    'description' => 'Updated deployment shipment approval bypass settings',
                    'metadata' => [
                        'deployment_shipment_approval_bypass_enabled' => $deploymentShipmentApprovalBypassEnabled ? '1' : '0',
                        'bypass_reason_length' => $reasonLength,
                    ],
                ]);
                Logger::info('admin_update_deployment_shipment_approval_bypass', 'Deployment shipment approval bypass settings updated', [
                    'admin_user' => $_SESSION['uid'] ?? null,
                    'deployment_shipment_approval_bypass_enabled' => $deploymentShipmentApprovalBypassEnabled ? '1' : '0',
                    'bypass_reason_length' => $reasonLength,
                ]);
            } catch (Throwable $e) {
                qt_rethrow_bad_input($e);
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Logger::error('admin_update_deployment_shipment_approval_bypass_failed', $e, [
                    'admin_user' => $_SESSION['uid'] ?? null,
                ]);
                flash_message('Unable to update deployment shipment approval bypass settings right now. Please try again.', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }

            $maxUpdated = set_app_setting('max_order_quantity', (string)$maxQty, $_SESSION['uid'] ?? null);
            $validationUpdated = set_app_setting('checkout_validation_enabled', $validationValue, $_SESSION['uid'] ?? null);
            $messages = [];
            if ($maxUpdated) {
                $messages[] = "Max per-item order limit updated to {$maxQty}.";
            }
            if ($validationUpdated) {
                $stateLabel = $validationValue === '1' ? 'enabled' : 'disabled';
                $messages[] = "Checkout validation {$stateLabel}.";
            }
            $messages[] = $deploymentShipmentApprovalBypassEnabled
                ? 'Deployment shipment approval bypass enabled.'
                : 'Deployment shipment approval bypass disabled.';

            Logger::info('admin_update_settings', 'Settings updated', [
                'admin_user' => $_SESSION['uid'] ?? null,
                'max_order_quantity' => $maxQty,
                'checkout_validation_enabled' => $validationValue,
            ]);
            flash_message(implode(' ', $messages), 'success');

            redirect('/admin.php?tab=settings');

            exit;
        case 'update_turnstile_keys':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $siteKey = req_string($_POST, 'turnstile_site_key');
            $secretKey = req_string($_POST, 'turnstile_secret_key');
            $turnstileEnabled = isset($_POST['turnstile_enabled']) && $_POST['turnstile_enabled'] === '1' ? '1' : '0';
        // Validate keys format
            if (empty($siteKey) || empty($secretKey)) {
                flash_message('Both Site Key and Secret Key are required', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }

            // Validate key format (should start with 0x)
            if (!preg_match('/^0x[a-zA-Z0-9_-]+$/', $siteKey)) {
                flash_message('Invalid Site Key format. Must start with "0x"', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }

            if (!preg_match('/^0x[a-zA-Z0-9_-]+$/', $secretKey)) {
                flash_message('Invalid Secret Key format. Must start with "0x"', 'error');
                redirect('/admin.php?tab=settings');
                exit;
            }

            // Update app_settings (writing directly to .env fails with
            // "Permission denied" once the app runs in Docker, since the
            // container can't write back to a file mounted from the host)
            try {
                $adminUserId = $_SESSION['uid'] ?? null;

                if (!set_app_setting('turnstile_site_key', $siteKey, $adminUserId)) {
                    throw new Exception('Failed to save Turnstile Site Key');
                }
                if (!set_app_setting('turnstile_secret_key', $secretKey, $adminUserId)) {
                    throw new Exception('Failed to save Turnstile Secret Key');
                }
                if (!set_app_setting('turnstile_enabled', $turnstileEnabled, $adminUserId)) {
                    throw new Exception('Failed to save Turnstile enabled setting');
                }

                Logger::info('admin_update_turnstile_keys', 'Turnstile keys updated successfully', [
                    'admin_user' => $_SESSION['uid'] ?? null,
                    'admin_email' => $_SESSION['email'] ?? 'unknown'
                ]);
                Logger::security('turnstile_keys_updated', [
                    'admin_user' => $_SESSION['uid'] ?? null,
                    'admin_email' => $_SESSION['email'] ?? 'unknown',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                flash_message('Turnstile keys updated successfully! Changes will take effect immediately.', 'success');
            } catch (Exception $e) {
                qt_rethrow_bad_input($e);
                Logger::error('admin_update_turnstile_keys_failed', $e, [
                'admin_user' => $_SESSION['uid'] ?? null,
                'error' => $e->getMessage()
                ]);
                flash_message('Failed to update Turnstile keys: ' . $e->getMessage(), 'error');
            }

            redirect('/admin.php?tab=settings');

            exit;
        case 'update_register_images':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $leftImage = req_string($_POST, 'register_left_image');
            $rightImage = req_string($_POST, 'register_right_image');
        // Validate URLs if provided
            if (!empty($leftImage)) {
// Check if it's a relative path or URL
                if (!preg_match('/^(https?:\/\/|\/)/i', $leftImage)) {
                    flash_message('Left image must be a valid URL (https://...) or path (/assets/...)', 'error');
                    redirect('/admin.php?tab=settings');
                    exit;
                }
            }

            if (!empty($rightImage)) {
// Check if it's a relative path or URL
                if (!preg_match('/^(https?:\/\/|\/)/i', $rightImage)) {
                    flash_message('Right image must be a valid URL (https://...) or path (/assets/...)', 'error');
                    redirect('/admin.php?tab=settings');
                    exit;
                }
            }

            // Update app_settings (see the note on update_turnstile_keys above)
            try {
                $adminUserId = $_SESSION['uid'] ?? null;

                if (!set_app_setting('register_left_image', $leftImage, $adminUserId)) {
                    throw new Exception('Failed to save left image URL');
                }
                if (!set_app_setting('register_right_image', $rightImage, $adminUserId)) {
                    throw new Exception('Failed to save right image URL');
                }

                Logger::info('admin_update_register_images', 'Registration page images updated', [
                    'admin_user' => $_SESSION['uid'] ?? null,
                    'admin_email' => $_SESSION['email'] ?? 'unknown',
                    'left_image_set' => !empty($leftImage),
                    'right_image_set' => !empty($rightImage)
                ]);
                $message = 'Registration page images updated successfully!';
                if (empty($leftImage) && empty($rightImage)) {
                    $message = 'Registration page images cleared. Side images are now hidden.';
                } elseif (empty($leftImage)) {
                    $message = 'Right side image updated. Left side image is hidden.';
                } elseif (empty($rightImage)) {
                    $message = 'Left side image updated. Right side image is hidden.';
                }

                flash_message($message, 'success');
            } catch (Exception $e) {
                qt_rethrow_bad_input($e);
                Logger::error('admin_update_register_images_failed', $e, [
                'admin_user' => $_SESSION['uid'] ?? null,
                'error' => $e->getMessage()
                ]);
                flash_message('Failed to update registration images: ' . $e->getMessage(), 'error');
            }

            redirect('/admin.php?tab=settings');

            exit;
        case 'update_login_images':
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $leftImage = req_string($_POST, 'login_left_image');
            $rightImage = req_string($_POST, 'login_right_image');
        // Validate URLs if provided
            if (!empty($leftImage)) {
// Check if it's a relative path or URL
                if (!preg_match('/^(https?:\/\/|\/)/i', $leftImage)) {
                    flash_message('Left image must be a valid URL (https://...) or path (/assets/...)', 'error');
                    redirect('/admin.php?tab=settings');
                    exit;
                }
            }

            if (!empty($rightImage)) {
// Check if it's a relative path or URL
                if (!preg_match('/^(https?:\/\/|\/)/i', $rightImage)) {
                    flash_message('Right image must be a valid URL (https://...) or path (/assets/...)', 'error');
                    redirect('/admin.php?tab=settings');
                    exit;
                }
            }

            // Update app_settings (see the note on update_turnstile_keys above)
            try {
                $adminUserId = $_SESSION['uid'] ?? null;

                if (!set_app_setting('login_left_image', $leftImage, $adminUserId)) {
                    throw new Exception('Failed to save left image URL');
                }
                if (!set_app_setting('login_right_image', $rightImage, $adminUserId)) {
                    throw new Exception('Failed to save right image URL');
                }

                Logger::info('admin_update_login_images', 'Login page images updated', [
                    'admin_user' => $_SESSION['uid'] ?? null,
                    'admin_email' => $_SESSION['email'] ?? 'unknown',
                    'left_image_set' => !empty($leftImage),
                    'right_image_set' => !empty($rightImage)
                ]);
                $message = 'Login page images updated successfully!';
                if (empty($leftImage) && empty($rightImage)) {
                    $message = 'Login page images cleared. Side images are now hidden.';
                } elseif (empty($leftImage)) {
                    $message = 'Right side image updated. Left side image is hidden.';
                } elseif (empty($rightImage)) {
                    $message = 'Left side image updated. Right side image is hidden.';
                }

                flash_message($message, 'success');
            } catch (Exception $e) {
                qt_rethrow_bad_input($e);
                Logger::error('admin_update_login_images_failed', $e, [
                'admin_user' => $_SESSION['uid'] ?? null,
                'error' => $e->getMessage()
                ]);
                flash_message('Failed to update login images: ' . $e->getMessage(), 'error');
            }

            redirect('/admin.php?tab=settings');

            exit;
    }
}

// Fetch data for dashboard display
// Regional stock overview
$regional_stock_overview = $pdo->query('SELECT rs.region, SUM(rs.quantity) as total_quantity
                                        FROM region_storage rs
                                        GROUP BY rs.region
                                        ORDER BY rs.region ASC')->fetchAll();
// Users count
$users_count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
// Stores count
$stores_count = $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
// Regions count
$regions_count = $pdo->query('SELECT COUNT(*) FROM regions')->fetchColumn();
// Recent activity (optional - not used in current dashboard)
$recent_security_logs = [];
// Pagination setup for data tables in dashboard
$items_per_page = 10;
$users_page = max(1, req_int($_GET, 'users_page', 1));
$stores_page = max(1, req_int($_GET, 'stores_page', 1));
$regions_page = max(1, req_int($_GET, 'regions_page', 1));
// Fetch paginated users
$users_offset = ($users_page - 1) * $items_per_page;
$users_stmt = $pdo->prepare("
    SELECT u.id, u.email, u.first_name, u.last_name, u.username,
           COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS display_name,
           u.region_id, r.name as region, u.role
    FROM users u
    LEFT JOIN regions r ON u.region_id = r.id
    ORDER BY u.email ASC
    LIMIT ? OFFSET ?
");
$users_stmt->execute([$items_per_page, $users_offset]);
$users = $users_stmt->fetchAll();
// Fetch paginated stores
$stores_offset = ($stores_page - 1) * $items_per_page;
$stores_stmt = $pdo->prepare('SELECT id, name, store_number, address, contact_info FROM stores ORDER BY name ASC LIMIT ? OFFSET ?');
$stores_stmt->execute([$items_per_page, $stores_offset]);
$stores = $stores_stmt->fetchAll();
// Fetch paginated regions
$regions_offset = ($regions_page - 1) * $items_per_page;
$regions_stmt = $pdo->prepare('SELECT id, name FROM regions ORDER BY name ASC LIMIT ? OFFSET ?');
$regions_stmt->execute([$items_per_page, $regions_offset]);
$regions = $regions_stmt->fetchAll();
// Get total counts for pagination
$total_users = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$total_stores = $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
$total_regions = $pdo->query('SELECT COUNT(*) FROM regions')->fetchColumn();
$users_pages = max(1, ceil($total_users / $items_per_page));
$stores_pages = max(1, ceil($total_stores / $items_per_page));
$regions_pages = max(1, ceil($total_regions / $items_per_page));
// Get available regions for dropdowns
$available_regions = $pdo->query('SELECT id, name FROM regions ORDER BY name ASC')->fetchAll();
// Get system logs data (only for people allowed to view logs or sessions)
$logStats = $canViewLogs ? Logger::getLogStats() : [];
// Get recent error entries
$recentErrors = [];
$errorLogFile = '/var/www/logs/error_' . date('Y-m-d') . '.log';
if ($canViewLogs && file_exists($errorLogFile)) {
    $lines = file($errorLogFile);
    $recentErrors = array_slice(array_reverse($lines), 0, 5);
    $recentErrors = array_map('json_decode', $recentErrors);
    $recentErrors = array_filter($recentErrors);
}

// Get recent security events
$recentSecurity = [];
$securityLogFile = '/var/www/logs/security_' . date('Y-m-d') . '.log';
if (($canViewLogs || $canViewSessions) && file_exists($securityLogFile)) {
    $lines = file($securityLogFile);
    $recentSecurity = array_slice(array_reverse($lines), 0, 5);
    $recentSecurity = array_map('json_decode', $recentSecurity);
    $recentSecurity = array_filter($recentSecurity);
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
// Get disk usage information
$diskUsage = FileManager::getDiskUsage();
// Get session statistics
$sessionStats = [
    'active_sessions' => get_active_sessions_count(),
    'session_lifetime' => ini_get('session.gc_maxlifetime'),
    'session_save_path' => session_save_path() ?: sys_get_temp_dir(),
    'session_name' => session_name(),
    'current_session_id' => session_id()
];
$rolesTablesReady = roles_tables_available();
$permissionDefinitions = permission_definitions();
$rolesList = [];
$rolePermissionsMap = [];
$userRoleAssignments = [];
if ($rolesTablesReady) {
    try {
        $rolesList = $pdo->query('
            SELECT r.*, COUNT(DISTINCT ur.user_id) AS user_count
            FROM roles r
            LEFT JOIN user_roles ur ON ur.role_id = r.id
            GROUP BY r.id
            ORDER BY r.name ASC
        ')->fetchAll(PDO::FETCH_ASSOC);
        $permStmt = $pdo->query('SELECT role_id, LOWER(permission_key) AS permission_key FROM role_permissions');
        while ($row = $permStmt->fetch(PDO::FETCH_ASSOC)) {
            $rolePermissionsMap[(int)$row['role_id']][] = $row['permission_key'];
        }

        $userRoleAssignments = $pdo->query("
            SELECT u.id, u.email, u.first_name, u.last_name, u.username,
                   COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS display_name,
                   u.role AS legacy_role, r.name AS region_name,
                   GROUP_CONCAT(DISTINCT ro.slug ORDER BY ro.slug SEPARATOR ',') AS role_slugs
            FROM users u
            LEFT JOIN regions r ON u.region_id = r.id
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles ro ON ro.id = ur.role_id
            GROUP BY u.id
            ORDER BY u.email ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($userRoleAssignments as &$assignment) {
            $assignment['role_slugs'] = array_values(array_filter(array_map('trim', explode(',', $assignment['role_slugs'] ?? ''))));
        }
        unset($assignment);
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        Logger::error('roles_overview_load_failed', $e);
        $rolesList = [];
        $rolePermissionsMap = [];
        $userRoleAssignments = [];
        $rolesTablesReady = false;
    }
}

$app_settings = [];
try {
    $settings_stmt = $pdo->query("
        SELECT s.setting_key, s.setting_value, s.updated_at,
               COALESCE(NULLIF(u.username, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email) AS updated_by_display
        FROM app_settings s
        LEFT JOIN users u ON s.updated_by = u.id
    ");
    while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
        $app_settings[strtolower($row['setting_key'])] = $row;
    }
} catch (Throwable $e) {
    qt_rethrow_bad_input($e);
    $app_settings = [];
}

$current_max_order_quantity = get_max_order_quantity();
$canManageEmailTemplates = has_permission('email_templates.manage');
$emails = [];
if ($canManageEmailTemplates) {
    try {
        $emailStmt = $pdo->prepare('
            SELECT pe.*,
                   uc.email as created_by_email,
                   uu.email as updated_by_email
            FROM purchasing_emails pe
            LEFT JOIN users uc ON pe.created_by = uc.id
            LEFT JOIN users uu ON pe.updated_by = uu.id
            ORDER BY pe.sort_order ASC, pe.label ASC
        ');
        $emailStmt->execute();
        $emails = $emailStmt->fetchAll();
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        $emails = [];
    }
}

require __DIR__ . '/views/admin_dashboard_new.view.php';
