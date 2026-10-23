<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/snipe_oauth.php';

// Validate before redirecting, otherwise an invalid session loops between login and home.
if (isset($_SESSION['uid'])) {
    if (validate_session()) {
        redirect('/');
    }
    destroy_session();
    session_id('');
    start_session_secure();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Verify Turnstile token (only if enabled)
    if (is_turnstile_enabled()) {
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        $turnstileSecretKey = get_turnstile_secret_key();

        if (empty($turnstileToken)) {
            handle_form_error('Please complete the security verification.');
            require __DIR__ . '/views/login.view.php';
            exit;
        }

        // Verify Turnstile with Cloudflare
        $turnstile = turnstile_verify($turnstileSecretKey, $turnstileToken, $_SERVER['REMOTE_ADDR'] ?? '');

        if ($turnstile['transport_error'] !== null) {
            log_error('login_turnstile_verification_failed', new Exception($turnstile['transport_error']), [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            handle_form_error('Security verification failed. Please try again.');
            require __DIR__ . '/views/login.view.php';
            exit;
        }

        if (!$turnstile['ok']) {
            log_security_event('login_turnstile_verification_rejected', [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error_codes' => $turnstile['error_codes']
            ]);
            handle_form_error('Security verification failed. Please try again.');
            require __DIR__ . '/views/login.view.php';
            exit;
        }
    }

    $email = req_string($_POST, 'email');
    $password = req_raw_string($_POST, 'password');

    // Validate input
    if (empty($email) || empty($password)) {
        handle_form_error('Please enter both email and password.');
        require __DIR__ . '/views/login.view.php';
        exit;
    }

    // Use secure login process
    $loginResult = secure_login($email, $password);

    if ($loginResult['success']) {
        // Check if user must reset password
        if (!empty($loginResult['must_reset_password'])) {
            flash('You must change your password before continuing.', 'info');
            redirect('/change_password.php');
            exit;
        }

        handle_form_success('Welcome back! You have been logged in successfully.', '/');
    } else {
        if ($loginResult['locked_out'] ?? false) {
            // Show lockout message
            handle_form_error($loginResult['message']);
        } else {
            // Show generic error message
            handle_form_error($loginResult['message']);
        }
    }
}

require __DIR__ . '/views/login.view.php';
