<?php

// config.php
// Loads environment, starts secure session, and provides PDO connection.

declare(strict_types=1);

require_once __DIR__ . '/includes/session_security.php';
require_once __DIR__ . '/includes/session_revocation.php';
require_once __DIR__ . '/includes/auth_policy.php';
require_once __DIR__ . '/includes/log_privacy.php';
require_once __DIR__ . '/includes/auth_rate_limit.php';

// CRITICAL: Configure sessions BEFORE include guard check
// This must run on EVERY request, even if config.php returns early
//
// Some endpoints (e.g. stateless API-key integrations) must not create session state/cookies.
// Set `define('QUACKTRACK_NO_SESSION', true);` before requiring config.php to skip session start.
if (!defined('QUACKTRACK_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    // Set session save path to writable directory
    $sessionPath = '/var/www/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0755, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        ini_set('session.save_path', $sessionPath);
    }

    $cookieSecureFlag = getenv('SESSION_COOKIE_SECURE');
    if ($cookieSecureFlag === false || $cookieSecureFlag === '') {
        $production = in_array(getenv('APP_ENV') ?: 'prod', ['prod', 'production'], true);
        $cookieSecureFlag = ($production || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? '1' : '0';
    }
    $cookieSecure = ($cookieSecureFlag === '1');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('psess');
    start_session_secure([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $cookieSecure,
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
    resume_rotated_session();
}

// Prevent multiple includes - CRITICAL for avoiding "Cannot redeclare" errors
if (defined('QUACKTRACK_CONFIG_LOADED')) {
    return;
}
define('QUACKTRACK_CONFIG_LOADED', true);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/includes/OutboundHttp.php';
require_once __DIR__ . '/includes/turnstile.php';
require_once __DIR__ . '/includes/resend_mail.php';
require_once __DIR__ . '/includes/api_errors.php';
require_once __DIR__ . '/includes/request.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/input_schema.php';

// Load environment variables from .env file if it exists
if (is_readable(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!getenv($name)) { // Don't override existing env vars
                putenv("$name=$value");
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): string
    {
        $val = getenv($key);
        return ($val === false || $val === '') ? ($default ?? '') : $val;
    }
}

/** Whether this is a production deployment: APP_ENV `prod` or `production`, and an unset APP_ENV counts as production. */
function app_is_production(): bool
{
    return in_array(env('APP_ENV', 'prod'), ['prod', 'production'], true);
}

/** Development-only tools run only when APP_ENV is explicitly `dev`. */
function app_is_development(): bool
{
    return env('APP_ENV', 'prod') === 'dev';
}

function require_app_base_url(): string
{
    $base = trim(env('APP_URL'));
    if ($base !== '') {
        return rtrim($base, '/');
    }
    if (app_is_production()) {
        throw new RuntimeException('APP_URL must be set in production.');
    }
    $host = $_SERVER['SERVER_NAME'] ?? '';
    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function build_password_reset_link(string $token): string
{
    $base = require_app_base_url();
    OutboundHttp::assertSecureUrl($base);
    return $base . '/reset_password.php?token=' . $token;
}

function hash_password_reset_token(string $token): string
{
    return hash('sha256', $token);
}

$APP_ENV = env('APP_ENV', 'prod');
$APP_SECRET = env('APP_SECRET');

$DB_HOST = env('DB_HOST', 'db'); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read through global declarations in db_handle() and run_migrations.php.
$DB_NAME = env('DB_NAME', 'parts_shop'); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read through global declarations in db_handle() and run_migrations.php.
$DB_USER = env('DB_USER', 'parts_user'); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read through global declarations in db_handle() and run_migrations.php.
$DB_PASSWORD = env('DB_PASSWORD', 'password'); // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Read through global declarations in db_handle() and run_migrations.php.

/**
 * PDO options that secure the connection to MySQL.
 *
 * With DB_SSL_CA set, the connection is encrypted and the server's certificate (chain and
 * host name) must verify against that CA, so a machine on the network cannot pose as the
 * database or read the traffic. Without it the connection would be plain text, which is
 * allowed only for APP_ENV=dev or an explicit DB_ALLOW_PLAINTEXT=1.
 *
 * @return array<int, mixed>
 * @throws RuntimeException when TLS is required but not usable
 */
function db_tls_options(): array
{
    $ca = (string)env('DB_SSL_CA', '');
    if ($ca === '') {
        if (env('APP_ENV', 'prod') === 'dev' || env('DB_ALLOW_PLAINTEXT', '0') === '1') {
            return [];
        }
        throw new RuntimeException('DB_SSL_CA is not set: refusing an unencrypted database connection (set DB_ALLOW_PLAINTEXT=1 to override).');
    }
    if (!is_readable($ca)) {
        throw new RuntimeException('DB_SSL_CA points to a file that cannot be read: ' . $ca);
    }

    return [
        PDO::MYSQL_ATTR_SSL_CA => $ca,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ];
}

/**
 * The shared PDO connection. With $connect = false it only returns an already-open
 * connection (null when this request never touched the database).
 */
function db_handle(bool $connect = true): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO || !$connect) {
        return $pdo;
    }
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);
    $pdo = new PDO($dsn, $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ] + db_tls_options());
    return $pdo;
}

function db(): PDO
{
    return db_handle();
}

/** Roll back a transaction left open by a request that is being aborted (never connects). */
function db_rollback_if_open(): void
{
    $pdo = db_handle(false);
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function is_logged_in(): bool
{
    return isset($_SESSION['uid']);
}
function require_login(): void
{
    if (!isset($_SESSION['uid'])) {
        // Check if this is an API/AJAX request
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                 !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required',
                'code' => 401,
                'redirect' => '/login.php'
            ]);
            exit;
        }

        // Don't destroy session for users who aren't logged in yet
        redirect('/login.php');
    }

    // Only validate session for logged-in users
    if (!validate_session()) {
        // Check if this is an API/AJAX request
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                 !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            destroy_session();
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Session expired',
                'code' => 401,
                'redirect' => '/login.php'
            ]);
            exit;
        }

        destroy_session();
        redirect('/login.php');
    }

    // Enforce password reset if required
    enforce_password_reset();
}

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Whether $url is an absolute http or https URL with a host, so it is safe as a link (never javascript: or data:). */
function is_http_url(string $url): bool
{
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && in_array($scheme, ['http', 'https'], true)
        && (string)parse_url($url, PHP_URL_HOST) !== '';
}

/**
 * Normalize image path to ensure it has the /uploads/ prefix.
 * Handles legacy data that may have just the filename.
 *
 * @param string|null $path The image path from database
 * @return string The normalized path with /uploads/ prefix, or empty string
 */
/**
 * Add a cache-busting ?v=<mtime> to a local /assets/ URL.
 *
 * Static assets are served with a one-year immutable Cache-Control, so a changed
 * file under an unchanged URL is never refetched by browsers or Cloudflare. A
 * version in the URL makes every changed file a new URL.
 */
function asset_href(string $href): string
{
    if (!str_starts_with($href, '/assets/') || str_contains($href, '?')) {
        return $href;
    }
    $file = dirname(__DIR__) . $href;
    return is_file($file) ? $href . '?v=' . (int)filemtime($file) : $href;
}

function image_url(?string $path): string
{
    if (empty($path)) {
        return '';
    }
    // Already has correct prefix
    if (str_starts_with($path, '/uploads/')) {
        return $path;
    }
    // Already has a leading slash (some other path)
    if (str_starts_with($path, '/')) {
        return $path;
    }
    // Just a filename - prepend /uploads/
    return '/uploads/' . $path;
}

/**
 * CSRF Protection System
 *
 * This application implements comprehensive CSRF (Cross-Site Request Forgery) protection:
 *
 * 1. Token Generation: Each session gets a unique 64-character CSRF token
 * 2. Token Validation: All POST requests are validated server-side
 * 3. Form Integration: csrf_field() helper adds hidden token field to forms
 * 4. JavaScript Enhancement: csrf-fix.js ensures tokens are preserved during submission
 * 5. Error Handling: Clear user messages and security logging for failures
 *
 * Usage:
 * - In forms: <?= csrf_field() ?>
 * - In controllers: csrf_verify() at the start of POST handlers
 * - For API endpoints: csrf_verify_api() returns boolean for custom handling
 */

// CSRF utilities
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['csrf'] ?? '';
        $session_csrf = $_SESSION['csrf'] ?? '';

        if (env('APP_ENV', 'dev') === 'dev') {
            error_log(sprintf(
                'CSRF DEBUG: sent=%s, session=%s, uid=%s, uri=%s',
                substr($sent, 0, 10) . '...',
                substr($session_csrf, 0, 10) . '...',
                $_SESSION['uid'] ?? 'none',
                $_SERVER['REQUEST_URI'] ?? 'unknown'
            ));
        }

        // If no session CSRF token exists, generate one (shouldn't happen in normal flow)
        if (empty($session_csrf)) {
            if (class_exists('Logger', false)) {
                try {
                    Logger::security('csrf_token_missing', [
                        'user_id' => $_SESSION['uid'] ?? null,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
                    ]);
                } catch (Throwable $e) {
                    error_log('Logger error in csrf_verify: ' . $e->getMessage());
                }
            }
            $session_csrf = csrf_token(); // This will generate and store a new token
        }

        if (!hash_equals($session_csrf, $sent)) {
            if (class_exists('Logger', false)) {
                try {
                    Logger::security('csrf_token_mismatch', [
                        'user_id' => $_SESSION['uid'] ?? null,
                        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                        'sent_token_length' => strlen($sent),
                        'session_token_length' => strlen($session_csrf)
                    ]);
                } catch (Throwable $e) {
                    error_log('Logger error in csrf_verify: ' . $e->getMessage());
                }
            }

            // Check if this is an API request
            $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                     !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

            if ($isApi) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            } else {
                http_response_code(400);
                echo 'Invalid CSRF token';
            }
            exit;
        }
    }
}

function flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}
function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// Additional security helper functions
function validate_csrf_token(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = $_POST['csrf'] ?? '';
        $session_csrf = $_SESSION['csrf'] ?? '';

        // If no session CSRF token exists, generate one
        if (empty($session_csrf)) {
            Logger::security('csrf_token_missing_validate', [
                'user_id' => $_SESSION['uid'] ?? null,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
            $session_csrf = csrf_token();
        }

        if (!hash_equals($session_csrf, $sent)) {
            Logger::security('csrf_validation_failed', [
                'user_id' => $_SESSION['uid'] ?? null,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            http_response_code(400);
            echo 'Invalid CSRF token';
            exit;
        }
    }
}

function is_admin(): bool
{
    return has_permission('admin.access');
}

function legacy_permission_map(): array
{
    return [
        'admin' => [
            'admin.access',
            'admin.users.view',
            'admin.users.manage',
            'admin.logs.view',
            'admin.logs.clear',
            'admin.files.manage',
            'admin.sessions.manage',
            'products.create',
            'products.edit',
            'products.delete',
            'stock.adjust',
            'stock.transfer',
            'orders.view_all',
            'orders.manage',
            'settings.manage',
            'reports.view',
            'deployment.view',
            'deployment.manage',
            'deployment.tasks.customize',
            'deployment.reports.export',
            'assets.view',
            'assets.checkout',
            'assets.checkin',
            'assets.manage',
            'assets.licenses',
            'assets.maintenance',
            'assets.categories',
            'assets.reports',
            'assets.admin',
            'kit.view',
            'kit.manage',
            'kit.shipments.view',
            'kit.shipments.create',
            'kit.shipments.manage',
        ],
        'manager' => [
            'products.create',
            'products.edit',
            'stock.adjust',
            'stock.transfer',
            'orders.view_region',
            'reports.view',
            'deployment.view'
        ],
        'user' => [
            'products.view',
            'stock.view_own',
            'stock.adjust',
            'orders.create',
            'orders.view_own',
            'cart.manage'
        ],
        'service_desk' => [
            'products.view',
            'stock.view_own',
            'stock.adjust',
            'orders.create',
            'orders.view_own',
            'cart.manage',
            'assets.view',
            'assets.checkout',
            'assets.checkin',
            'assets.manage',
            'assets.licenses',
            'assets.maintenance',
            'assets.categories',
            'assets.reports',
            'kit.view',
            'kit.manage',
            'kit.shipments.view',
            'kit.shipments.create',
            'kit.shipments.manage',
        ]
    ];
}

function merge_legacy_permissions(array $permissions): array
{
    $role = strtolower($_SESSION['role'] ?? '');
    if ($role === '') {
        return $permissions;
    }

    $legacyMap = legacy_permission_map();
    if (!isset($legacyMap[$role])) {
        return $permissions;
    }

    return array_values(array_unique(array_merge($permissions, $legacyMap[$role])));
}

function require_admin(): void
{
    if (!has_permission('admin.access')) {
        Logger::security('unauthorized_admin_access', [
            'attempted_resource' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'user_role' => $_SESSION['role'] ?? 'unknown',
            'user_id' => $_SESSION['uid'] ?? null
        ]);

        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                 !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            api_error('Access denied. Administrator privileges required.', 403);
        } else {
            flash_message('Access denied. Administrator privileges required.', 'error');
            redirect('/');
        }
    }
}

function permission_list_has(array $permissions, string $permission): bool
{
    if (in_array('system.full_access', $permissions, true)) {
        return true;
    }

    if (in_array('admin.access', $permissions, true)) {
        return true;
    }

    return in_array($permission, $permissions, true);
}

function has_permission(string $permission): bool
{
    static $permissionCacheRefreshed = false;

    $permission = strtolower(trim($permission));
    if ($permission === '') {
        return false;
    }

    $userId = $_SESSION['uid'] ?? null;
    if (!$userId) {
        return false;
    }

    $permissions = current_user_permissions();
    if ($permissions !== null) {
        if (permission_list_has($permissions, $permission)) {
            return true;
        }

        if (!$permissionCacheRefreshed) {
            $permissionCacheRefreshed = true;
            reset_user_permission_cache();
            $permissions = current_user_permissions();
            if ($permissions !== null && permission_list_has($permissions, $permission)) {
                return true;
            }
        }
        return false;
    }

    $legacyMap = legacy_permission_map();
    $role = strtolower($_SESSION['role'] ?? 'user');
    return in_array($permission, $legacyMap[$role] ?? [], true);
}

function require_permission(string $permission): void
{
    if (!has_permission($permission)) {
        Logger::security('unauthorized_permission_access', [
            'attempted_permission' => $permission,
            'user_role' => $_SESSION['role'] ?? 'unknown',
            'user_id' => $_SESSION['uid'] ?? null,
            'resource' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);

        // Check if this is an API request
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                 !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            api_error('Access denied. Insufficient permissions.', 403);
        } else {
            flash_message('Access denied. Insufficient permissions.', 'error');
            redirect('/');
        }
    }
}

function check_resource_ownership(int $resourceUserId): bool
{
    // Users can access their own resources, admins can access all
    return is_admin() || ($_SESSION['uid'] ?? 0) === $resourceUserId;
}

function require_resource_ownership(int $resourceUserId, string $resourceType = 'resource'): void
{
    if (!check_resource_ownership($resourceUserId)) {
        Logger::security('unauthorized_resource_access', [
            'resource_type' => $resourceType,
            'resource_owner' => $resourceUserId,
            'accessing_user' => $_SESSION['uid'] ?? null,
            'user_role' => $_SESSION['role'] ?? 'unknown'
        ]);

        // Check if this is an API request
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                 !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            api_error('Access denied. You can only access your own resources.', 403);
        } else {
            flash_message('Access denied. You can only access your own resources.', 'error');
            redirect('/');
        }
    }
}

function flash_message(string $message, string $type = 'info'): void
{
    flash($message, $type);
}

function redirect(string $url): void
{
    // Simple redirect without URL manipulation to prevent loops
    header('Location: ' . $url);
    exit;
}

// Enhanced session security validation
function validate_session(): bool
{
    // Check if session is valid and not expired
    if (!isset($_SESSION['uid'])) {
        error_log('SESSION DEBUG: No uid in session');
        return false;
    }

    if (!session_auth_method_allowed((string)($_SESSION['login_method'] ?? 'local'))) {
        return false;
    }

    if (!session_version_is_current((int)$_SESSION['uid'], $_SESSION['session_version'] ?? 0)) {
        log_session_security_event('session_revoked', ['user_id' => $_SESSION['uid']]);
        return false;
    }

    // Check session timeout
    $timeout = (int)env('SESSION_TIMEOUT', '3600'); // 1 hour default
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        log_session_security_event('session_timeout', [
            'user_id' => $_SESSION['uid'],
            'last_activity' => $_SESSION['last_activity'],
            'timeout_duration' => $timeout,
        ]);
        return false; // Don't destroy here, let caller handle it
    }

    if (!validate_session_fingerprint()) {
        log_session_security_event('session_hijacking_attempt', ['user_id' => $_SESSION['uid']]);
        return false;
    }

    // Renew every 30 minutes, preserving in-flight requests through a bounded alias.
    if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] >= 1800) {
        renew_session_secure();
        // A concurrent logout may destroy the successor while strict mode is restored.
        if (
            !isset($_SESSION['uid'])
            || !session_version_is_current((int)$_SESSION['uid'], $_SESSION['session_version'] ?? 0)
        ) {
            return false;
        }
    }

    // Update last activity time
    $_SESSION['last_activity'] = time();

    return true;
}

// Secure session destruction
function destroy_session(): void
{
    $user_id = $_SESSION['uid'] ?? null;

    // Clear all session data
    $_SESSION = [];

    // Delete session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // Destroy session with error handling
    if (!session_destroy()) {
        // Log the error but don't fail the operation
        error_log('Session destroy error: session_destroy() returned false');
    }

    if (class_exists('Logger', false)) {
        try {
            Logger::info('session_destroyed', 'Session destroyed', [
                'user_id' => $user_id,
            ]);
        } catch (Throwable $e) {
            error_log('Logger error in destroy_session: ' . $e->getMessage());
        }
    }
}

// Enhanced require_login with session validation
function require_login_secure(): void
{
    if (!validate_session()) {
        destroy_session();
        redirect('/login.php');
    }
}

// Rate limiting for login attempts
function check_login_rate_limit(string $identifier): bool
{
    $max_attempts = 5;
    $lockout_time = 900; // 15 minutes
    $cache_key = 'login_attempts_' . hash('sha256', $identifier);

    // For simplicity, using session storage. In production, use Redis or database
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $now = time();
    $attempts = $_SESSION['rate_limits'][$cache_key] ?? [];

    // Remove old attempts
    $attempts = array_filter($attempts, function ($timestamp) use ($now, $lockout_time) {
        return ($now - $timestamp) < $lockout_time;
    });

    // Check if rate limit exceeded
    if (count($attempts) >= $max_attempts) {
        Logger::security('login_rate_limit_exceeded', [
            'identifier' => $identifier,
            'attempts' => count($attempts),
            'lockout_time' => $lockout_time
        ]);
        return false;
    }

    return true;
}

// Record login attempt
function record_login_attempt(string $identifier, bool $success): void
{
    $cache_key = 'login_attempts_' . hash('sha256', $identifier);

    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    if (!$success) {
        // Only record failed attempts
        if (!isset($_SESSION['rate_limits'][$cache_key])) {
            $_SESSION['rate_limits'][$cache_key] = [];
        }
        $_SESSION['rate_limits'][$cache_key][] = time();
    } else {
        // Clear attempts on successful login
        unset($_SESSION['rate_limits'][$cache_key]);
    }
}

/**
 * Database-backed rate limiting for API operations
 *
 * @param string $operation Operation name (e.g., 'stock_adjust', 'checkout')
 * @param int $userId User ID performing the operation
 * @param int $maxAttempts Maximum allowed attempts in the window
 * @param int $windowSeconds Time window in seconds
 * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => string]
 */
function check_rate_limit(string $operation, int $userId, int $maxAttempts = 50, int $windowSeconds = 60): array
{
    try {
        $pdo = db();
        $rateKey = $operation . ':' . $userId;
        $now = new DateTime();

        // Try to get existing rate limit record
        $stmt = $pdo->prepare('SELECT id, hit_count, reset_at FROM rate_limits WHERE rate_key = ?');
        $stmt->execute([$rateKey]);
        $record = $stmt->fetch();

        if ($record) {
            $resetAt = new DateTime($record['reset_at']);

            // Check if window has expired
            if ($now >= $resetAt) {
                // Reset the counter for new window
                $newResetAt = (clone $now)->modify("+{$windowSeconds} seconds")->format('Y-m-d H:i:s');
                $stmt = $pdo->prepare('UPDATE rate_limits SET hit_count = 1, reset_at = ? WHERE id = ?');
                $stmt->execute([$newResetAt, $record['id']]);

                return [
                    'allowed' => true,
                    'remaining' => $maxAttempts - 1,
                    'reset_at' => $newResetAt
                ];
            }

            // Check if limit exceeded
            if ($record['hit_count'] >= $maxAttempts) {
                Logger::security('rate_limit_exceeded', [
                    'operation' => $operation,
                    'user_id' => $userId,
                    'hit_count' => $record['hit_count'],
                    'reset_at' => $record['reset_at']
                ]);

                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_at' => $record['reset_at']
                ];
            }

            // Increment counter
            $stmt = $pdo->prepare('UPDATE rate_limits SET hit_count = hit_count + 1 WHERE id = ?');
            $stmt->execute([$record['id']]);

            return [
                'allowed' => true,
                'remaining' => $maxAttempts - $record['hit_count'] - 1,
                'reset_at' => $record['reset_at']
            ];
        } else {
            // Create new record
            $newResetAt = (clone $now)->modify("+{$windowSeconds} seconds")->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare('INSERT INTO rate_limits (rate_key, hit_count, reset_at) VALUES (?, 1, ?)');
            $stmt->execute([$rateKey, $newResetAt]);

            return [
                'allowed' => true,
                'remaining' => $maxAttempts - 1,
                'reset_at' => $newResetAt
            ];
        }
    } catch (Exception $e) {
        // If rate limiting fails, allow the request but log the error
        Logger::error('rate_limit_check_failed', $e, [
            'operation' => $operation,
            'user_id' => $userId
        ]);

        return [
            'allowed' => true,
            'remaining' => $maxAttempts,
            'reset_at' => null
        ];
    }
}

/**
 * Clean up expired rate limit records (can be called periodically)
 */
function cleanup_rate_limits(): int
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE reset_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Exception $e) {
        Logger::error('rate_limit_cleanup_failed', $e);
        return 0;
    }
}

// Secure login process
function secure_login(string $email, string $password): array
{
    $identifier = $_SERVER['REMOTE_ADDR'] . '|' . $email;

    // Check rate limiting
    if (!check_login_rate_limit($identifier)) {
        return [
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again in 15 minutes.',
            'locked_out' => true
        ];
    }

    try {
        // Check if must_reset_password column exists (for backwards compatibility)
        $pdo = db();
        $column_exists = false;
        try {
            $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_reset_password'");
            $column_exists = $check->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist, that's okay
            $column_exists = false;
        }

        // Build query based on whether new columns exist
        if ($column_exists) {
            $query = 'SELECT u.id, u.email, u.password_hash, u.role, u.first_name, u.last_name, u.username,
                             r.name as region_name, u.region_id, u.created_at, u.must_reset_password, u.login_method, u.session_version
                     FROM users u
                     LEFT JOIN regions r ON u.region_id = r.id
                     WHERE u.email = ?';
        } else {
            $query = 'SELECT u.id, u.email, u.password_hash, u.role, u.first_name, u.last_name, u.username,
                             r.name as region_name, u.region_id, u.created_at, u.login_method, u.session_version
                     FROM users u
                     LEFT JOIN regions r ON u.region_id = r.id
                     WHERE u.email = ?';
        }

        $stmt = safe_execute_query($query, [$email], 'user_login');
        $user = $stmt->fetch();

        // Set default value if column doesn't exist
        if ($user && !$column_exists) {
            $user['must_reset_password'] = 0;
        }

        if (!$user) {
            // User not found - still check password to prevent timing attacks
            password_verify($password, '$2y$10$dummy.hash.to.prevent.timing.attacks');

            record_login_attempt($identifier, false);
            Logger::security('login_attempt_invalid_user', ['email' => $email]);

            return [
                'success' => false,
                'message' => 'Invalid email or password.',
                'locked_out' => false
            ];
        }

        if (!password_verify($password, $user['password_hash']) || !user_accepts_local_credentials((string)$user['login_method'])) {
            record_login_attempt($identifier, false);
            Logger::security('login_attempt_invalid_password', [
                'user_id' => $user['id'],
                'email' => $email
            ]);

            return [
                'success' => false,
                'message' => 'Invalid email or password.',
                'locked_out' => false
            ];
        }

        // Successful login
        record_login_attempt($identifier, true);

        // Regenerate session ID for security
        regenerate_session_secure();

        // Set session data
        // Keep the version verified with the password/role snapshot; never adopt a later revocation.
        $_SESSION['session_version'] = (int)$user['session_version'];
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['login_method'] = 'local';
        $_SESSION['email'] = $user['email'] ?? $email;
        $_SESSION['first_name'] = $user['first_name'] ?? null;
        $_SESSION['last_name'] = $user['last_name'] ?? null;
        $_SESSION['username'] = $user['username'] ?? null;
        $_SESSION['display_name'] = build_user_display_name(
            $user['first_name'] ?? null,
            $user['last_name'] ?? null,
            $user['username'] ?? null,
            $user['email'] ?? $email
        );
        $_SESSION['region_id'] = $user['region_id'];
        $_SESSION['region'] = $user['region_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();
        $_SESSION['fingerprint'] = generate_session_fingerprint();
        $_SESSION['login_time'] = time();
        sync_user_role_assignment((int)$user['id'], $user['role'] ?? null);
        reset_user_permission_cache();

        Logger::security('successful_login', [
            'user_id' => $user['id'],
            'email' => $email,
            'must_reset_password' => (bool)$user['must_reset_password']
        ]);

        return [
            'success' => true,
            'message' => 'Login successful.',
            'user' => $user,
            'must_reset_password' => (bool)$user['must_reset_password']
        ];
    } catch (Exception $e) {
        Logger::error('login_process', $e, ['email' => $email]);

        return [
            'success' => false,
            'message' => 'Login failed due to a system error. Please try again.',
            'locked_out' => false
        ];
    }
}

// API Response helpers
function api_success(mixed $data = null, string $message = 'Success'): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

function api_error(string $message, int $code = 400, array $details = []): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => $code,
        'details' => $details
    ]);
    exit;
}

function api_handle_exception(Exception $e, string $context = ''): void
{
    error_log("API Error [{$context}]: " . $e->getMessage());

    // Don't expose internal errors in production
    $message = env('APP_ENV') === 'dev' ? $e->getMessage() : 'An error occurred. Please try again.';

    api_error($message, 500);
}

// Helper function to convert timestamps to California timezone
function format_log_timestamp(mixed $timestamp): mixed
{
    try {
        // Create DateTime object from the timestamp
        $dt = new DateTime($timestamp);

        // Set the timezone to California (Pacific Time)
        $californiaTimezone = new DateTimeZone('America/Los_Angeles');
        $dt->setTimezone($californiaTimezone);

        // Return formatted timestamp with timezone indicator
        return $dt->format('Y-m-d H:i:s T');
    } catch (Exception $e) {
        // If conversion fails, return the original timestamp
        return $timestamp;
    }
}

function build_user_display_name(?string $firstName, ?string $lastName, ?string $username, ?string $email): string
{
    $username = trim($username ?? '');
    if ($username !== '') {
        return $username;
    }
    $fullName = trim(trim($firstName ?? '') . ' ' . trim($lastName ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }
    return $email ?? '';
}

function permission_definitions(): array
{
    static $definitions = null;

    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [
        'System' => [
            'system.full_access' => 'Bypass all permission checks',
            'admin.access' => 'Access the admin dashboard'
        ],
        'Dashboard' => [
            'dashboard.view' => 'View dashboard overview'
        ],
        'Cart & Favorites' => [
            'cart.manage' => 'Manage cart items',
            'favorites.manage' => 'Manage favorite products',
            'order_templates.manage' => 'Manage order templates'
        ],
        'Orders' => [
            'orders.create' => 'Create/submit orders',
            'orders.view_own' => 'View own orders',
            'orders.view_region' => 'View orders for assigned region',
            'orders.view_all' => 'View all orders',
            'orders.manage' => 'Manage order lifecycle (cancel/update)'
        ],
        'Receiving' => [
            'receiving.receive_region' => 'Receive supply orders into assigned region storage'
        ],
        'Region Storage' => [
            'region_storage.view' => 'View regional storage inventory',
            'region_storage.adjust' => 'Adjust regional storage quantities',
            'region_storage.transfer_van' => 'Transfer stock from region to van',
            'region_storage.transfer_region' => 'Transfer stock between regions',
            'region_storage.audit.view' => 'View region storage audit logs'
        ],
        'Van Stock' => [
            'van_stock.view' => 'View van stock levels',
            'van_stock.adjust' => 'Adjust van stock',
            'van_stock.transfer' => 'Transfer between van stores',
            'van_stock.transactions.view' => 'View van stock transactions',
            'stock.view_own' => 'Legacy: view own stock',
            'stock.adjust' => 'Legacy: adjust stock',
            'stock.transfer' => 'Legacy: transfer stock'
        ],
        'Kits & Shipments' => [
            'kit.view'             => 'View kit templates',
            'kit.manage'           => 'Create and edit kit templates',
            'kit.shipments.view'   => 'View kit shipments',
            'kit.shipments.create' => 'Create a shipment from a kit template',
            'kit.shipments.manage' => 'Update or cancel kit shipments',
        ],
        'Deployments' => [
            'deployment.view' => 'View deployment projects',
            'deployment.manage' => 'Update deployment progress and notes',
            'deployment.tasks.customize' => 'Customize deployment task lists',
            'deployment.reports.export' => 'Export deployment reports'
        ],
        'Forms' => [
            'forms.manage' => 'Create/edit/publish forms',
            'forms.campaigns.manage' => 'Create and manage form campaigns',
            'forms.submit_internal' => 'Submit internal form responses',
            'forms.invites.send' => 'Send contractor invitations',
            'forms.view_responses' => 'View form responses',
            'forms.export' => 'Export form responses'
        ],
        'Products & Catalog' => [
            'products.view' => 'View products',
            'products.create' => 'Create products',
            'products.edit' => 'Edit products',
            'products.delete' => 'Delete products',
            'products.bulk_import' => 'Bulk import/update catalog'
        ],
        'Users & Roles' => [
            'admin.users.view' => 'View users',
            'admin.users.manage' => 'Manage users',
            'roles.manage' => 'Manage roles & assignments'
        ],
        'Stores & Regions' => [
            'admin.stores.view' => 'View stores',
            'admin.stores.manage' => 'Manage stores',
            'admin.regions.view' => 'View regions',
            'admin.regions.manage' => 'Manage regions'
        ],
        'Logs & Diagnostics' => [
            'admin.logs.view' => 'View system/security logs',
            'admin.logs.clear' => 'Clear log files',
            'admin.files.manage' => 'Manage uploaded files',
            'admin.sessions.manage' => 'Manage sessions/force logout',
            'audit_logs.view' => 'View audit log entries',
            'reports.view' => 'View reports/analytics',
            'api_keys.manage' => 'Manage API keys',
            'migrations.run' => 'Run database migrations'
        ],
        'Settings' => [
            'settings.view' => 'View application settings',
            'settings.manage' => 'Manage application settings'
        ],
        'Snipe IT Assets' => [
            'assets.view' => 'View IT assets from Snipe IT',
            'assets.checkout' => 'Check out equipment from inventory',
            'assets.checkin' => 'Return equipment to inventory',
            'assets.manage' => 'Edit asset details and reassign equipment',
            'assets.licenses' => 'Manage software licenses',
            'assets.maintenance' => 'Track warranties and maintenance schedules',
            'assets.categories' => 'Create and manage asset categories',
            'assets.reports' => 'Generate and view asset reports',
            'assets.admin' => 'Full Snipe IT asset administration'
        ]
    ];

    return $definitions;
}

function all_permission_keys(): array
{
    $keys = [];
    foreach (permission_definitions() as $group => $entries) {
        foreach ($entries as $key => $label) {
            $keys[] = strtolower($key);
        }
    }
    return array_values(array_unique($keys));
}

function get_role_id_by_slug(string $slug): ?int
{
    static $cache = [];
    $slug = strtolower(trim($slug));

    if ($slug === '') {
        return null;
    }

    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }

    if (!roles_tables_available()) {
        $cache[$slug] = null;
        return null;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $stmt->execute([$slug]);
        $roleId = $stmt->fetchColumn();
        $cache[$slug] = $roleId ? (int)$roleId : null;
        return $cache[$slug];
    } catch (Throwable $e) {
        Logger::error('role_lookup_failed', $e, ['slug' => $slug]);
        $cache[$slug] = null;
        return null;
    }
}

function sync_user_role_assignment(int $userId, ?string $legacyRole = null): void
{
    if ($userId <= 0 || !roles_tables_available()) {
        return;
    }

    $legacyRole = $legacyRole ?? null;
    if ($legacyRole === null) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $legacyRole = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            Logger::error('fetch_legacy_role_failed', $e, ['user_id' => $userId]);
            return;
        }
    }

    $legacyRole = strtolower((string)$legacyRole);
    $map = [
        'admin' => 'admin',
        'manager' => 'field_tech_lead',
        'user' => 'field_tech'
    ];
    $roleSlug = $map[$legacyRole] ?? null;
    if (!$roleSlug) {
        return;
    }

    $roleId = get_role_id_by_slug($roleSlug);
    if (!$roleId) {
        return;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $stmt->execute([$userId, $roleId]);
    } catch (Throwable $e) {
        Logger::error('sync_user_role_failed', $e, [
            'user_id' => $userId,
            'role_slug' => $roleSlug
        ]);
    }
}

function roles_tables_available(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
        $available = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function fetch_user_permissions(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT DISTINCT LOWER(rp.permission_key) AS permission_key
        FROM user_roles ur
        INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
        WHERE ur.user_id = ?
    ');
    $stmt->execute([$userId]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_filter(array_map('strval', $permissions))));
}

function load_user_permissions_from_db(int $userId): ?array
{
    if ($userId <= 0 || !roles_tables_available()) {
        return null;
    }

    try {
        $pdo = db();
        $permissions = fetch_user_permissions($pdo, $userId);

        if (empty($permissions)) {
            sync_user_role_assignment($userId, $_SESSION['role'] ?? null);
            $permissions = fetch_user_permissions($pdo, $userId);
        }

        if (empty($permissions)) {
            return null;
        }

        return $permissions;
    } catch (Throwable $e) {
        Logger::error('permission_load_failed', $e, ['user_id' => $userId]);
        return null;
    }
}

function current_user_permissions(): ?array
{
    $userId = $_SESSION['uid'] ?? null;
    if (!$userId) {
        return null;
    }

    if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
        return merge_legacy_permissions($_SESSION['permissions']);
    }

    $permissions = load_user_permissions_from_db((int)$userId);
    if ($permissions !== null) {
        $_SESSION['permissions'] = $permissions;
    }

    return $permissions !== null ? merge_legacy_permissions($permissions) : null;
}

function reset_user_permission_cache(): void
{
    unset($_SESSION['permissions']);
}

function app_settings_cache(bool $forceRefresh = false): array
{
    static $cache = null;

    if ($forceRefresh) {
        $cache = null;
    }

    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = db();
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings');
        $cache = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cache[strtolower($row['setting_key'])] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        $cache = [];
    }

    return $cache;
}

function get_app_setting(string $key, mixed $default = null): mixed
{
    $normalized = strtolower(trim($key));
    if ($normalized === '') {
        return $default;
    }

    $settings = app_settings_cache();
    if (array_key_exists($normalized, $settings)) {
        return $settings[$normalized];
    }

    return $default;
}

function set_app_setting(string $key, mixed $value, ?int $updatedBy = null): bool
{
    $normalized = strtolower(trim($key));
    if ($normalized === '') {
        return false;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('
            INSERT INTO app_settings (setting_key, setting_value, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$normalized, (string)$value, $updatedBy]);
        app_settings_cache(true);
        return true;
    } catch (Throwable $e) {
        Logger::error('app_setting_update_failed', $e, [
            'setting_key' => $normalized
        ]);
        return false;
    }
}

function get_max_order_quantity(): int
{
    $value = get_app_setting('max_order_quantity', env('MAX_ORDER_QUANTITY', '100'));
    $value = (int)$value;
    if ($value <= 0) {
        $value = 100;
    }
    return $value;
}

function is_checkout_validation_enabled(): bool
{
    $value = get_app_setting('checkout_validation_enabled', env('CHECKOUT_VALIDATION_ENABLED', '1'));
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        $value = '1';
    }
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

// Turnstile and login/register page image settings. Admin-editable (see
// admin.php's update_turnstile_keys / update_login_images /
// update_register_images), stored in app_settings with the .env value as
// the first-run default -- writing these to .env directly used to fail
// with "Permission denied" in Docker, since the container can't write
// back to a file mounted from the host.
function get_turnstile_site_key(): string
{
    return (string)get_app_setting('turnstile_site_key', env('TURNSTILE_SITE_KEY', ''));
}

function get_turnstile_secret_key(): string
{
    return (string)get_app_setting('turnstile_secret_key', env('TURNSTILE_SECRET_KEY', ''));
}

function is_turnstile_enabled(): bool
{
    $value = strtolower(trim((string)get_app_setting('turnstile_enabled', env('TURNSTILE_ENABLED', '0'))));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function get_login_left_image(): string
{
    return (string)get_app_setting('login_left_image', env('LOGIN_LEFT_IMAGE', ''));
}

function get_login_right_image(): string
{
    return (string)get_app_setting('login_right_image', env('LOGIN_RIGHT_IMAGE', ''));
}

function get_register_left_image(): string
{
    return (string)get_app_setting('register_left_image', env('REGISTER_LEFT_IMAGE', ''));
}

function get_register_right_image(): string
{
    return (string)get_app_setting('register_right_image', env('REGISTER_RIGHT_IMAGE', ''));
}

// Enhanced centralized logging system
class Logger
{
    private static string $logDir = '/var/www/logs';
    private static int $maxFileSize = 10485760; // 10MB
    private static int $maxFiles = 5;

    public static function init(): void
    {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    public static function error(string $context, Throwable $e, array $extra = []): void
    {
        $logData = [
            'level' => 'ERROR',
            'context' => $context,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => self::formatTrace($e->getTrace()),
            'extra' => $extra,
            'user_id' => $_SESSION['uid'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'timestamp' => date('c'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        self::writeLog('error', $logData);

        // Also log to PHP error log for immediate visibility
        error_log("ERROR [{$context}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }

    public static function security(string $event, array $details = []): void
    {
        $details = log_safe_context($details);
        $logData = [
            'level' => 'SECURITY',
            'event' => $event,
            'details' => $details,
            'user_id' => $_SESSION['uid'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'forwarded_ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'timestamp' => date('c')
        ];

        self::writeLog('security', $logData);

        // Log critical security events to PHP error log
        if (in_array($event, ['failed_login', 'csrf_violation', 'unauthorized_access'])) {
            error_log("SECURITY [{$event}]: " . json_encode($details));
        }
    }

    public static function performance(string $operation, float $duration, array $extra = []): void
    {
        $logData = [
            'level' => 'PERFORMANCE',
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'extra' => $extra,
            'user_id' => $_SESSION['uid'] ?? null,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => date('c')
        ];

        self::writeLog('performance', $logData);

        // Log slow operations to PHP error log
        if ($duration > 2.0) { // Log operations taking more than 2 seconds
            error_log("SLOW OPERATION [{$operation}]: {$duration}s");
        }
    }

    /** Expected problems caused by the client (bad input); not a fault in our code. */
    public static function warning(string $context, string $message, array $extra = []): void
    {
        self::writeLog('warning', [
            'level' => 'WARNING',
            'context' => $context,
            'message' => $message,
            'extra' => $extra,
            'user_id' => $_SESSION['uid'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'timestamp' => date('c')
        ]);
    }

    public static function info(string $context, string $message, array $extra = []): void
    {
        $logData = [
            'level' => 'INFO',
            'context' => $context,
            'message' => $message,
            'extra' => $extra,
            'user_id' => $_SESSION['uid'] ?? null,
            'timestamp' => date('c')
        ];

        self::writeLog('info', $logData);
    }

    public static function debug(string $context, string $message, array $extra = []): void
    {
        // Only log debug messages in development
        if (env('APP_ENV') !== 'dev') {
            return;
        }

        $logData = [
            'level' => 'DEBUG',
            'context' => $context,
            'message' => $message,
            'extra' => $extra,
            'user_id' => $_SESSION['uid'] ?? null,
            'timestamp' => date('c')
        ];

        self::writeLog('debug', $logData);
    }

    private static function writeLog(string $type, array $data): void
    {
        $data = log_safe_context($data);
        // Ensure log directory exists
        if (!is_dir(self::$logDir)) {
            if (!@mkdir(self::$logDir, 0755, true)) {
                // If we can't create the log directory, fall back to error_log
                error_log("Logger: Failed to create log directory " . self::$logDir);
                error_log("Logger [{$type}]: " . json_encode($data));
                return;
            }
        }

        $filename = self::$logDir . '/' . $type . '_' . date('Y-m-d') . '.log';

        // Rotate log if it's too large
        if (file_exists($filename) && filesize($filename) > self::$maxFileSize) {
            self::rotateLog($filename);
        }

        $logLine = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // Use file locking to prevent corruption with error handling
        if (log_private_append($filename, $logLine) === false) {
            // If file write fails, fall back to error_log
            error_log("Logger [{$type}]: " . json_encode($data));
        }
    }

    private static function rotateLog(string $filename): void
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $directory = dirname($filename);

        // Rotate existing files
        for ($i = self::$maxFiles - 1; $i >= 1; $i--) {
            $oldFile = $directory . '/' . $baseName . '.' . $i . '.' . $extension;
            $newFile = $directory . '/' . $baseName . '.' . ($i + 1) . '.' . $extension;

            if (file_exists($oldFile)) {
                if ($i + 1 > self::$maxFiles) {
                    unlink($oldFile); // Delete oldest file
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }

        // Move current file to .1
        $rotatedFile = $directory . '/' . $baseName . '.1.' . $extension;
        rename($filename, $rotatedFile);
    }

    private static function formatTrace(array $trace): array
    {
        $formatted = [];

        foreach (array_slice($trace, 0, 10) as $frame) { // Limit to 10 frames
            $formatted[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null
            ];
        }

        return $formatted;
    }

    public static function getLogStats(): array
    {
        $stats = [];
        $logTypes = ['error', 'warning', 'security', 'performance', 'info', 'debug'];

        foreach ($logTypes as $type) {
            $filename = self::$logDir . '/' . $type . '_' . date('Y-m-d') . '.log';

            if (file_exists($filename)) {
                $stats[$type] = [
                    'size' => filesize($filename),
                    'lines' => count(file($filename)),
                    'last_modified' => filemtime($filename)
                ];
            } else {
                $stats[$type] = [
                    'size' => 0,
                    'lines' => 0,
                    'last_modified' => null
                ];
            }
        }

        return $stats;
    }
}

// Initialize logger
Logger::init();

// Include helper files
$helperFiles = [
    __DIR__ . '/helpers/url_helper.php',
    __DIR__ . '/helpers/audit_logger.php'
];

foreach ($helperFiles as $helperFile) {
    if (file_exists($helperFile)) {
        require_once $helperFile;
    }
}

// Ensure logs directory exists on Windows/Docker
if (!is_dir('/var/www/logs')) {
    @mkdir('/var/www/logs', 0755, true);
}

// Set up global error handlers
set_error_handler(function ($severity, $message, $file, $line) {
    // Don't log suppressed errors (with @)
    if (!(error_reporting() & $severity)) {
        return false;
    }

    // Convert to exception for consistent logging
    $exception = new ErrorException($message, 0, $severity, $file, $line);

    // Safely log without risking config.php reload
    try {
        if (class_exists('Logger', false)) {
            Logger::error('php_error', $exception, [
                'severity' => $severity,
                'severity_name' => [
                    E_ERROR => 'E_ERROR',
                    E_WARNING => 'E_WARNING',
                    E_PARSE => 'E_PARSE',
                    E_NOTICE => 'E_NOTICE',
                    E_CORE_ERROR => 'E_CORE_ERROR',
                    E_CORE_WARNING => 'E_CORE_WARNING',
                    E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                    E_COMPILE_WARNING => 'E_COMPILE_WARNING',
                    E_USER_ERROR => 'E_USER_ERROR',
                    E_USER_WARNING => 'E_USER_WARNING',
                    E_USER_NOTICE => 'E_USER_NOTICE',
                    E_STRICT => 'E_STRICT',
                    E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
                    E_DEPRECATED => 'E_DEPRECATED',
                    E_USER_DEPRECATED => 'E_USER_DEPRECATED'
                ][$severity] ?? 'UNKNOWN'
            ]);
        } else {
            // Fallback to error_log if Logger not available
            error_log(sprintf('PHP Error [%d]: %s in %s:%d', $severity, $message, $file, $line));
        }
    } catch (Throwable $e) {
        // Last resort: plain error_log to avoid cascading failures
        error_log(sprintf('Error handler failed: %s (Original: %s in %s:%d)', $e->getMessage(), $message, $file, $line));
    }

    // Don't prevent default error handling
    return false;
});

set_exception_handler(function ($exception) {
    // Wrong-typed request input (e.g. ?name[]=x) is the client's mistake: 400, not 500.
    if (qt_is_client_error($exception)) {
        qt_respond_bad_request($exception);
    }

    // Safely log without risking config.php reload
    try {
        if (class_exists('Logger', false)) {
            Logger::error('uncaught_exception', $exception, [
                'type' => get_class($exception)
            ]);
        } else {
            error_log(sprintf('Uncaught Exception [%s]: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }
    } catch (Throwable $e) {
        error_log(sprintf('Exception handler failed: %s (Original: %s)', $e->getMessage(), $exception->getMessage()));
    }

    // Generate error ID for tracking
    $error_id = uniqid('ERR-', true);

    // Set HTTP response code
    http_response_code(500);

    // Check if this is an AJAX/API request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) &&
                   strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    if ($isAjax || $isApi || $acceptsJson) {
        // Return JSON error for AJAX/API requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'An internal server error occurred',
            'code' => 500,
            'error_id' => $error_id
        ]);
        exit;
    }

    // Show user-friendly error page
    if (app_is_production()) {
        // Use our custom error view if available
        $errorView = __DIR__ . '/views/errors/500.view.php';
        if (file_exists($errorView)) {
            require $errorView;
        } else {
            // Fallback error page
            echo '<!DOCTYPE html><html><head><title>Error 500</title></head><body>';
            echo '<h1>Something went wrong</h1>';
            echo '<p>We apologize for the inconvenience. The error has been logged and will be investigated.</p>';
            echo '<p>Error ID: ' . htmlspecialchars($error_id) . '</p>';
            echo '<p><a href="/">Return to homepage</a></p>';
            echo '</body></html>';
        }
        exit;
    }

    // In development, show the actual error
    throw $exception;
});

// Malformed request data (invalid UTF-8, NUL bytes, absurd nesting) is a 400 for every page and API.
qt_assert_clean_request_input($_GET, $_POST);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Use plain error_log to avoid re-loading config.php which would cause "Cannot redeclare" errors
        // DO NOT use Logger class here as it may trigger config.php reload in shutdown context
        $errorMsg = sprintf(
            "FATAL ERROR [%s]: %s in %s:%d",
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line']
        );
        error_log($errorMsg);

        // Log to file directly without using Logger class
        $logDir = '/var/www/logs';
        if (is_dir($logDir)) {
            $logFile = $logDir . '/error.log';
            $logEntry = json_encode([
                'level' => 'FATAL',
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'timestamp' => date('c'),
                'user_id' => $_SESSION['uid'] ?? null,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
});

// Sensitive operation validation
function validate_sensitive_operation(string $operation, array $context = []): void
{
    // Operations that require admin privileges
    $adminOnlyOperations = [
        'user_management',
        'system_config',
        'log_management',
        'file_management',
        'session_management',
        'database_operations',
        'security_config'
    ];

    if (in_array($operation, $adminOnlyOperations, true)) {
        require_admin();
    }

    // Log sensitive operations for audit trail
    Logger::security('sensitive_operation', array_merge([
        'operation' => $operation,
        'user_id' => $_SESSION['uid'] ?? null,
        'user_role' => $_SESSION['role'] ?? 'unknown',
        'timestamp' => time()
    ], $context));
}

// Role management helper functions
function get_user_role(): string
{
    return $_SESSION['role'] ?? 'user';
}

function set_user_role(int $userId, string $role): bool
{
    if (!has_permission('roles.manage')) {
        Logger::security('unauthorized_role_change_attempt', [
            'target_user_id' => $userId,
            'attempted_role' => $role,
            'current_user_id' => $_SESSION['uid'] ?? null,
            'current_role' => $_SESSION['role'] ?? 'unknown'
        ]);
        return false;
    }

    $validRoles = ['admin', 'manager', 'user'];
    if (!in_array($role, $validRoles, true)) {
        Logger::security('invalid_role_assignment_attempt', [
            'target_user_id' => $userId,
            'invalid_role' => $role,
            'admin_user_id' => $_SESSION['uid']
        ]);
        return false;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $result = $stmt->execute([$role, $userId]);

        if ($result) {
            Logger::security('user_role_changed', [
                'target_user_id' => $userId,
                'new_role' => $role,
                'admin_user_id' => $_SESSION['uid']
            ]);

            // If changing current user's role, update session
            if ($userId === ($_SESSION['uid'] ?? 0)) {
                $_SESSION['role'] = $role;
            }
        }

        return $result;
    } catch (Exception $e) {
        Logger::error('role_change_error', $e, [
            'target_user_id' => $userId,
            'role' => $role
        ]);
        return false;
    }
}

function get_available_roles(): array
{
    return [
        'user' => 'User',
        'manager' => 'Manager',
        'admin' => 'Administrator'
    ];
}

// Enhanced permission validation patterns
class PermissionValidator
{
    public static function validateAdminAccess(string $context = ''): void
    {
        if (!is_admin()) {
            Logger::security('admin_access_denied', [
                'context' => $context,
                'user_id' => $_SESSION['uid'] ?? null,
                'user_role' => $_SESSION['role'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);

            $isApi = self::isApiRequest();

            if ($isApi) {
                api_error('Administrator access required', 403);
            } else {
                flash_message('Access denied. Administrator privileges required.', 'error');
                redirect('/index.php');
            }
        }
    }

    public static function validateUserAccess(int $targetUserId, string $context = ''): void
    {
        $currentUserId = $_SESSION['uid'] ?? 0;

        // Admins can access any user, users can only access themselves
        if (!is_admin() && $currentUserId !== $targetUserId) {
            Logger::security('user_access_denied', [
                'context' => $context,
                'current_user_id' => $currentUserId,
                'target_user_id' => $targetUserId,
                'user_role' => $_SESSION['role'] ?? 'unknown'
            ]);

            $isApi = self::isApiRequest();

            if ($isApi) {
                api_error('Access denied. You can only access your own data.', 403);
            } else {
                flash_message('Access denied. You can only access your own data.', 'error');
                redirect('/index.php');
            }
        }
    }

    public static function validateRegionAccess(string $targetRegion, string $context = ''): void
    {
        $userRegion = $_SESSION['region'] ?? '';

        // Admins can access any region, users can only access their own
        if (!is_admin() && $userRegion !== $targetRegion) {
            Logger::security('region_access_denied', [
                'context' => $context,
                'user_region' => $userRegion,
                'target_region' => $targetRegion,
                'user_id' => $_SESSION['uid'] ?? null
            ]);

            $isApi = self::isApiRequest();

            if ($isApi) {
                api_error('Access denied. You can only access your region data.', 403);
            } else {
                flash_message('Access denied. You can only access your region data.', 'error');
                redirect('/index.php');
            }
        }
    }

    public static function validateOperationPermission(string $operation, string $context = ''): void
    {
        if (!has_permission($operation)) {
            Logger::security('operation_permission_denied', [
                'operation' => $operation,
                'context' => $context,
                'user_id' => $_SESSION['uid'] ?? null,
                'user_role' => $_SESSION['role'] ?? 'unknown'
            ]);

            $isApi = self::isApiRequest();

            if ($isApi) {
                api_error('Access denied. Insufficient permissions for this operation.', 403);
            } else {
                flash_message('Access denied. Insufficient permissions for this operation.', 'error');
                redirect('/index.php');
            }
        }
    }

    private static function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
               !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
               (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
    }

    public static function validateSensitiveOperation(string $operation, array $context = []): void
    {
        // Define operations that require admin access
        $adminOnlyOperations = [
            'user_role_management',
            'system_configuration',
            'log_management',
            'file_system_access',
            'session_management',
            'database_administration',
            'security_configuration',
            'bulk_data_operations'
        ];

        // Define operations that require specific permissions
        $permissionRequiredOperations = [
            'product_management' => 'products.create',
            'stock_adjustment' => 'stock.adjust',
            'order_management' => 'orders.manage',
            'user_data_access' => 'admin.users.view',
            'role_management' => 'roles.manage',
            'settings_change' => 'settings.manage',
            'audit_log_view' => 'audit_logs.view'
        ];

        if (in_array($operation, $adminOnlyOperations, true)) {
            self::validateAdminAccess($operation);
        } elseif (isset($permissionRequiredOperations[$operation])) {
            self::validateOperationPermission($permissionRequiredOperations[$operation], $operation);
        }

        // Log all sensitive operations for audit trail
        Logger::security('sensitive_operation_performed', array_merge([
            'operation' => $operation,
            'user_id' => $_SESSION['uid'] ?? null,
            'user_role' => $_SESSION['role'] ?? 'unknown',
            'timestamp' => time(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $context));
    }
}

// Backward compatibility functions
function log_error(string $context, Throwable $e, array $extra = []): void
{
    Logger::error($context, $e, $extra);
}

function log_security_event(string $event, array $details = []): void
{
    Logger::security($event, $details);
}

function log_performance(string $operation, float $duration, array $extra = []): void
{
    Logger::performance($operation, $duration, $extra);
}

function log_info(string $context, string $message, array $extra = []): void
{
    Logger::info($context, $message, $extra);
}

function log_debug(string $context, string $message, array $extra = []): void
{
    Logger::debug($context, $message, $extra);
}

// Input validation and sanitization helpers
class ValidationResult
{
    public bool $isValid = true;
    public array $errors = [];
    public array $sanitizedData = [];

    public function addError(string $field, string $message): void
    {
        $this->isValid = false;
        $this->errors[$field] = $message;
    }

    public function hasErrors(): bool
    {
        return !$this->isValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function setSanitizedValue(string $field, mixed $value): void
    {
        $this->sanitizedData[$field] = $value;
    }

    public function getSanitizedData(): array
    {
        return $this->sanitizedData;
    }
}

// Input sanitization functions
function sanitize_string(string $input, int $maxLength = 255): string
{
    $sanitized = trim($input);
    // FILTER_SANITIZE_STRING is deprecated in PHP 8.1+, use htmlspecialchars instead
    $sanitized = htmlspecialchars($sanitized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Decode to allow normal text but keep dangerous characters escaped
    $sanitized = html_entity_decode($sanitized, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Remove any null bytes and control characters
    $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);
    return mb_substr($sanitized, 0, $maxLength);
}

function sanitize_email(string $email): string
{
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

function sanitize_int(string $input): int
{
    return (int)filter_var($input, FILTER_SANITIZE_NUMBER_INT);
}

function sanitize_float(string $input): float
{
    return (float)filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function sanitize_filename(string $filename): string
{
    // Remove any path traversal attempts
    $filename = basename($filename);
    // Remove dangerous characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    // Limit length
    return mb_substr($filename, 0, 100);
}

// Input validation functions
function validate_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_required(string $value): bool
{
    return trim($value) !== '';
}

function validate_length(string $value, int $min = 0, int $max = 255): bool
{
    $length = mb_strlen(trim($value));
    return $length >= $min && $length <= $max;
}

function validate_int(string $value, int $min = null, int $max = null): bool
{
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false) {
        return false;
    }

    if ($min !== null && $int < $min) {
        return false;
    }
    if ($max !== null && $int > $max) {
        return false;
    }

    return true;
}

function validate_product_id(string $productId): bool
{
    // Product ID should be alphanumeric with dashes and underscores
    return preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $productId) === 1;
}

// File upload validation
function validate_uploaded_file(array $file, array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'], int $maxSize = 10485760): ValidationResult
{
    $result = new ValidationResult();

    if ($file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $result->addError('file', 'File is too large');
                break;
            case UPLOAD_ERR_PARTIAL:
                $result->addError('file', 'File upload was interrupted');
                break;
            case UPLOAD_ERR_NO_FILE:
                $result->addError('file', 'No file was uploaded');
                break;
            default:
                $result->addError('file', 'File upload failed');
        }
        return $result;
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        $result->addError('file', 'File is too large (max ' . number_format($maxSize / 1024 / 1024, 1) . 'MB)');
        return $result;
    }

    // Check MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedTypes, true)) {
        $result->addError('file', 'Invalid file type. Allowed: ' . implode(', ', $allowedTypes));
        return $result;
    }

    // For images, verify it's actually an image
    if (strpos($mimeType, 'image/') === 0) {
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            $result->addError('file', 'Invalid image file');
            return $result;
        }

        // Check for reasonable dimensions
        if ($imageInfo[0] > 5000 || $imageInfo[1] > 5000) {
            $result->addError('file', 'Image dimensions too large (max 5000x5000)');
            return $result;
        }
    }

    return $result;
}

// Comprehensive form validation
function validate_product_form(array $data): ValidationResult
{
    $result = new ValidationResult();

    // Validate product ID
    $productId = sanitize_string($data['product_id'] ?? '', 50);
    if (!validate_required($productId)) {
        $result->addError('product_id', 'Product ID is required');
    } elseif (!validate_product_id($productId)) {
        $result->addError('product_id', 'Product ID can only contain letters, numbers, dashes, and underscores (max 50 characters)');
    }
    $result->setSanitizedValue('product_id', $productId);

    // Validate name
    $name = sanitize_string($data['name'] ?? '', 255);
    if (!validate_required($name)) {
        $result->addError('name', 'Product name is required');
    } elseif (!validate_length($name, 1, 255)) {
        $result->addError('name', 'Product name must be between 1 and 255 characters');
    }
    $result->setSanitizedValue('name', $name);

    // Validate optional fields
    $result->setSanitizedValue('asset_type', sanitize_string($data['asset_type'] ?? '', 100));
    $result->setSanitizedValue('manufacturer', sanitize_string($data['manufacturer'] ?? '', 100));
    $result->setSanitizedValue('status', sanitize_string($data['status'] ?? '', 50));
    $result->setSanitizedValue('color', sanitize_string($data['color'] ?? '', 50));
    $result->setSanitizedValue('order_number', sanitize_string($data['order_number'] ?? '', 100));

    return $result;
}

function validate_stock_form(array $data): ValidationResult
{
    $result = new ValidationResult();

    // Validate product ID
    $productId = sanitize_int($data['product_id'] ?? '0');
    if ($productId <= 0) {
        $result->addError('product_id', 'Please select a valid product');
    }
    $result->setSanitizedValue('product_id', $productId);

    // Validate quantity
    $quantity = sanitize_int($data['quantity'] ?? '0');
    if ($quantity <= 0) {
        $result->addError('quantity', 'Quantity must be a positive number');
    } elseif ($quantity > 10000) {
        $result->addError('quantity', 'Quantity cannot exceed 10,000');
    }
    $result->setSanitizedValue('quantity', $quantity);

    // Validate store ID
    $storeId = sanitize_int($data['store_id'] ?? '1');
    if ($storeId <= 0) {
        $result->addError('store_id', 'Please select a valid store');
    }
    $result->setSanitizedValue('store_id', $storeId);

    // Validate note
    $note = sanitize_string($data['note'] ?? '', 255);
    $result->setSanitizedValue('note', $note);

    return $result;
}

function validate_user_registration(array $data): ValidationResult
{
    $result = new ValidationResult();

    // Validate first name
    $firstName = sanitize_string($data['first_name'] ?? '', 80);
    if (!validate_required($firstName)) {
        $result->addError('first_name', 'First name is required');
    } elseif (!validate_length($firstName, 1, 80)) {
        $result->addError('first_name', 'First name must be between 1 and 80 characters');
    }
    $result->setSanitizedValue('first_name', $firstName);

    // Validate last name
    $lastName = sanitize_string($data['last_name'] ?? '', 80);
    if (!validate_required($lastName)) {
        $result->addError('last_name', 'Last name is required');
    } elseif (!validate_length($lastName, 1, 80)) {
        $result->addError('last_name', 'Last name must be between 1 and 80 characters');
    }
    $result->setSanitizedValue('last_name', $lastName);

    // Validate username
    $username = sanitize_string($data['username'] ?? '', 80);
    if (!validate_required($username)) {
        $result->addError('username', 'Username is required');
    } elseif (!validate_length($username, 3, 50)) {
        $result->addError('username', 'Username must be between 3 and 50 characters');
    } elseif (preg_match('/[^a-zA-Z0-9._-]/', $username)) {
        $result->addError('username', 'Username can only contain letters, numbers, dots, underscores, and hyphens');
    }
    $result->setSanitizedValue('username', $username);

    // Validate email
    $email = sanitize_email($data['email'] ?? '');
    if (!validate_required($email)) {
        $result->addError('email', 'Email is required');
    } elseif (!validate_email($email)) {
        $result->addError('email', 'Please enter a valid email address');
    }
    $result->setSanitizedValue('email', $email);

    // Validate password
    $password = $data['password'] ?? '';
    if (!validate_required($password)) {
        $result->addError('password', 'Password is required');
    } elseif (($passwordError = password_policy_error($password)) !== null) {
        $result->addError('password', $passwordError);
    }
    $result->setSanitizedValue('password', $password);

    // Validate password confirmation
    $confirm = $data['confirm'] ?? '';
    if ($password !== $confirm) {
        $result->addError('confirm', 'Passwords do not match');
    }

    // Validate region ID
    $regionId = sanitize_int($data['region_id'] ?? '0');
    if ($regionId <= 0) {
        $result->addError('region_id', 'Please select a valid region');
    }
    $result->setSanitizedValue('region_id', $regionId);

    return $result;
}

/**
 * Validate user profile completion data
 * Used for profile completion modal
 *
 * @param array $data User input data
 * @return ValidationResult Validation result with sanitized data or errors
 */
function validate_user_profile_data(array $data): ValidationResult
{
    $result = new ValidationResult();

    // First Name
    $firstName = sanitize_string($data['first_name'] ?? '', 80);
    if (empty($firstName)) {
        $result->addError('first_name', 'First name is required');
    } elseif (!validate_length($firstName, 1, 80)) {
        $result->addError('first_name', 'First name must be 1-80 characters');
    } else {
        $result->setSanitizedValue('first_name', $firstName);
    }

    // Last Name
    $lastName = sanitize_string($data['last_name'] ?? '', 80);
    if (empty($lastName)) {
        $result->addError('last_name', 'Last name is required');
    } elseif (!validate_length($lastName, 1, 80)) {
        $result->addError('last_name', 'Last name must be 1-80 characters');
    } else {
        $result->setSanitizedValue('last_name', $lastName);
    }

    // Username (Display Name)
    $username = sanitize_string($data['username'] ?? '', 80);
    if (empty($username)) {
        $result->addError('username', 'Username is required');
    } elseif (!validate_length($username, 3, 50)) {
        $result->addError('username', 'Username must be 3-50 characters');
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $result->addError('username', 'Username can only contain letters, numbers, dots, underscores, and hyphens');
    } else {
        $result->setSanitizedValue('username', $username);
    }

    // Region ID
    $regionId = sanitize_int($data['region_id'] ?? '0');
    if ($regionId <= 0) {
        $result->addError('region_id', 'Please select a region');
    } else {
        // Verify region exists and allows registration
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM regions WHERE id = ? AND allow_registration = 1');
        $stmt->execute([$regionId]);
        if (!$stmt->fetch()) {
            $result->addError('region_id', 'Invalid region selected');
        } else {
            $result->setSanitizedValue('region_id', $regionId);
        }
    }

    return $result;
}

// Database transaction helpers
/**
 * @param callable(PDO): mixed $callback
 */
function execute_transaction(callable $callback, string $context = 'transaction'): bool
{
    $startTime = microtime(true);
    $pdo = db();
    $maxRetries = 3;
    $retryDelay = 100; // milliseconds

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $pdo->beginTransaction();

            $result = $callback($pdo);

            $pdo->commit();

            // Log performance
            $duration = microtime(true) - $startTime;
            Logger::performance($context, $duration, [
                'attempt' => $attempt,
                'success' => true
            ]);

            return $result !== false;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Check if this is a retryable error
            if ($attempt < $maxRetries && is_retryable_error($e)) {
                Logger::error($context, $e, ['attempt' => $attempt, 'retrying' => true]);
                usleep($retryDelay * 1000 * $attempt); // Exponential backoff
                continue;
            }

            // Log final error with performance data
            $duration = microtime(true) - $startTime;
            Logger::error($context, $e, [
                'attempt' => $attempt,
                'final_failure' => true,
                'duration' => $duration
            ]);
            throw $e;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $duration = microtime(true) - $startTime;
            Logger::error($context, $e, [
                'attempt' => $attempt,
                'duration' => $duration
            ]);
            throw $e;
        }
    }

    return false;
}

function is_retryable_error(PDOException $e): bool
{
    $retryableCodes = [
        '40001', // Serialization failure
        '40P01', // Deadlock detected
        '08003', // Connection does not exist
        '08006', // Connection failure
        '08001', // Unable to connect
        '08004', // Connection rejected
    ];

    return in_array($e->getCode(), $retryableCodes, true) ||
           strpos($e->getMessage(), 'Deadlock') !== false ||
           strpos($e->getMessage(), 'Lock wait timeout') !== false ||
           strpos($e->getMessage(), 'Connection') !== false;
}

function safe_execute_query(string $sql, array $params = [], string $context = 'query'): PDOStatement
{
    $startTime = microtime(true);
    $pdo = db();
    $maxRetries = 3;
    $retryDelay = 50; // milliseconds

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Log performance for slow queries
            $duration = microtime(true) - $startTime;
            if ($duration > 1.0) { // Log queries taking more than 1 second
                Logger::performance($context, $duration, [
                    'sql' => $sql,
                    'params_count' => count($params),
                    'attempt' => $attempt
                ]);
            }

            return $stmt;
        } catch (PDOException $e) {
            // Check if this is a retryable error
            if ($attempt < $maxRetries && is_retryable_error($e)) {
                Logger::error($context, $e, ['attempt' => $attempt, 'retrying' => true]);
                usleep($retryDelay * 1000 * $attempt); // Exponential backoff
                continue;
            }

            // Log final error
            $duration = microtime(true) - $startTime;
            Logger::error($context, $e, [
                'sql' => $sql,
                'params_count' => count($params),
                'attempt' => $attempt,
                'duration' => $duration
            ]);
            throw $e;
        }
    }

    throw new Exception("Failed to execute query after {$maxRetries} attempts");
}

// Enhanced error boundary handling
class ErrorBoundary
{
    private static array $errorHandlers = [];
    private static array $recoveryStrategies = [];

    /**
     * @param callable(never, string, array): mixed $handler  handlers may narrow the exception type
     */
    public static function registerHandler(string $errorType, callable $handler): void
    {
        self::$errorHandlers[$errorType] = $handler;
    }

    /**
     * @param callable(never, string, array): mixed $strategy  strategies may narrow the exception type
     */
    public static function registerRecoveryStrategy(string $operation, callable $strategy): void
    {
        self::$recoveryStrategies[$operation] = $strategy;
    }

    public static function handleError(Exception $e, string $context = '', array $options = []): void
    {
        $errorType = get_class($e);

        // Log the error
        Logger::error($context, $e, $options);

        // Try registered handler first
        if (isset(self::$errorHandlers[$errorType])) {
            try {
                call_user_func(self::$errorHandlers[$errorType], $e, $context, $options);
                return;
            } catch (Exception $handlerException) {
                Logger::error('error_handler_failed', $handlerException, [
                    'original_error' => $e->getMessage(),
                    'context' => $context
                ]);
            }
        }

        // Try recovery strategy
        if (isset($options['operation']) && isset(self::$recoveryStrategies[$options['operation']])) {
            try {
                call_user_func(self::$recoveryStrategies[$options['operation']], $e, $context, $options);
                return;
            } catch (Exception $recoveryException) {
                Logger::error('recovery_strategy_failed', $recoveryException, [
                    'original_error' => $e->getMessage(),
                    'context' => $context
                ]);
            }
        }

        // Default error handling
        self::defaultErrorHandler($e, $context, $options);
    }

    private static function defaultErrorHandler(Exception $e, string $context, array $options): void
    {
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
                 !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                 (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isApi) {
            // API error response
            $message = env('APP_ENV') === 'dev' ? $e->getMessage() : 'An error occurred. Please try again.';
            api_error($message, 500, ['context' => $context]);
        } else {
            // Web page error handling
            $message = env('APP_ENV') === 'dev' ? $e->getMessage() : 'An error occurred. Please try again.';
            flash_message($message, 'error');

            // Redirect to safe page
            $fallbackUrl = $options['fallback_url'] ?? '/index.php';
            redirect($fallbackUrl);
        }
    }

    /**
     * @param callable(): mixed $operation
     */
    public static function wrap(callable $operation, string $context = '', array $options = []): mixed
    {
        try {
            return $operation();
        } catch (Exception $e) {
            self::handleError($e, $context, $options);
            return $options['default_value'] ?? null;
        }
    }
}

// Enhanced validation framework
class Validator
{
    private array $rules = [];
    private array $messages = [];
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function rule(string $field, array $rules, string $message = ''): self
    {
        $this->rules[$field] = $rules;
        if ($message) {
            $this->messages[$field] = $message;
        }
        return $this;
    }

    public function validate(): ValidationResult
    {
        $result = new ValidationResult();

        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? '';

            foreach ($rules as $rule => $params) {
                if (is_numeric($rule)) {
                    // Simple rule without parameters
                    $rule = $params;
                    $params = [];
                }

                if (!$this->validateRule($value, $rule, $params)) {
                    $message = $this->messages[$field] ?? $this->getDefaultMessage($field, $rule, $params);
                    $result->addError($field, $message);
                    break; // Stop at first error for this field
                }
            }

            // If validation passed, add sanitized value
            if (!isset($result->errors[$field])) {
                $sanitized = $this->sanitizeValue($value, $field);
                $result->setSanitizedValue($field, $sanitized);
            }
        }

        return $result;
    }

    private function validateRule(mixed $value, string $rule, array $params): bool
    {
        switch ($rule) {
            case 'required':
                return validate_required($value);

            case 'email':
                return validate_email($value);

            case 'length':
                $min = $params[0] ?? 0;
                $max = $params[1] ?? 255;
                return validate_length($value, $min, $max);

            case 'int':
                $min = $params[0] ?? null;
                $max = $params[1] ?? null;
                return validate_int($value, $min, $max);

            case 'product_id':
                return validate_product_id($value);

            case 'in':
                return in_array($value, $params, true);

            case 'regex':
                return preg_match($params[0], $value) === 1;

            case 'unique':
                return $this->validateUnique($value, $params[0], $params[1] ?? null);

            case 'exists':
                return $this->validateExists($value, $params[0], $params[1] ?? 'id');

            default:
                Logger::error('unknown_validation_rule', new Exception("Unknown validation rule: {$rule}"), [
                    'rule' => $rule,
                    'field' => array_search($rule, $this->rules) ?: 'unknown'
                ]);
                return true; // Don't fail validation for unknown rules
        }
    }

    private function validateUnique(mixed $value, string $table, ?string $excludeId = null): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE id = ?";
            $params = [$value];

            if ($excludeId) {
                $sql .= " AND id != ?";
                $params[] = $excludeId;
            }

            $stmt = safe_execute_query($sql, $params, 'unique_validation');
            return $stmt->fetchColumn() == 0;
        } catch (Exception $e) {
            Logger::error('unique_validation_failed', $e, ['table' => $table, 'value' => $value]);
            return true; // Don't fail validation if we can't check
        }
    }

    private function validateExists(mixed $value, string $table, string $column = 'id'): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
            $stmt = safe_execute_query($sql, [$value], 'exists_validation');
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            Logger::error('exists_validation_failed', $e, ['table' => $table, 'column' => $column, 'value' => $value]);
            return true; // Don't fail validation if we can't check
        }
    }

    private function getDefaultMessage(string $field, string $rule, array $params): string
    {
        $fieldName = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                return "{$fieldName} is required";
            case 'email':
                return "{$fieldName} must be a valid email address";
            case 'length':
                $min = $params[0] ?? 0;
                $max = $params[1] ?? 255;
                return "{$fieldName} must be between {$min} and {$max} characters";
            case 'int':
                return "{$fieldName} must be a valid number";
            case 'product_id':
                return "{$fieldName} can only contain letters, numbers, dashes, and underscores";
            case 'in':
                return "{$fieldName} must be one of: " . implode(', ', $params);
            case 'unique':
                return "{$fieldName} already exists";
            case 'exists':
                return "{$fieldName} does not exist";
            default:
                return "{$fieldName} is invalid";
        }
    }

    private function sanitizeValue(mixed $value, string $field): mixed
    {
        // Apply field-specific sanitization
        switch ($field) {
            case 'email':
                return sanitize_email($value);
            case 'product_id':
            case 'name':
            case 'manufacturer':
            case 'color':
            case 'status':
                return sanitize_string($value);
            case 'quantity':
            case 'store_id':
            case 'region_id':
                return sanitize_int($value);
            case 'price':
                return sanitize_float($value);
            default:
                return is_string($value) ? sanitize_string($value) : $value;
        }
    }
}

// Validation helper functions for common patterns
function validate_form_data(array $data, array $rules): ValidationResult
{
    $validator = new Validator($data);

    foreach ($rules as $field => $fieldRules) {
        $message = '';
        if (is_array($fieldRules) && isset($fieldRules['message'])) {
            $message = $fieldRules['message'];
            unset($fieldRules['message']);
        }
        $validator->rule($field, $fieldRules, $message);
    }

    return $validator->validate();
}

function api_validate_input(array $rules): array
{
    $result = validate_form_data($_POST, $rules);

    if ($result->hasErrors()) {
        api_error('Validation failed', 400, ['errors' => $result->getErrors()]);
    }

    return $result->getSanitizedData();
}

function validate_file_upload(string $fieldName, array $allowedTypes = ['image/jpeg', 'image/png'], int $maxSize = 10485760): ValidationResult
{
    $result = new ValidationResult();

    if (!isset($_FILES[$fieldName])) {
        $result->addError($fieldName, 'No file uploaded');
        return $result;
    }

    return validate_uploaded_file($_FILES[$fieldName], $allowedTypes, $maxSize);
}

// Additional validation utilities for consistent validation result handling
function handle_validation_result(ValidationResult $result, string $fallbackUrl = '/index.php'): void
{
    if ($result->hasErrors()) {
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

        if ($isApi) {
            api_error('Validation failed', 400, ['errors' => $result->getErrors()]);
        } else {
            foreach ($result->getErrors() as $field => $message) {
                flash_message($message, 'error');
            }
            redirect($fallbackUrl);
        }
    }
}

function get_validated_data(ValidationResult $result): array
{
    if ($result->hasErrors()) {
        throw new InvalidArgumentException('Cannot get data from failed validation result');
    }

    return $result->getSanitizedData();
}

// Enhanced form validation patterns
function validate_and_sanitize_post_data(array $rules, string $fallbackUrl = '/index.php'): array
{
    $result = validate_form_data($_POST, $rules);
    handle_validation_result($result, $fallbackUrl);
    return get_validated_data($result);
}

function validate_csrf_and_auth(): void
{
    require_login();
    validate_csrf_token();
}

function validate_admin_csrf_and_auth(): void
{
    require_login();
    require_admin();
    validate_csrf_token();
}

// Input sanitization utilities
class Sanitizer
{
    public static function cleanString(string $input, int $maxLength = 255): string
    {
        $cleaned = trim($input);
        $cleaned = strip_tags($cleaned);
        $cleaned = htmlspecialchars($cleaned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return mb_substr($cleaned, 0, $maxLength);
    }

    public static function cleanArray(array $input, array $allowedKeys = []): array
    {
        $cleaned = [];

        foreach ($input as $key => $value) {
            // Only include allowed keys if specified
            if (!empty($allowedKeys) && !in_array($key, $allowedKeys, true)) {
                continue;
            }

            if (is_string($value)) {
                $cleaned[$key] = self::cleanString($value);
            } elseif (is_array($value)) {
                $cleaned[$key] = self::cleanArray($value, $allowedKeys);
            } elseif (is_int($value) || is_float($value)) {
                $cleaned[$key] = $value;
            } elseif (is_bool($value)) {
                $cleaned[$key] = $value;
            }
            // Skip other types (objects, resources, etc.)
        }

        return $cleaned;
    }

    public static function sanitizeForDatabase(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = trim($value);
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value ? 1 : 0;
            } elseif (is_null($value)) {
                $sanitized[$key] = null;
            } else {
                // Convert other types to string
                $sanitized[$key] = (string)$value;
            }
        }

        return $sanitized;
    }
}

// Register default error handlers
ErrorBoundary::registerHandler('PDOException', function (PDOException $e, string $context, array $options) {
    // Handle database errors specifically
    Logger::error('database_error', $e, array_merge($options, ['context' => $context]));

    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApi) {
        api_error('Database operation failed. Please try again.', 500);
    } else {
        flash_message('Database operation failed. Please try again.', 'error');
        redirect($options['fallback_url'] ?? '/index.php');
    }
});

ErrorBoundary::registerHandler('InvalidArgumentException', function (InvalidArgumentException $e, string $context, array $options) {
    Logger::error('invalid_argument', $e, array_merge($options, ['context' => $context]));

    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApi) {
        api_error('Invalid input provided.', 400);
    } else {
        flash_message('Invalid input provided. Please check your data and try again.', 'error');
        redirect($options['fallback_url'] ?? '/index.php');
    }
});

// Register recovery strategies
ErrorBoundary::registerRecoveryStrategy('file_upload', function (Exception $e, string $context, array $options) {
    // Clean up any partial uploads
    if (isset($options['temp_file']) && file_exists($options['temp_file'])) {
        @unlink($options['temp_file']);
    }

    Logger::error('file_upload_recovery', $e, $options);

    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApi) {
        api_error('File upload failed. Please try again.', 500);
    } else {
        flash_message('File upload failed. Please try again.', 'error');
        redirect($options['fallback_url'] ?? '/index.php');
    }
});

ErrorBoundary::registerRecoveryStrategy('stock_operation', function (Exception $e, string $context, array $options) {
    // Log stock operation failure for audit
    Logger::security('stock_operation_failed', [
        'user_id' => $_SESSION['uid'] ?? null,
        'operation' => $options['operation'] ?? 'unknown',
        'product_id' => $options['product_id'] ?? null,
        'error' => $e->getMessage()
    ]);

    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApi) {
        api_error('Stock operation failed. Please try again.', 500);
    } else {
        flash_message('Stock operation failed. Please try again.', 'error');
        redirect($options['fallback_url'] ?? '/stock.php');
    }
});

// Enhanced database connection with retry logic
function db_with_retry(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        // Test connection
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (PDOException $e) {
            // Connection lost, reset and reconnect
            $pdo = null;
        }
    }

    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);

    $maxRetries = 3;
    $retryDelay = 1000; // milliseconds

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $pdo = new PDO($dsn, $DB_USER, $DB_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'"
            ] + db_tls_options());

            return $pdo;
        } catch (PDOException $e) {
            if ($attempt < $maxRetries) {
                log_error('database_connection', $e, ['attempt' => $attempt, 'retrying' => true]);
                usleep($retryDelay * 1000);
                continue;
            }

            log_error('database_connection', $e, ['attempt' => $attempt, 'final_failure' => true]);
            throw $e;
        }
    }

    throw new Exception('Failed to establish database connection after retries');
}

// Stock operation with proper transaction handling
function update_stock_with_transaction(int $productId, int $userId, int $storeId, int $quantityChange, string $reason, string $note = ''): bool
{
    return execute_transaction(function ($pdo) use ($productId, $userId, $storeId, $quantityChange, $reason, $note) {
        // Check current stock for 'use' operations
        if ($reason === 'use' && $quantityChange < 0) {
            $stmt = $pdo->prepare('SELECT COALESCE(quantity, 0) FROM van_stock WHERE product_id = ? AND user_id = ? AND store_id = ?');
            $stmt->execute([$productId, $userId, $storeId]);
            $currentStock = (int)$stmt->fetchColumn();

            if ($currentStock + $quantityChange < 0) {
                throw new Exception('Insufficient stock. Current: ' . $currentStock . ', Requested: ' . abs($quantityChange));
            }
        }

        // Update or insert van_stock
        $stmt = $pdo->prepare('
            INSERT INTO van_stock (product_id, user_id, store_id, quantity) 
            VALUES (?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity + VALUES(quantity))
        ');
        $stmt->execute([$productId, $userId, $storeId, max(0, $quantityChange)]);

        // Get store name for transaction record
        $stmt = $pdo->prepare('SELECT name FROM stores WHERE id = ?');
        $stmt->execute([$storeId]);
        $storeName = $stmt->fetchColumn();

        // Insert transaction record
        $stmt = $pdo->prepare('
            INSERT INTO transactions (product_id, user_id, change_qty, reason, store_id, store, note) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$productId, $userId, $quantityChange, $reason, $storeId, $storeName, $note ?: null]);

        return true;
    }, 'update_stock');
}

// Product creation with proper transaction handling
function create_product_with_transaction(array $productData, int $userId): int
{
    $productId = 0;
    $committed = execute_transaction(function ($pdo) use ($productData, $userId, &$productId) {
        // Insert product
        $stmt = $pdo->prepare('
            INSERT INTO products (product_id, name, user_id, image_path, asset_type, manufacturer, status, color, order_number) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $productData['product_id'],
            $productData['name'],
            $userId,
            $productData['image_path'] ?? null,
            $productData['asset_type'] ?? null,
            $productData['manufacturer'] ?? null,
            $productData['status'] ?? null,
            $productData['color'] ?? null,
            $productData['order_number'] ?? null
        ]);

        $productId = (int)$pdo->lastInsertId();

        // Initialize van_stock row
        $stmt = $pdo->prepare('INSERT INTO van_stock (product_id, user_id, store_id, quantity) VALUES (?, ?, 1, 0)');
        $stmt->execute([$productId, $userId]);

        return true;
    }, 'create_product');

    return $committed ? $productId : 0;
}

// Form success handling
function handle_form_success(string $message, string $redirectUrl = null): void
{
    flash_message($message, 'success');

    // Log successful form submission
    Logger::info('form_success', $message, [
        'redirect_url' => $redirectUrl,
        'is_ajax' => !empty($_SERVER['HTTP_X_REQUESTED_WITH']),
        'user_id' => $_SESSION['uid'] ?? null
    ]);

    // If this is an AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        api_success(['redirect' => $redirectUrl], $message);
    }

    // Regular form submission
    if ($redirectUrl) {
        redirect($redirectUrl);
    }
}

function handle_form_error(string $message, string $redirectUrl = null): void
{
    flash_message($message, 'error');

    // If this is an AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        api_error($message, 400);
    }

    // Regular form submission
    if ($redirectUrl) {
        redirect($redirectUrl);
    }
}

// Enhanced flash message with auto-dismiss
function flash_with_autodismiss(string $message, string $type = 'info', int $duration = 5000): void
{
    $_SESSION['flash'][] = [$type, $message, $duration];
}

// Session monitoring and cleanup
function get_active_sessions_count(): int
{
    // This would typically query a sessions table in production
    // For now, we'll use a simple file-based approach
    $sessionDir = session_save_path() ?: sys_get_temp_dir();
    $sessionFiles = glob($sessionDir . '/sess_*');
    return count($sessionFiles ?: []);
}

function cleanup_expired_sessions(): int
{
    $cleaned = 0;
    $sessionDir = session_save_path() ?: sys_get_temp_dir();
    $sessionFiles = glob($sessionDir . '/sess_*');
    $maxLifetime = (int)ini_get('session.gc_maxlifetime');

    if ($sessionFiles) {
        foreach ($sessionFiles as $file) {
            // Check if file exists and is readable before trying to get modification time
            if (!file_exists($file) || !is_readable($file)) {
                continue;
            }

            try {
                if (filemtime($file) < (time() - $maxLifetime)) {
                    // Check if file is writable before trying to delete
                    if (is_writable($file) && unlink($file)) {
                        $cleaned++;
                    }
                }
            } catch (Exception $e) {
                // Silently continue if we can't access or delete the file
                // This prevents permission errors from breaking the application
                continue;
            }
        }
    }

    if ($cleaned > 0) {
        Logger::info('session_cleanup', "Cleaned up $cleaned expired sessions");
    }

    return $cleaned;
}

// Security headers
const SECURITY_PERMISSIONS_POLICY = 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), serial=(), hid=(), '
    . 'bluetooth=(), display-capture=(), accelerometer=(), gyroscope=(), magnetometer=()';

function set_security_headers(): void
{
    // Prevent clickjacking
    header('X-Frame-Options: DENY');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Browser features: only the camera (barcode scanning) for this site, nothing else (audit H12). The deprecated
    // X-XSS-Protection header is not sent; the CSP below does that job.
    header('Permissions-Policy: ' . SECURITY_PERMISSIONS_POLICY);

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy (basic)
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' https://unpkg.com; " .
           "style-src 'self' 'unsafe-inline'; " .
           "img-src 'self' data:; " .
           "font-src 'self'; " .
           "connect-src 'self'";
    // header("Content-Security-Policy: $csp");

    // HTTPS enforcement in production
    if (app_is_production()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Set security headers for all requests
set_security_headers();

// Periodic session cleanup (run occasionally)
if (rand(1, 1000) === 1) { // 0.1% chance - reduced frequency to minimize permission issues
    cleanup_expired_sessions();
}

// File operations helper class
class FileManager
{
    private static string $uploadDir = '/var/www/uploads';
    private static int $maxFileSize = 10485760; // 10MB
    /** Accepted types and the extension a stored file of that type gets (never the client's extension: audit H8). */
    private static array $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
    ];

    public static function init(): void
    {
        // Skip initialization in non-web contexts (CLI, Composer, CI)
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        if (!is_dir(self::$uploadDir)) {
            if (!@mkdir(self::$uploadDir, 0755, true)) {
                // Log error but don't throw in non-critical contexts
                if (function_exists('Logger::error')) {
                    Logger::error('file_system', new Exception('Failed to create upload directory'), [
                        'directory' => self::$uploadDir,
                        'permissions' => '0755',
                        'context' => 'initialization'
                    ]);
                }
                // Only throw if we're in a web context where uploads are needed
                if (isset($_SERVER['REQUEST_URI'])) {
                    throw new Exception('Upload directory could not be created');
                }
                return; // Silently fail in CLI/composer context
            }
        }

        // Check directory permissions (only warn if not writable)
        if (is_dir(self::$uploadDir) && !is_writable(self::$uploadDir)) {
            if (function_exists('Logger::error') && isset($_SERVER['REQUEST_URI'])) {
                Logger::error('file_system', new Exception('Upload directory is not writable'), [
                    'directory' => self::$uploadDir,
                    'current_permissions' => substr(sprintf('%o', fileperms(self::$uploadDir)), -4)
                ]);
                throw new Exception('Upload directory is not writable');
            }
        }
    }

    public static function uploadFile(array $file, string $context = 'upload'): array
    {
        $startTime = microtime(true);

        try {
            // Validate file upload
            $validation = self::validateUpload($file);
            if (!$validation['success']) {
                return $validation;
            }

            // Generate secure filename; the extension follows the detected type
            $originalName = $file['name'];
            $extension = self::$allowedMimeTypes[$validation['mime']];
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = self::$uploadDir . '/' . $filename;

            // Check available disk space
            $freeSpace = disk_free_space(self::$uploadDir);
            if ($freeSpace < ($file['size'] * 2)) { // Require 2x file size free space
                Logger::error('file_upload', new Exception('Insufficient disk space'), [
                    'required_space' => $file['size'] * 2,
                    'available_space' => $freeSpace,
                    'context' => $context
                ]);
                return ['success' => false, 'error' => 'Insufficient disk space'];
            }

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                Logger::error('file_upload', new Exception('Failed to move uploaded file'), [
                    'source' => $file['tmp_name'],
                    'destination' => $destination,
                    'context' => $context
                ]);
                return ['success' => false, 'error' => 'Failed to save uploaded file'];
            }

            // Set proper permissions
            if (!chmod($destination, 0644)) {
                Logger::error('file_upload', new Exception('Failed to set file permissions'), [
                    'file' => $destination,
                    'context' => $context
                ]);
            }

            // Log successful upload
            $duration = microtime(true) - $startTime;
            Logger::performance('file_upload', $duration, [
                'filename' => $filename,
                'original_name' => $originalName,
                'size' => $file['size'],
                'context' => $context
            ]);

            return [
                'success' => true,
                'filename' => $filename,
                'path' => '/uploads/' . $filename,
                'size' => $file['size'],
                'original_name' => $originalName
            ];
        } catch (Exception $e) {
            Logger::error('file_upload', $e, [
                'file_info' => [
                    'name' => $file['name'] ?? 'unknown',
                    'size' => $file['size'] ?? 0,
                    'type' => $file['type'] ?? 'unknown'
                ],
                'context' => $context
            ]);

            return ['success' => false, 'error' => 'File upload failed: ' . $e->getMessage()];
        }
    }

    public static function deleteFile(string $filename, string $context = 'delete'): bool
    {
        try {
            $filePath = self::$uploadDir . '/' . basename($filename);

            if (!file_exists($filePath)) {
                Logger::info('file_delete', 'File not found for deletion', [
                    'filename' => $filename,
                    'context' => $context
                ]);
                return true; // File doesn't exist, consider it deleted
            }

            // Check if file is in use (basic check)
            if (is_resource($handle = @fopen($filePath, 'r+'))) {
                fclose($handle);
            } else {
                Logger::error('file_delete', new Exception('File is in use or locked'), [
                    'filename' => $filename,
                    'context' => $context
                ]);
                return false;
            }

            if (!unlink($filePath)) {
                Logger::error('file_delete', new Exception('Failed to delete file'), [
                    'filename' => $filename,
                    'file_path' => $filePath,
                    'context' => $context
                ]);
                return false;
            }

            Logger::info('file_delete', 'File deleted successfully', [
                'filename' => $filename,
                'context' => $context
            ]);

            return true;
        } catch (Exception $e) {
            Logger::error('file_delete', $e, [
                'filename' => $filename,
                'context' => $context
            ]);
            return false;
        }
    }

    public static function getFileInfo(string $filename): array
    {
        try {
            $filePath = self::$uploadDir . '/' . basename($filename);

            if (!file_exists($filePath)) {
                return ['exists' => false];
            }

            $info = [
                'exists' => true,
                'size' => filesize($filePath),
                'modified' => filemtime($filePath),
                'permissions' => substr(sprintf('%o', fileperms($filePath)), -4),
                'mime_type' => mime_content_type($filePath),
                'is_readable' => is_readable($filePath),
                'is_writable' => is_writable($filePath)
            ];

            // For images, get dimensions
            if (strpos($info['mime_type'], 'image/') === 0) {
                $imageInfo = getimagesize($filePath);
                if ($imageInfo) {
                    $info['width'] = $imageInfo[0];
                    $info['height'] = $imageInfo[1];
                }
            }

            return $info;
        } catch (Exception $e) {
            Logger::error('file_info', $e, ['filename' => $filename]);
            return ['exists' => false, 'error' => $e->getMessage()];
        }
    }

    public static function cleanupOldFiles(int $maxAge = 2592000): int
    {
 // 30 days default
        $cleaned = 0;

        try {
            $files = glob(self::$uploadDir . '/*');
            $cutoffTime = time() - $maxAge;

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }

            if ($cleaned > 0) {
                Logger::info('file_cleanup', "Cleaned up $cleaned old files", [
                    'max_age_days' => $maxAge / 86400,
                    'cleaned_count' => $cleaned
                ]);
            }
        } catch (Exception $e) {
            Logger::error('file_cleanup', $e);
        }

        return $cleaned;
    }

    public static function getDiskUsage(): array
    {
        try {
            $totalSpace = disk_total_space(self::$uploadDir);
            $freeSpace = disk_free_space(self::$uploadDir);
            $usedSpace = $totalSpace - $freeSpace;

            return [
                'total' => $totalSpace,
                'free' => $freeSpace,
                'used' => $usedSpace,
                'usage_percent' => ($usedSpace / $totalSpace) * 100
            ];
        } catch (Exception $e) {
            Logger::error('disk_usage', $e);
            return ['error' => $e->getMessage()];
        }
    }

    private static function validateUpload(array $file): array
    {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];

            $message = $errorMessages[$file['error']] ?? 'Unknown upload error';
            return ['success' => false, 'error' => $message];
        }

        // Check file size
        if ($file['size'] > self::$maxFileSize) {
            return [
                'success' => false,
                'error' => 'File too large. Maximum size: ' . number_format(self::$maxFileSize / 1024 / 1024, 1) . 'MB'
            ];
        }

        // Check MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!isset(self::$allowedMimeTypes[$mimeType])) {
            return [
                'success' => false,
                'error' => 'Invalid file type. Allowed types: ' . implode(', ', array_keys(self::$allowedMimeTypes))
            ];
        }

        // Additional validation for images
        if (strpos($mimeType, 'image/') === 0) {
            $imageInfo = getimagesize($file['tmp_name']);
            if (!$imageInfo) {
                return ['success' => false, 'error' => 'Invalid image file'];
            }

            // Check image dimensions
            if ($imageInfo[0] > 5000 || $imageInfo[1] > 5000) {
                return ['success' => false, 'error' => 'Image dimensions too large (max 5000x5000)'];
            }
        }

        return ['success' => true, 'mime' => $mimeType];
    }
}

// Initialize file manager
FileManager::init();

// Periodic cleanup of old files (run occasionally)
if (rand(1, 1000) === 1) { // 0.1% chance
    FileManager::cleanupOldFiles();
}

// Ensure uploads dir exists (legacy support)
$uploadDir = '/var/www/uploads';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
// ============================================================================
// NOTIFICATION SYSTEM HELPER FUNCTIONS
// ============================================================================

/**
 * Enhanced notification system that integrates with QuackNotify JavaScript API
 * Provides server-side flash messages that display as styled notifications
 */

/**
 * Display a notification using the QuackNotify system
 * This function sets a session flash message that will be automatically
 * converted to a JavaScript notification on the next page load
 *
 * @param string $message The notification message
 * @param string $type Notification type: 'success', 'error', 'warning', 'info'
 * @param array $options Optional configuration for the notification
 */
function notify(string $message, string $type = 'info', array $options = []): void
{
    // Validate notification type
    $validTypes = ['success', 'error', 'warning', 'info'];
    if (!in_array($type, $validTypes)) {
        $type = 'info';
    }

    // Set default options
    $defaultOptions = [
        'duration' => 5000,
        'persistent' => false,
        'actions' => []
    ];
    $options = array_merge($defaultOptions, $options);

    // Store notification in session for display on next page load
    if (!isset($_SESSION['notifications'])) {
        $_SESSION['notifications'] = [];
    }

    $_SESSION['notifications'][] = [
        'message' => $message,
        'type' => $type,
        'options' => $options,
        'timestamp' => time()
    ];

    // Also use the existing flash system for backward compatibility
    flash($message, $type);
}

/**
 * Display a success notification
 */
function notify_success(string $message, array $options = []): void
{
    notify($message, 'success', $options);
}

/**
 * Display an error notification
 */
function notify_error(string $message, array $options = []): void
{
    // Error notifications should be persistent by default
    $options = array_merge(['persistent' => true], $options);
    notify($message, 'error', $options);
}

/**
 * Display a warning notification
 */
function notify_warning(string $message, array $options = []): void
{
    notify($message, 'warning', $options);
}

/**
 * Display an info notification
 */
function notify_info(string $message, array $options = []): void
{
    notify($message, 'info', $options);
}

/**
 * Get all pending notifications and clear them from session
 * This function is called by the JavaScript to retrieve server-side notifications
 *
 * @return array Array of notification objects
 */
function get_notifications(): array
{
    $notifications = $_SESSION['notifications'] ?? [];
    unset($_SESSION['notifications']);
    return $notifications;
}

/**
 * Check if there are pending notifications
 *
 * @return bool True if there are notifications waiting to be displayed
 */
function has_notifications(): bool
{
    return !empty($_SESSION['notifications']);
}

/**
 * Clear all pending notifications without displaying them
 */
function clear_notifications(): void
{
    unset($_SESSION['notifications']);
}

/**
 * Add a confirmation dialog notification
 * This creates a special notification that will trigger a confirmation dialog
 *
 * @param string $message The confirmation message
 * @param string $confirmText Text for the confirm button (default: "Confirm")
 * @param string $cancelText Text for the cancel button (default: "Cancel")
 * @param array $options Additional options for the confirmation
 */
function notify_confirm(string $message, string $confirmText = 'Confirm', string $cancelText = 'Cancel', array $options = []): void
{
    $confirmOptions = array_merge([
        'type' => 'confirmation',
        'confirmText' => $confirmText,
        'cancelText' => $cancelText,
        'confirmStyle' => 'danger'
    ], $options);

    if (!isset($_SESSION['notifications'])) {
        $_SESSION['notifications'] = [];
    }

    $_SESSION['notifications'][] = [
        'message' => $message,
        'type' => 'confirmation',
        'options' => $confirmOptions,
        'timestamp' => time()
    ];
}

/**
 * Generate JavaScript code to display notifications
 * This function outputs JavaScript that will display all pending notifications
 * Should be called in the page template after QuackNotify is loaded
 *
 * @return string JavaScript code to display notifications
 */
function render_notifications_js(): string
{
    $notifications = get_notifications();

    if (empty($notifications)) {
        return '';
    }

    $js = "<script>\n";
    $js .= "document.addEventListener('DOMContentLoaded', function() {\n";
    $js .= "  if (typeof QuackNotify !== 'undefined') {\n";

    foreach ($notifications as $notification) {
        $message = json_encode($notification['message']);
        $type = json_encode($notification['type']);
        $options = json_encode($notification['options']);

        if ($notification['type'] === 'confirmation') {
            $js .= "    QuackNotify.confirm({$message}, {$options});\n";
        } else {
            $js .= "    QuackNotify.show({$message}, {$type}, {$options});\n";
        }
    }

    $js .= "  } else {\n";
    $js .= "    console.warn('QuackNotify not loaded, falling back to alerts');\n";

    // Fallback to browser alerts if QuackNotify is not available
    foreach ($notifications as $notification) {
        if ($notification['type'] !== 'confirmation') {
            $message = json_encode($notification['message']);
            $js .= "    alert({$message});\n";
        }
    }

    $js .= "  }\n";
    $js .= "});\n";
    $js .= "</script>\n";

    return $js;
}

/**
 * Enhanced flash_message function that uses the notification system
 * This replaces the existing flash_message function to use notifications
 * while maintaining backward compatibility
 */
function flash_message_enhanced(string $message, string $type = 'info'): void
{
    notify($message, $type);
}

/**
 * API helper to send notifications in JSON responses
 * Use this in API endpoints to include notification data in responses
 *
 * @param string $message The notification message
 * @param string $type Notification type
 * @param array $options Notification options
 * @return array Notification data for JSON response
 */
function api_notification(string $message, string $type = 'info', array $options = []): array
{
    return [
        'notification' => [
            'message' => $message,
            'type' => $type,
            'options' => array_merge([
                'duration' => 5000,
                'persistent' => false
            ], $options)
        ]
    ];
}

/**
 * API success response with notification
 */
function api_success_with_notification(string $message, mixed $data = null, string $notificationType = 'success', array $notificationOptions = []): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message,
        'notification' => [
            'message' => $message,
            'type' => $notificationType,
            'options' => array_merge([
                'duration' => 5000,
                'persistent' => false
            ], $notificationOptions)
        ]
    ]);
    exit;
}

/**
 * API error response with notification
 */
function api_error_with_notification(string $message, int $code = 400, array $details = [], array $notificationOptions = []): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => $code,
        'details' => $details,
        'notification' => [
            'message' => $message,
            'type' => 'error',
            'options' => array_merge([
                'persistent' => true
            ], $notificationOptions)
        ]
    ]);
    exit;
}

/**
 * Send password reset email using Resend API
 *
 * @param string $email The recipient email address
 * @param string $reset_link The password reset link
 * @return bool True if email was sent successfully, false otherwise
 */
function send_password_reset_email(string $email, string $reset_link): bool
{
    $resend_api_key = env('RESEND_API_KEY');

    if (empty($resend_api_key)) {
        error_log('RESEND_API_KEY not configured for password reset emails');
        return false;
    }

    $from_email = env('FROM_EMAIL', 'noreply@quacktrack.com');
    $from_name = env('FROM_NAME', 'Quack Track');

    $subject = 'Reset Your Quack Track Password';

    // Create HTML email content
    $html_content = generate_password_reset_email_html($reset_link);

    // Create plain text version
    $text_content = generate_password_reset_email_text($reset_link);

    $data = [
        'from' => "{$from_name} <{$from_email}>",
        'to' => [$email],
        'subject' => $subject,
        'html' => $html_content,
        'text' => $text_content
    ];

    $sent = resend_send_email($resend_api_key, $data);
    if (!$sent['ok']) {
        error_log("Password reset email failed: " . $sent['error']);
        return false;
    }

    Logger::info('password_reset_email_sent', 'Password reset email sent successfully', [
        'email' => $email,
        'http_code' => $sent['status']
    ]);

    return true;
}

/**
 * Send password reset notification email (admin-initiated)
 * This is used when an admin forces a password reset
 *
 * @param string $email User email address
 * @param string $reset_link Password reset link
 * @return bool True if email sent successfully, false otherwise
 */
function send_password_reset_notification_email(string $email, string $reset_link): bool
{
    $resend_api_key = env('RESEND_API_KEY');

    if (empty($resend_api_key)) {
        error_log('RESEND_API_KEY not configured for password reset notification emails');
        return false;
    }

    $from_email = env('FROM_EMAIL', 'noreply@quacktrack.com');
    $from_name = env('FROM_NAME', 'Quack Track');

    $subject = 'Password Reset Required - Quack Track';

    // Create HTML email content
    $html_content = generate_password_reset_notification_email_html($reset_link);

    // Create plain text version
    $text_content = generate_password_reset_notification_email_text($reset_link);

    $data = [
        'from' => "{$from_name} <{$from_email}>",
        'to' => [$email],
        'subject' => $subject,
        'html' => $html_content,
        'text' => $text_content
    ];

    $sent = resend_send_email($resend_api_key, $data);
    if (!$sent['ok']) {
        error_log("Password reset notification email failed: " . $sent['error']);
        return false;
    }

    Logger::info('password_reset_notification_email_sent', 'Password reset notification email sent successfully', [
        'email' => $email,
        'http_code' => $sent['status']
    ]);

    return true;
}

/**
 * Validate password strength according to best practices
 *
 * Requirements:
 * - Minimum 12 characters (or 8 for legacy compatibility)
 * - At least one uppercase letter
 * - At least one lowercase letter
 * - At least one number
 * - At least one special character
 * - No common passwords
 *
 * @param string $password The password to validate
 * @param bool $allow_legacy If true, allows 8-character minimum (for existing users)
 * @return array ['valid' => bool, 'errors' => string[], 'warnings' => string[]]
 */
/**
 * The first reason $password breaks the password policy, or null when it meets it. Every path that sets a password
 * (registration, reset, change, administrator create/edit, first administrator) uses this one policy (audit H5).
 */
function password_policy_error(string $password): ?string
{
    return validate_password_strength($password)['errors'][0] ?? null;
}

function validate_password_strength(string $password, bool $allow_legacy = false): array
{
    $errors = [];
    $warnings = [];

    // Minimum length check
    $min_length = $allow_legacy ? 8 : 12;

    if (strlen($password) < $min_length) {
        $errors[] = "Password must be at least {$min_length} characters long";
    } elseif ($allow_legacy && strlen($password) < 12) {
        // Password meets legacy minimum but not new standard
        $warnings[] = 'For better security, we recommend using at least 12 characters';
    }

    // Maximum length check (prevent DoS via bcrypt)
    if (strlen($password) > 72) {
        $errors[] = 'Password must be no more than 72 characters long';
    }

    // Uppercase letter check
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }

    // Lowercase letter check
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }

    // Number check
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }

    // Special character check
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character (!@#$%^&*()_+-=[]{}|;:,.<>?)';
    }

    // Common password check (top 20 most common passwords)
    $common_passwords = [
        '123456', 'password', '12345678', 'qwerty', '123456789',
        '12345', '1234', '111111', '1234567', 'dragon',
        '123123', 'baseball', 'iloveyou', 'trustno1', '1234567890',
        'sunshine', 'master', 'welcome', 'shadow', 'ashley',
        'password123', 'admin', 'root', 'letmein', 'monkey'
    ];

    $lowercase_password = strtolower($password);
    foreach ($common_passwords as $common) {
        if (strpos($lowercase_password, $common) !== false) {
            $errors[] = 'Password contains a common password pattern and is not secure';
            break;
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings
    ];
}

/**
 * Check if user must reset password
 *
 * @param int $user_id User ID to check
 * @return bool True if password reset is required
 */
function must_reset_password(int $user_id): bool
{
    try {
        // Check if column exists first (backwards compatibility)
        $pdo = db();
        $column_exists = false;
        try {
            $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_reset_password'");
            $column_exists = $check->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist yet
            $column_exists = false;
        }

        if (!$column_exists) {
            // Migration hasn't run yet, so no forced resets
            return false;
        }

        $stmt = safe_execute_query(
            'SELECT must_reset_password FROM users WHERE id = ?',
            [$user_id],
            'check_password_reset'
        );
        $result = $stmt->fetch();
        return $result && (bool)$result['must_reset_password'];
    } catch (Exception $e) {
        // On any error, don't force reset (fail open for login)
        return false;
    }
}

/**
 * Check if user needs to complete their profile
 * Returns true if any critical profile fields are NULL or empty
 *
 * @param int $user_id User ID to check
 * @return array ['needs_completion' => bool, 'missing_fields' => array]
 */
function needs_profile_completion(int $user_id): array
{
    try {
        $pdo = db();
        $stmt = safe_execute_query(
            'SELECT first_name, last_name, username, region_id FROM users WHERE id = ?',
            [$user_id],
            'check_profile_completion'
        );
        $user = $stmt->fetch();

        if (!$user) {
            return ['needs_completion' => false, 'missing_fields' => []];
        }

        $missing = [];
        if (empty($user['first_name'])) {
            $missing[] = 'first_name';
        }
        if (empty($user['last_name'])) {
            $missing[] = 'last_name';
        }
        if (empty($user['username'])) {
            $missing[] = 'username';
        }
        if (empty($user['region_id'])) {
            $missing[] = 'region_id';
        }

        return [
            'needs_completion' => !empty($missing),
            'missing_fields' => $missing
        ];
    } catch (Exception $e) {
        Logger::error('profile_completion_check_failed', $e, ['user_id' => $user_id]);
        return ['needs_completion' => false, 'missing_fields' => []];
    }
}

/**
 * Enforce password reset check on protected pages
 * Redirects to change_password.php if user must reset password
 *
 * @return void
 */
function enforce_password_reset(): void
{
    if (!is_logged_in()) {
        return; // Not logged in, let require_login() handle it
    }

    $user_id = $_SESSION['uid'];

    // Whitelist pages that don't require password reset enforcement
    $allowed_paths = [
        '/change_password.php',
        '/change-password',
        '/api/change_password.php',
        '/logout.php',
        '/logout'
    ];

    $current_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    // Check if current path is in whitelist
    foreach ($allowed_paths as $allowed) {
        if ($current_path === $allowed) {
            return;
        }
    }

    // Check if user must reset password
    if (must_reset_password($user_id)) {
        redirect('/change_password.php');
    }
}

/**
 * Generate HTML content for password reset email
 */
function generate_password_reset_email_html(string $reset_link): string
{
    $app_name = env('APP_NAME', 'Quack Track');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        /* Reset styles */
        body, table, td, p, a, li, blockquote {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }
        
        /* Main styles */
        body {
            margin: 0 !important;
            padding: 0 !important;
            background-color: #f4f7fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        
        .email-container {
            max-width: 1500px;
            margin: 0 auto;
            background-color: #ffffff;
            width: 100%;
        }
        
        .header {
            background: linear-gradient(135deg, #006A4E 0%, #004d38 100%);
            padding: 50px 50px;
            text-align: center;
        }
        
        .header h1 {
            color: #ffffff;
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header .subtitle {
            color: rgba(255,255,255,0.9);
            font-size: 16px;
            margin: 8px 0 0 0;
            font-weight: 400;
        }
        
        .content {
            padding: 40px 50px;
        }
        
        .content h2 {
            color: #2d3748;
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 20px 0;
            line-height: 1.3;
        }
        
        .content p {
            color: #4a5568;
            font-size: 16px;
            line-height: 1.6;
            margin: 0 0 20px 0;
        }
        
        .button-container {
            text-align: center;
            margin: 35px 0;
        }
        
        .button {
            display: inline-block;
            padding: 16px 32px;
            background: #006A4E;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(0, 106, 78, 0.4);
            transition: all 0.3s ease;
            border: 2px solid #006A4E;
        }
        
        .button:hover {
            background: #FFD42A !important;
            color: #111827 !important;
            border-color: #e6bf24;
            box-shadow: 0 6px 20px rgba(255, 212, 42, 0.6);
            transform: translateY(-2px);
        }
        
        .link-fallback {
            background-color: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }
        
        .link-fallback p {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #718096;
        }
        
        .link-fallback .link {
            word-break: break-all;
            color: #006A4E;
            font-size: 14px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        }
        
        .warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 4px solid #FFD42A;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }
        
        .warning-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            background-color: #FFD42A;
            border-radius: 50%;
            margin-right: 10px;
            vertical-align: middle;
            position: relative;
        }
        
        .warning-icon:before {
            content: "!";
            color: #111827;
            font-weight: bold;
            font-size: 14px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .warning p {
            margin: 0;
            color: #92400e;
            font-weight: 500;
        }
        
        .footer {
            background-color: #f7fafc;
            padding: 40px 50px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        
        .footer p {
            color: #718096;
            font-size: 14px;
            line-height: 1.5;
            margin: 0 0 10px 0;
        }
        
        .footer .company-info {
            font-weight: 600;
            color: #4a5568;
        }
        
        /* Mobile responsive */
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
                margin: 0 !important;
            }
            .content {
                padding: 30px 25px !important;
            }
            .header {
                padding: 40px 25px !important;
            }
            .footer {
                padding: 30px 25px !important;
            }
            .header h1 {
                font-size: 24px !important;
            }
            .content h2 {
                font-size: 20px !important;
            }
            .button {
                padding: 14px 28px !important;
                font-size: 15px !important;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>{$app_name}</h1>
        </div>
        
        <div class="content">
            <h2> Reset Your Password</h2>
            
            <p>Hello there!</p>
            
            <p>We received a request to reset your password for your {$app_name} account. No worries – it happens to the best of us!</p>
            
            <p>Click the button below to create a new password:</p>
            
            <div class="button-container">
                <a href="{$reset_link}" class="button">Reset My Password</a>
            </div>
            
            <div class="link-fallback">
                <p><strong>Button not working?</strong> Copy and paste this link into your browser:</p>
                <div class="link">{$reset_link}</div>
            </div>
            
            <div class="warning">
                <span class="warning-icon"></span>
                <p><strong>Important Security Notice:</strong> This link will expire in 1 hour for your security. If you didn't request this password reset, you can safely ignore this email – your account remains secure. <br> - Bob </p>
            </div>
            
        </div>
        
        <div class="footer">
            <p>This is an automated security email. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Generate plain text content for password reset email
 */
function generate_password_reset_email_text(string $reset_link): string
{
    $app_name = env('APP_NAME', 'Quack Track');

    return <<<TEXT
 {$app_name} - Reset Your Password
═══════════════════════════════════════════════════════════

Hello there!

We received a request to reset your password for your {$app_name} account. 
No worries – it happens to the best of us!

 RESET YOUR PASSWORD:
{$reset_link}

  IMPORTANT SECURITY NOTICE:
• This link will expire in 1 hour for your security
• If you didn't request this password reset, you can safely ignore this email
• Your account remains secure
• Bob





───────────────────────────────────────────────────────────
This is an automated security email.
TEXT;
}

/**
 * Generate HTML content for password reset notification email (admin-initiated)
 */
function generate_password_reset_notification_email_html(string $reset_link): string
{
    $app_name = env('APP_NAME', 'Quack Track');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Required</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #006A4E 0%, #00A86B 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }

        .content {
            padding: 40px 30px;
        }

        .content h2 {
            color: #006A4E;
            font-size: 24px;
            margin-top: 0;
            margin-bottom: 20px;
        }

        .content p {
            margin: 15px 0;
            color: #555;
        }

        .button-container {
            text-align: center;
            margin: 30px 0;
        }

        .button {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #006A4E 0%, #00A86B 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.2s;
        }

        .button:hover {
            transform: translateY(-2px);
        }

        .link-fallback {
            background-color: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
        }

        .link-fallback p {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #718096;
        }

        .link-fallback .link {
            word-break: break-all;
            color: #006A4E;
            font-size: 14px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        }

        .warning {
            background: linear-gradient(135deg, #ffe5e5 0%, #ffcccc 100%);
            border-left: 4px solid #dc3545;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }

        .warning-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            background-color: #dc3545;
            border-radius: 50%;
            margin-right: 10px;
            vertical-align: middle;
            position: relative;
        }

        .warning-icon:before {
            content: "!";
            color: #ffffff;
            font-weight: bold;
            font-size: 14px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .warning p {
            color: #721c24;
            margin: 10px 0;
        }

        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
            border-top: 1px solid #e9ecef;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>{$app_name}</h1>
        </div>

        <div class="content">
            <h2>⚠ Password Reset Required</h2>

            <p>Hello,</p>

            <p>An administrator has requested that you change your password for security reasons.</p>

            <div class="warning">
                <span class="warning-icon"></span>
                <p><strong>Action Required:</strong> You must reset your password before you can access your account again.</p>
            </div>

            <p>Click the button below to create a new, secure password:</p>

            <div class="button-container">
                <a href="{$reset_link}" class="button">Reset My Password</a>
            </div>

            <div class="link-fallback">
                <p><strong>Button not working?</strong> Copy and paste this link into your browser:</p>
                <div class="link">{$reset_link}</div>
            </div>

            <p><strong>Important:</strong> This link will expire in 1 hour for your security.</p>

            <p>If you have any questions or concerns, please contact your administrator.</p>

            <p>Best regards,<br>The {$app_name} Team</p>
        </div>

        <div class="footer">
            <p>This is an automated security email. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Generate plain text content for password reset notification email (admin-initiated)
 */
function generate_password_reset_notification_email_text(string $reset_link): string
{
    $app_name = env('APP_NAME', 'Quack Track');

    return <<<TEXT
⚠ {$app_name} - Password Reset Required
═══════════════════════════════════════════════════════════

Hello,

An administrator has requested that you change your password for security reasons.

⚠ ACTION REQUIRED: You must reset your password before you can access your account again.

RESET YOUR PASSWORD:
{$reset_link}

IMPORTANT:
• This link will expire in 1 hour for your security
• You will not be able to access your account until you reset your password
• If you have any questions or concerns, please contact your administrator

Best regards,
The {$app_name} Team

───────────────────────────────────────────────────────────
This is an automated security email.
TEXT;
}

/**
 * Clear cached session data
 * Call this when stores or other cached data is modified
 *
 * @param string|null $key Optional specific cache key to clear, or null to clear all
 * @return void
 */
function clear_session_cache(?string $key = null): void
{
    if ($key === null) {
        // Clear all cached data
        $cacheKeys = ['cached_stores'];
        foreach ($cacheKeys as $cacheKey) {
            if (isset($_SESSION[$cacheKey])) {
                unset($_SESSION[$cacheKey]);
            }
        }
    } else {
        // Clear specific cache key
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
}

/**
 * Generate email from template with variable replacement
 *
 * @param int $emailConfigId Purchasing email configuration ID
 * @param string $orderNumber Order number
 * @param string $shipToStoreName Shipping destination store
 * @param array $items Order items
 * @param string $notes Order notes
 * @param string $region User's region
 * @param int $shipToStoreId Shipping store ID
 * @return array ['subject' => string, 'body' => string, 'mailto_link' => string]
 */
function generate_email_from_template(
    int $emailConfigId,
    string $orderNumber,
    string $shipToStoreName,
    array $items,
    string $notes,
    string $region,
    int $shipToStoreId
): array {
    $pdo = db();

    // Fetch template
    $stmt = $pdo->prepare('
        SELECT email, cc_addresses, subject_template, body_template
        FROM purchasing_emails
        WHERE id = ? AND is_active = 1
    ');
    $stmt->execute([$emailConfigId]);
    $template = $stmt->fetch();

    if (!$template) {
        throw new Exception('Invalid purchasing email template');
    }

    // Build items list with billing breakdown
    $itemsList = build_items_list_for_email($items, $shipToStoreId, $shipToStoreName);

    // Build order notes section
    $orderNotes = '';
    if (!empty(trim($notes))) {
        $orderNotes = "========================\nORDER NOTES:\n" . trim($notes) . "\n";
    }

    // Get current user name
    $userName = $_SESSION['email'] ?? 'User';
    if (isset($_SESSION['uid'])) {
        $userStmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
        $userStmt->execute([$_SESSION['uid']]);
        $user = $userStmt->fetch();
        if ($user) {
            if ($user['first_name'] && $user['last_name']) {
                $userName = $user['first_name'] . ' ' . $user['last_name'];
            } else {
                $userName = $user['email'];
            }
        }
    }

    // Define replacement variables
    $variables = [
        '{{ORDER_NUMBER}}' => $orderNumber,
        '{{STORE_NAME}}' => $shipToStoreName,
        '{{REGION}}' => $region,
        '{{DATE}}' => date('Y-m-d'),
        '{{DATE_TIME}}' => date('Y-m-d H:i:s'),
        '{{ITEMS_LIST}}' => $itemsList,
        '{{ORDER_NOTES}}' => $orderNotes,
        '{{USER_NAME}}' => $userName,
        '{{USER_EMAIL}}' => $_SESSION['email'] ?? '',
        '{{TOTAL_ITEMS}}' => count($items),
    ];

    // Replace variables in subject and body
    $subject = str_replace(array_keys($variables), array_values($variables), $template['subject_template']);
    $body = str_replace(array_keys($variables), array_values($variables), $template['body_template']);

    // Build mailto link
    $mailto = 'mailto:' . $template['email'];
    $mailto .= '?subject=' . rawurlencode($subject);
    $mailto .= '&body=' . rawurlencode($body);

    // Add CC addresses if present
    if (!empty($template['cc_addresses'])) {
        $ccList = array_map('trim', explode(',', $template['cc_addresses']));
        $ccList = array_filter($ccList, function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        });
        if (!empty($ccList)) {
            $mailto .= '&cc=' . rawurlencode(implode(',', $ccList));
        }
    }

    return [
        'subject' => $subject,
        'body' => $body,
        'mailto_link' => $mailto,
        'purchasing_email' => $template['email'],
        'cc_addresses' => $template['cc_addresses']
    ];
}

/**
 * Build items list section for email (with billing breakdown if multi-store)
 *
 * @param array $items Order items
 * @param int $shipToStoreId Shipping destination store ID
 * @param string $shipToStoreName Shipping destination store name
 * @return string Formatted items list text
 */
function build_items_list_for_email(array $items, int $shipToStoreId, string $shipToStoreName): string
{
    // Group items by billing store
    $billing_groups = [];
    foreach ($items as $item) {
        $bill_store_id = $item['bill_to_store_id'] ?: $shipToStoreId;
        $bill_store_name = $item['bill_to_store_name'] ?: $shipToStoreName;

        if (!isset($billing_groups[$bill_store_id])) {
            $billing_groups[$bill_store_id] = [
                'store_name' => $bill_store_name,
                'items' => []
            ];
        }

        $billing_groups[$bill_store_id]['items'][] = $item;
    }

    $output = '';

    // Check if multi-store billing
    if (count($billing_groups) > 1) {
        $output .= "BILLING BREAKDOWN:\n";
        $output .= "-------------------\n\n";

        foreach ($billing_groups as $store_id => $group) {
            $output .= "Bill to: {$group['store_name']}\n";
            $output .= str_repeat('-', strlen("Bill to: {$group['store_name']}")) . "\n";

            foreach ($group['items'] as $item) {
                $output .= "  SKU: {$item['sku']}\n";
                $output .= "  Product: {$item['name']}\n";
                $output .= "  Quantity: {$item['quantity']}\n";
                if (!empty($item['notes'])) {
                    $output .= "  Notes: {$item['notes']}\n";
                }
                $output .= "\n";
            }
        }
    } else {
        // Single store - use original format
        $output .= "ITEMS:\n";
        $output .= "------\n\n";

        foreach ($items as $item) {
            $output .= "SKU: {$item['sku']}\n";
            $output .= "Product: {$item['name']}\n";
            $output .= "Quantity: {$item['quantity']}\n";
            if (!empty($item['notes'])) {
                $output .= "Notes: {$item['notes']}\n";
            }
            $output .= "\n";
        }
    }

    return $output;
}

// Profile completion helpers
if (!function_exists('needs_profile_completion')) {
    function needs_profile_completion(int $user_id): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT first_name, last_name, username, region_id FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) {
            return [
                'needs_completion' => false,
                'missing_fields' => [],
            ];
        }

        $missing = [];
        if (empty(trim((string)($user['first_name'] ?? '')))) {
            $missing[] = 'first_name';
        }
        if (empty(trim((string)($user['last_name'] ?? '')))) {
            $missing[] = 'last_name';
        }
        if (empty(trim((string)($user['username'] ?? '')))) {
            $missing[] = 'username';
        }
        if (empty($user['region_id'])) {
            $missing[] = 'region_id';
        }

        return [
            'needs_completion' => !empty($missing),
            'missing_fields' => $missing,
        ];
    }
}

if (!function_exists('validate_user_profile_data')) {
    function validate_user_profile_data(array $data): ValidationResult
    {
        $validator = new Validator($data);

        return $validator
            ->rule('first_name', ['required', 'length' => [1, 80]], 'First name is required')
            ->rule('last_name', ['required', 'length' => [1, 80]], 'Last name is required')
            ->rule('username', ['length' => [0, 80]], 'Username cannot exceed 80 characters')
            ->rule('region_id', ['required', 'int' => [1]], 'Please select a valid region')
            ->validate();
    }
}
