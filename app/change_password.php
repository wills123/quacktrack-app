<?php

// change_password.php
// Authenticated password change page for users

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/change_password_service.php';
require_login();

$pdo = db();
$user_id = $_SESSION['uid'];

// Get user info
// Check if column exists (backwards compatibility)
$column_exists = false;
try {
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_reset_password'");
    $column_exists = $check->rowCount() > 0;
} catch (Exception $e) {
    qt_rethrow_bad_input($e);
    Logger::info('password_reset_column_unavailable', 'Legacy users schema does not include password reset state.', [
        'error_type' => get_class($e),
    ]);
}

if ($column_exists) {
    $stmt = safe_execute_query('SELECT email, first_name, last_name, username, must_reset_password FROM users WHERE id = ?', [$user_id], 'get_user_password_info');
} else {
    $stmt = safe_execute_query('SELECT email, first_name, last_name, username FROM users WHERE id = ?', [$user_id], 'get_user_password_info');
}
$user = $stmt->fetch();
if (!$user) {
    destroy_session();
    redirect('/login.php');
    exit;
}

$is_forced_reset = $column_exists && isset($user['must_reset_password']) ? (bool)$user['must_reset_password'] : false;
$display_name = build_user_display_name($user['first_name'] ?? null, $user['last_name'] ?? null, $user['username'] ?? null, $user['email'] ?? null);
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $input = [
            'current_password' => req_raw_string($_POST, 'current_password'),
            'new_password' => req_raw_string($_POST, 'new_password'),
            'confirm_password' => req_raw_string($_POST, 'confirm_password'),
        ];
        if ($input['new_password'] !== $input['confirm_password']) {
            throw new BadRequestException('New passwords do not match');
        }
        $changed = change_password_save(db(), $user_id, $input, 'validate_password_strength', (int)$_SESSION['session_version']);
        $_SESSION['session_version'] = $changed['session_version'];
        Logger::security('password_changed', ['user_id' => $user_id, 'was_forced' => $changed['was_forced']]);
        flash('Your password has been changed successfully.', 'success');
        redirect('/');
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        $error = $e instanceof ClientError ? $e->getMessage() : 'Unable to change password. Please try again.';
        Logger::error('password_change_failed', new RuntimeException('Password change failed'), ['user_id' => $user_id, 'exception_type' => get_class($e)]);
    }
}

$page_title = $is_forced_reset ? 'Password Reset Required' : 'Change Password';
require VIEW_ROOT . '/change_password.view.php';
