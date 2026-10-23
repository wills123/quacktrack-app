<?php

// change_password.php
// Authenticated password change page for users

require_once 'config.php';
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
    redirect('/logout.php');
    exit;
}

$is_forced_reset = $column_exists && isset($user['must_reset_password']) ? (bool)$user['must_reset_password'] : false;
$display_name = build_user_display_name($user['first_name'] ?? null, $user['last_name'] ?? null, $user['username'] ?? null, $user['email'] ?? null);
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $current_password = req_raw_string($_POST, 'current_password');
    $new_password = req_raw_string($_POST, 'new_password');
    $confirm_password = req_raw_string($_POST, 'confirm_password');

    try {
        // Validate current password
        if (empty($current_password)) {
            throw new Exception('Current password is required');
        }

        // Fetch current password hash
        $stmt = safe_execute_query(
            'SELECT password_hash FROM users WHERE id = ?',
            [$user_id],
            'verify_current_password'
        );
        $user_data = $stmt->fetch();

        if (!$user_data || !password_verify($current_password, $user_data['password_hash'])) {
            Logger::security('password_change_invalid_current', [
                'user_id' => $user_id,
                'email' => $user['email']
            ]);
            throw new Exception('Current password is incorrect');
        }

        // Validate new password
        if (empty($new_password)) {
            throw new Exception('New password is required');
        }

        if ($new_password !== $confirm_password) {
            throw new Exception('New passwords do not match');
        }

        // Check if new password is same as current
        if ($current_password === $new_password) {
            throw new Exception('New password must be different from current password');
        }

        // Validate password strength
        // Allow legacy 8-char minimum for users with existing short passwords (grandfathering)
        // New users and users upgrading must use 12+ characters
        $allow_legacy = strlen($current_password) < 12; // If current is short, allow short new password
        $strength_check = validate_password_strength($new_password, $allow_legacy);

        if (!$strength_check['valid']) {
            throw new Exception(implode('; ', $strength_check['errors']));
        }

        // Display warnings to user even if valid (via session flash after success)
        if (!empty($strength_check['warnings'])) {
            $_SESSION['password_warnings'] = $strength_check['warnings'];
        }

        // Hash new password
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

        // Update password in database
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            UPDATE users
            SET password_hash = ?,
                must_reset_password = 0,
                password_changed_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([$new_hash, $user_id]);

        $pdo->commit();

        Logger::security('password_changed', [
            'user_id' => $user_id,
            'email' => $user['email'],
            'was_forced' => $is_forced_reset
        ]);

        flash('Your password has been changed successfully.', 'success');

        // Redirect to dashboard after successful password change
        redirect('/');
        exit;
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
        Logger::error('password_change_failed', $e, [
            'user_id' => $user_id
        ]);
    }
}

$page_title = $is_forced_reset ? 'Password Reset Required' : 'Change Password';
require VIEW_ROOT . '/change_password.view.php';
