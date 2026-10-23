<?php

declare(strict_types=1);

/** @param array<string, bool|int|string> $options */
function start_session_secure(array $options = []): void
{
    if (!session_start($options)) {
        throw new RuntimeException('Unable to start session');
    }
}

function commit_session_secure(): void
{
    if (!session_write_close()) {
        throw new RuntimeException('Unable to save session');
    }
}

// Accept-* headers vary by request/proxy. IP addresses also change on mobile networks.
function generate_session_fingerprint(): string
{
    return hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

function validate_session_fingerprint(): bool
{
    // Sessions created while validation was disabled used a different fingerprint format.
    if (($_SESSION['fingerprint_version'] ?? null) !== 2) {
        $_SESSION['fingerprint'] = generate_session_fingerprint();
        $_SESSION['fingerprint_version'] = 2;
        return true;
    }

    return is_string($_SESSION['fingerprint'] ?? null)
        && hash_equals($_SESSION['fingerprint'], generate_session_fingerprint());
}

// Resolve rotation aliases before any controller reads authentication or CSRF state.
function resume_rotated_session(): void
{
    if (!isset($_SESSION['session_rotation_id'])) {
        return;
    }

    $successor_id = $_SESSION['session_rotation_id'];
    $expires_at = $_SESSION['session_rotation_expires'] ?? 0;
    $valid_alias = is_string($successor_id)
        && time() < $expires_at
        && validate_session_fingerprint();
    session_abort();

    if ($valid_alias && resume_session_successor($successor_id, $expires_at)) {
        return;
    }

    // Expired aliases have no authenticated state and must not damage the successor.
    session_id('');
    start_session_secure();
}

function resume_session_successor(string $successor_id, int $expires_at): bool
{
    // The rotating request may still be creating the successor. Never copy authentication
    // from the old ID: that would resurrect a successor that has since logged out.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        session_id($successor_id);
        start_session_secure();
        if (time() < $expires_at && session_id() === $successor_id && isset($_SESSION['uid'])) {
            return true;
        }
        session_abort();
        if (time() >= $expires_at) {
            break;
        }
        usleep(100000);
    }
    return false;
}

// Login, SSO and registration: the pre-login ID is discarded at once (fixation protection).
function regenerate_session_secure(): void
{
    require_session_before_output();
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to regenerate session ID');
    }
    finish_session_rotation();
}

// Periodic renewal: the old ID stays briefly as an alias so overlapping requests keep working.
function renew_session_secure(): void
{
    require_session_before_output();
    renew_session_with_alias();
    finish_session_rotation();
}

function require_session_before_output(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        throw new RuntimeException('Session renewal requires an active session before output');
    }
}

function finish_session_rotation(): void
{
    $_SESSION['fingerprint'] = generate_session_fingerprint();
    $_SESSION['fingerprint_version'] = 2;
    $_SESSION['last_regeneration'] = time();

    if (!class_exists('Logger', false)) {
        return;
    }
    try {
        // Session IDs are credentials; do not include them in renewal logs.
        Logger::info('session_regenerated', 'Session ID regenerated for security', [
            'user_id' => $_SESSION['uid'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Logger error in session rotation: ' . $e->getMessage());
    }
}

function renew_session_with_alias(): void
{
    $session_data = $_SESSION;
    $successor_id = session_create_id();
    if ($successor_id === false) {
        throw new RuntimeException('Unable to create session ID');
    }

    // Persist the alias while holding the old session's lock. Waiting requests see
    // no independent authenticated copy, and all resume the same successor.
    $_SESSION = [
        'session_rotation_id' => $successor_id,
        'session_rotation_expires' => time() + 60,
        'fingerprint' => generate_session_fingerprint(),
        'fingerprint_version' => 2,
    ];
    commit_session_secure();

    // This server-generated ID does not exist yet. Restore strict mode before
    // returning to application code; no client-supplied ID uses this exception.
    $strict_mode = ini_get('session.use_strict_mode');
    ini_set('session.use_strict_mode', '0');
    try {
        session_id($successor_id);
        start_session_secure();
        $_SESSION = $session_data;
        $_SESSION['last_regeneration'] = time();
        commit_session_secure();
    } finally {
        // A failed write must also release any active session before changing ini settings.
        session_abort();
        ini_set('session.use_strict_mode', $strict_mode === false ? '1' : $strict_mode);
    }
    start_session_secure();
}

/** @param array<string, mixed> $details */
function log_session_security_event(string $event, array $details): void
{
    if (!class_exists('Logger', false)) {
        return;
    }
    try {
        Logger::security($event, $details);
    } catch (Throwable $e) {
        error_log('Logger error in session validation: ' . $e->getMessage());
    }
}
