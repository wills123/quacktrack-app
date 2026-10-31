<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/password_recovery.php';
require_once __DIR__ . '/includes/after_response.php';
// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_token();
// Verify Turnstile token (only if enabled)
    if (is_turnstile_enabled()) {
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        $turnstileSecretKey = get_turnstile_secret_key();
        if (empty($turnstileToken)) {
            flash_message('Please complete the security verification.', 'error');
            redirect('/forgot_password.php');
        }

        // Verify Turnstile with Cloudflare
        $turnstile = turnstile_verify($turnstileSecretKey, $turnstileToken, $_SERVER['REMOTE_ADDR'] ?? '');
        if ($turnstile['transport_error'] !== null) {
            Logger::error('forgot_password_turnstile_verification_failed', new Exception($turnstile['transport_error']), [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            flash_message('Security verification failed. Please try again.', 'error');
            redirect('/forgot_password.php');
        }

        if (!$turnstile['ok']) {
            Logger::security('forgot_password_turnstile_verification_rejected', [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error_codes' => $turnstile['error_codes']
            ]);
            flash_message('Security verification failed. Please try again.', 'error');
            redirect('/forgot_password.php');
        }
    }

    $email = req_string($_POST, 'email');
    if (empty($email)) {
        flash_message('Please enter your email address.', 'error');
        redirect('/forgot_password.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_message('Please enter a valid email address.', 'error');
        redirect('/forgot_password.php');
    }

    try {
        $issued = auth_request_allowed(db(), 'recovery', $email) ? password_recovery_issue(db(), $email) : null;
        if ($issued !== null) {
            $resetLink = build_password_reset_link($issued['token']);
            run_after_response(static function () use ($issued, $resetLink): void {
                if (!send_password_reset_email($issued['email'], $resetLink)) {
                    Logger::security('password_reset_email_failed', []);
                }
            });
        }
        // A mail failure must never expose a usable credential, including in development.
        flash_message('If an account with that email exists, a password reset link has been sent.', 'success');

        redirect('/forgot_password.php');
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        Logger::error('password_reset_request', $e, ['email' => $email]);
        flash_message('An error occurred. Please try again later.', 'error');
        redirect('/forgot_password.php');
    }
}

require 'views/forgot_password.view.php';
