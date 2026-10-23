<?php

require_once 'config.php';
// If user is already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('/');
}

$token = req_raw_string($_GET, 'token');
$user = null;
$token_valid = false;
$token_hash = $token !== '' ? hash_password_reset_token($token) : '';
if (empty($token)) {
    flash_message('Invalid reset link.', 'error');
    redirect('/login.php');
}

try {
    $pdo = db();
// Validate token and get user
    $stmt = $pdo->prepare('
        SELECT prt.id as token_id, prt.user_id, prt.expires_at, prt.used_at, u.email
        FROM password_reset_tokens prt
        JOIN users u ON prt.user_id = u.id
        WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL
    ');
    $stmt->execute([$token_hash]);
    $token_data = $stmt->fetch();
    if (!$token_data) {
        $stmt = $pdo->prepare('
            SELECT prt.id as token_id, prt.user_id, prt.expires_at, prt.used_at, u.email
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL
        ');
        $stmt->execute([$token]);
        $token_data = $stmt->fetch();
    }

    if ($token_data) {
        $token_valid = true;
        $user = $token_data;
    } else {
    // Check if token exists but is expired/used
        $stmt = $pdo->prepare('SELECT id, expires_at, used_at FROM password_reset_tokens WHERE token = ?');
        $stmt->execute([$token_hash]);
        $expired_token = $stmt->fetch();
        if (!$expired_token) {
            $stmt = $pdo->prepare('SELECT id, expires_at, used_at FROM password_reset_tokens WHERE token = ?');
            $stmt->execute([$token]);
            $expired_token = $stmt->fetch();
        }

        if ($expired_token) {
            if ($expired_token['used_at']) {
                flash_message('This reset link has already been used.', 'error');
            } else {
                flash_message('This reset link has expired. Please request a new one.', 'error');
            }
        } else {
            flash_message('Invalid reset link.', 'error');
        }

        Logger::security('password_reset_invalid_token', [
            'token_hash' => $token_hash !== '' ? $token_hash : null,
            'expired_token_found' => !empty($expired_token)
        ]);
        redirect('/forgot_password.php');
    }
} catch (Exception $e) {
    qt_rethrow_bad_input($e);
    Logger::error('password_reset_token_validation', $e, ['token' => $token]);
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

    if (strlen($password) < 8) {
        flash_message('Password must be at least 8 characters long.', 'error');
        require 'views/reset_password.view.php';
        exit;
    }

    if ($password !== $confirm_password) {
        flash_message('Passwords do not match.', 'error');
        require 'views/reset_password.view.php';
        exit;
    }

    try {
        $pdo->beginTransaction();
// Update user password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$password_hash, $user['user_id']]);
// Mark token as used
        $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?');
        $stmt->execute([$user['token_id']]);
// Invalidate all other reset tokens for this user
        $stmt = $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL AND id != ?');
        $stmt->execute([$user['user_id'], $user['token_id']]);
        $pdo->commit();
        Logger::security('password_reset_completed', [
            'user_id' => $user['user_id'],
            'email' => $user['email']
        ]);
        flash_message('Your password has been reset successfully. You can now log in with your new password.', 'success');
        redirect('/login.php');
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        $pdo->rollBack();
        Logger::error('password_reset_update', $e, [
            'user_id' => $user['user_id'],
            'token_id' => $user['token_id']
        ]);
        flash_message('An error occurred while resetting your password. Please try again.', 'error');
    }
}

require 'views/reset_password.view.php';
