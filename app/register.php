<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/after_response.php';

/** The one answer to every registration attempt, whether or not the account was created (audit H3). */
const REGISTRATION_ANSWER = 'Thanks. If those details can be used, your account is ready: sign in with your email and password. '
    . 'If you already have an account, sign in or reset your password.';

if (!public_registration_enabled()) {
    http_response_code(404);
    exit;
}

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

    // Every attempt gets the same answer, redirect and timing whether or not the email or username is already taken
    // (audit H3): the password is always hashed, nobody is signed in, and the owner of an existing email is told by
    // email (after the response, at most once a day) instead of the visitor.
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
        $stmt->execute([$email, $username]);
        $taken = $stmt->fetch() !== false;
        if (!$taken) {
            $pdo->prepare('INSERT INTO users (email, first_name, last_name, username, password_hash, region_id, role) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$email, $first_name, $last_name, $username, $hash, $region_id, $role]);
            log_security_event('user_registered', [
                'user_id' => (int)$pdo->lastInsertId(),
                'region_id' => $region_id
            ]);
        }
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        // A concurrent registration of the same email or username is answered like any other taken one.
        if (!($e instanceof PDOException && $e->getCode() === '23000')) {
            log_error('user_registration', $e, ['region_id' => $region_id]);
            handle_form_error('Registration failed. Please try again later.', '/register.php');
        }
        $taken = true;
    }
    if ($taken) {
        log_security_event('user_registration_refused_taken', ['region_id' => $region_id]);
        $owner = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $owner->execute([$email]);
        $ownerId = (int)$owner->fetchColumn();
        // Only the owner of an already registered email hears about it, at most once a day, so the notice can neither
        // reveal a taken username to the visitor nor be used to flood an inbox. A failed limit check sends nothing.
        $notice = $ownerId > 0 ? check_rate_limit('registration_notice', $ownerId, 1, 86400) : null;
        if ($notice !== null && $notice['allowed'] && $notice['reset_at'] !== null) {
            run_after_response(static fn () => send_account_notice_email(
                $email,
                'Someone tried to register with your Quack Track email',
                "Someone tried to create a Quack Track account with this email address, which already has an account.\n\n"
                . "If it was you, sign in, or use \"Forgot password\" on the sign-in page. If not, you can ignore this email."
            ));
        }
    }
    handle_form_success(REGISTRATION_ANSWER, '/login.php');
}

require __DIR__ . '/views/register.view.php';
