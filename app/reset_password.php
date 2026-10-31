<?php

// Remove the bearer token before bootstrap or application logging reads request metadata.
$_SERVER['REQUEST_URI'] = explode('?', $_SERVER['REQUEST_URI'] ?? '/reset_password', 2)[0];
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/password_recovery.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('/');
}

$token = req_raw_string($_GET, 'token');
$user = null;
$token_valid = false;
try {
    $user = password_recovery_find(db(), $token);
    $token_valid = $user !== null;
    if (!$token_valid) {
        flash_message('Invalid or expired reset link. Please request a new one.', 'error');
        redirect('/forgot_password.php');
    }
} catch (Throwable $e) {
    qt_rethrow_bad_input($e);
    Logger::error('password_reset_token_validation', new RuntimeException('Reset lookup failed'), ['exception_type' => get_class($e)]);
    flash_message('An error occurred. Please try again later.', 'error');
    redirect('/forgot_password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    validate_csrf_token();
// Verify Turnstile token (only if enabled)
    if (is_turnstile_enabled()) {
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        $turnstileSecretKey = get_turnstile_secret_key();
        if (empty($turnstileToken)) {
            flash_message('Please complete the security verification.', 'error');
            require 'views/reset_password.view.php';
            exit;
        }

        // Verify Turnstile with Cloudflare
        $turnstile = turnstile_verify($turnstileSecretKey, $turnstileToken, $_SERVER['REMOTE_ADDR'] ?? '');
        if ($turnstile['transport_error'] !== null) {
            Logger::error('reset_password_turnstile_verification_failed', new Exception($turnstile['transport_error']), [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_id' => $user['user_id'] ?? null
            ]);
            flash_message('Security verification failed. Please try again.', 'error');
            require 'views/reset_password.view.php';
            exit;
        }

        if (!$turnstile['ok']) {
            Logger::security('reset_password_turnstile_verification_rejected', [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error_codes' => $turnstile['error_codes'],
                'user_id' => $user['user_id'] ?? null
            ]);
            flash_message('Security verification failed. Please try again.', 'error');
            require 'views/reset_password.view.php';
            exit;
        }
    }

    $password = req_raw_string($_POST, 'password');
    $confirm_password = req_raw_string($_POST, 'confirm_password');
// Validate passwords
    if (empty($password) || empty($confirm_password)) {
        flash_message('Please enter and confirm your new password.', 'error');
        require 'views/reset_password.view.php';
        exit;
    }

    $passwordError = password_policy_error($password);
    if ($passwordError !== null) {
        flash_message($passwordError . '.', 'error');
        require 'views/reset_password.view.php';
        exit;
    }

    if ($password !== $confirm_password) {
        flash_message('Passwords do not match.', 'error');
        require 'views/reset_password.view.php';
        exit;
    }

    try {
        password_recovery_complete(db(), $token, $password);
        Logger::security('password_reset_completed', [
            'user_id' => $user['user_id'],
            'email' => $user['email']
        ]);
        flash_message('Your password has been reset successfully. You can now log in with your new password.', 'success');
        redirect('/login.php');
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        Logger::error('password_reset_update', new RuntimeException('Reset update failed'), [
            'user_id' => $user['user_id'],
            'token_id' => $user['token_id']
        ]);
        flash_message($e instanceof ClientError ? $e->getMessage() : 'An error occurred while resetting your password. Please try again.', 'error');
    }
}

require 'views/reset_password.view.php';
