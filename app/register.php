<?php

require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('/index.php');
}

csrf_verify();
$pdo = db();

// Fetch all regions that are currently accepting registrations
$available_regions = $pdo->query('SELECT id, name FROM regions WHERE allow_registration = 1 ORDER BY name ASC')->fetchAll(); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read by the required views/register.view.php dropdown.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify Turnstile token (only if enabled)
    if (is_turnstile_enabled()) {
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        $turnstileSecretKey = get_turnstile_secret_key();

        if (empty($turnstileToken)) {
            flash_message('Please complete the security verification', 'error');
            redirect('/register.php');
        }

        // Verify Turnstile with Cloudflare
        $turnstile = turnstile_verify($turnstileSecretKey, $turnstileToken, $_SERVER['REMOTE_ADDR'] ?? '');

        if ($turnstile['transport_error'] !== null) {
            log_error('turnstile_verification_failed', new Exception($turnstile['transport_error']), [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            flash_message('Security verification failed. Please try again.', 'error');
            redirect('/register.php');
        }

        if (!$turnstile['ok']) {
            log_security_event('turnstile_verification_rejected', [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'error_codes' => $turnstile['error_codes']
            ]);
            flash_message('Security verification failed. Please try again.', 'error');
            redirect('/register.php');
        }
    }

    // Validate form data
    $validation = validate_user_registration($_POST);

    if ($validation->hasErrors()) {
        $errors = $validation->getErrors();
        $errorMessage = 'Registration failed: ' . implode(', ', $errors);
        flash_message($errorMessage, 'error');
        redirect('/register.php');
    }

    $sanitizedData = $validation->getSanitizedData();
    $first_name = $sanitizedData['first_name'];
    $last_name = $sanitizedData['last_name'];
    $username = $sanitizedData['username'];
    $email = $sanitizedData['email'];
    $password = $sanitizedData['password'];
    $region_id = $sanitizedData['region_id'];
    $role = 'user'; // Set default role for new users

    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            flash_message('An account with that email already exists', 'error');
            redirect('/register.php');
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            flash_message('That username is already taken', 'error');
            redirect('/register.php');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (email, first_name, last_name, username, password_hash, region_id, role) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$email, $first_name, $last_name, $username, $hash, $region_id, $role]);
        regenerate_session_secure(); // Prevent fixation and initialize session security metadata
        $new_user_id = (int)$pdo->lastInsertId();
        record_session_version($new_user_id);
        $_SESSION['uid'] = $new_user_id;
        $_SESSION['email'] = $email;
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['username'] = $username;
        $_SESSION['display_name'] = build_user_display_name($first_name, $last_name, $username, $email);
        $_SESSION['region_id'] = $region_id;

        // Fetch region name for session display
        $stmt = $pdo->prepare('SELECT name FROM regions WHERE id = ?');
        $stmt->execute([$region_id]);
        $_SESSION['region'] = $stmt->fetchColumn();

        $_SESSION['role'] = $role;
        $_SESSION['last_activity'] = time(); // Set initial activity time

        // Log successful registration
        log_security_event('user_registered', [
            'user_id' => $_SESSION['uid'],
            'email' => $email,
            'region_id' => $region_id
        ]);

        handle_form_success('Account created successfully! Welcome to Quack Track.', '/index.php');
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        log_error('user_registration', $e, [
            'email' => $email,
            'region_id' => $region_id
        ]);

        handle_form_error('Registration failed: ' . $e->getMessage(), '/register.php');
    }
}

require __DIR__ . '/views/register.view.php';
