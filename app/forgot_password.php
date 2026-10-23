<?php

require_once 'config.php';
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
        $pdo = db();
// Check if user exists
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
        // Generate secure reset token
            $token = bin2hex(random_bytes(32));
            $token_hash = hash_password_reset_token($token);
            $expires_at = date('Y-m-d H:i:s', time() + 3600);
        // 1 hour expiry

            // Store reset token
            $stmt = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$user['id'], $token_hash, $expires_at]);
        // Create reset link
            $reset_link = build_password_reset_link($token);
        // Log the reset request for security
            Logger::security('password_reset_requested', [
                'user_id' => $user['id'],
                'email' => $email,
                'token_expires' => $expires_at
            ]);
        // Send password reset email
                    $email_sent = send_password_reset_email($user['email'], $reset_link);
            if ($email_sent) {
                flash_message('If an account with that email exists, a password reset link has been sent.', 'success');
            } else {
        // In development, show the link if email fails
                if (env('APP_ENV') === 'dev') {
                    flash_message("Email failed to send. Reset link (DEV MODE): <a href=\"{$reset_link}\" target=\"_blank\">{$reset_link}</a>", 'info');
                } else {
                    flash_message('If an account with that email exists, a password reset link has been sent.', 'success');
                }
            }
        } else {
        // Don't reveal whether the email exists or not
            flash_message('If an account with that email exists, a password reset link has been sent.', 'success');
        // Log failed reset attempt
            Logger::security('password_reset_invalid_email', [
                'email' => $email
            ]);
        }

        redirect('/forgot_password.php');
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        Logger::error('password_reset_request', $e, ['email' => $email]);
        flash_message('An error occurred. Please try again later.', 'error');
        redirect('/forgot_password.php');
    }
}

require 'views/forgot_password.view.php';
