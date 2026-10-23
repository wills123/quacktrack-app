<?php

/**
 * Front Controller Router
 *
 * This is the main entry point for all HTTP requests to the application.
 * It handles routing, error pages, and ensures proper HTTP status codes.
 */

// Define the base path for the application
define('APP_ROOT', __DIR__);
define('VIEW_ROOT', APP_ROOT . '/views');
// Get the requested URI and method
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestUri)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo 'Invalid request URI.';
    exit;
}
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Redirect loop prevention removed - let browser handle it

// Remove trailing slash except for root
if ($requestUri !== '/' && substr($requestUri, -1) === '/') {
    $requestUri = rtrim($requestUri, '/');
}

// Define valid routes (map URLs to PHP files)
// This acts as a whitelist of accessible PHP files
$routes = [
    // Public routes (clean URLs)
    '/' => '/index.php',
    '/index' => '/index.php',
    '/login' => '/login.php',
    '/logout' => '/logout.php',
    '/register' => '/register.php',
    '/forgot_password' => '/forgot_password.php',
    '/forgot-password' => '/forgot_password.php',  // Alternative hyphenated version
    '/reset_password' => '/reset_password.php',
    '/reset-password' => '/reset_password.php',  // Alternative hyphenated version
    '/change_password' => '/change_password.php',
    '/change-password' => '/change_password.php',  // Alternative hyphenated version
    '/health' => '/health.php',
    '/public_form' => '/public_form.php',
    '/public-form' => '/public_form.php',

    // Main application routes (clean URLs)
    '/products' => '/products.php',
    '/cart' => '/cart.php',
    '/stock' => '/stock.php',
    '/stock/item' => '/stock_item.php',
    '/stock-item' => '/stock_item.php',
    '/transactions' => '/transactions.php',
    '/orders' => '/orders.php',
    '/receiving' => '/receiving.php',
    '/order_confirmation' => '/order_confirmation.php',
    '/order-confirmation' => '/order_confirmation.php',  // Alternative hyphenated version
    '/region_storage' => '/region_storage.php',
    '/region-storage' => '/region_storage.php',  // Alternative hyphenated version
    '/travel' => '/travel.php',
    '/deployments' => '/deployments.php',
    '/forms' => '/forms.php',
    '/surveys' => '/surveys.php',
    '/assets' => '/assets.php',
    '/assets.php' => '/assets.php',
    '/reports' => '/reports.php',
    '/reports.php' => '/reports.php',
    '/asset-review' => '/asset_review.php',
    '/asset-review.php' => '/asset_review.php',
    '/asset-templates' => '/asset_templates.php',
    '/asset-templates.php' => '/asset_templates.php',
    '/form_submit' => '/form_submit.php',
    '/form-submit' => '/form_submit.php',
    '/form_responses' => '/form_responses.php',
    '/form-responses' => '/form_responses.php',
    '/form_response_detail' => '/form_response_detail.php',
    '/form-response-detail' => '/form_response_detail.php',

    // Admin routes (clean URLs)
    '/admin' => '/admin.php',

    // Admin subdirectory routes (clean URLs)
    '/admin/regions' => '/admin/regions.php',
    '/admin/stores' => '/admin/stores.php',
    '/admin/users' => '/admin/users.php',
    '/admin/migrations' => '/admin/migrations.php',
    '/admin/stock_settings' => '/admin/stock_settings.php',
    '/admin/email_templates' => '/admin/email_templates.php',
    '/admin/categories' => '/admin/categories.php',
    '/admin/manufacturers' => '/admin/manufacturers.php',
    '/admin/api_keys' => '/admin/api_keys.php',
    '/admin/api-keys' => '/admin/api_keys.php',
    '/admin_logs' => '/admin_logs.php',
    '/admin_files' => '/admin_files.php',
    '/admin_sessions' => '/admin_sessions.php',

    // API routes (keep with .php for backwards compatibility)
    '/api/cart.php' => '/api/cart.php',
    '/api/checkout.php' => '/api/checkout.php',
    '/api/email_helper.php' => '/api/email_helper.php',
    '/api/favorites.php' => '/api/favorites.php',
    '/api/order_details.php' => '/api/order_details.php',
    '/api/products.php' => '/api/products.php',
    '/api/scan_product.php' => '/api/scan_product.php',
    '/api/orders.php' => '/api/orders.php',
    '/api/templates.php' => '/api/templates.php',
    '/api/log_client_error.php' => '/api/log_client_error.php',
    '/api/change_password.php' => '/api/change_password.php',
    '/api/profile_completion.php' => '/api/profile_completion.php',
    '/api/deployments.php' => '/api/deployments.php',
    '/api/forms.php' => '/api/forms.php',
    '/api/surveys.php' => '/api/surveys.php',
    '/api/transactions.php' => '/api/transactions.php',
    '/api/stock.php' => '/api/stock.php',
    '/api/purchasing_emails.php' => '/api/purchasing_emails.php',
    '/api/travel.php' => '/api/travel.php',
    '/api/powerbi.php' => '/api/powerbi.php',
    '/api/powerbi' => '/api/powerbi.php',
    '/api/external/orders.php' => '/api/external/orders.php',

    // Snipe IT Integration API routes
    '/api/snipe-it-assets.php' => '/api/snipe-it-assets.php',
    '/api/snipe-asset-review.php' => '/api/snipe-asset-review.php',
    '/api/tracked-assets.php' => '/api/tracked-assets.php',
    '/api/snipe-it-reports.php' => '/api/snipe-it-reports.php',
    '/api/snipe-oauth-callback.php' => '/api/snipe-oauth-callback.php',
    '/api/snipe-oauth-callback' => '/api/snipe-oauth-callback.php',
    '/api/snipe-link-start.php' => '/api/snipe-link-start.php',
    '/api/snipe-link-start' => '/api/snipe-link-start.php',
    '/api/snipe-disconnect.php' => '/api/snipe-disconnect.php',
    '/api/snipe-disconnect' => '/api/snipe-disconnect.php',

    // Acumatica ERP Integration API (generic -- callable from any feature)
    '/api/acumatica.php' => '/api/acumatica.php',

    // BACKWARDS COMPATIBILITY - Keep old .php URLs working
    // This ensures existing links/bookmarks don't break
    '/index.php' => '/index.php',
    '/login.php' => '/login.php',
    '/logout.php' => '/logout.php',
    '/register.php' => '/register.php',
    '/forgot_password.php' => '/forgot_password.php',
    '/reset_password.php' => '/reset_password.php',
    '/change_password.php' => '/change_password.php',
    '/products.php' => '/products.php',
    '/cart.php' => '/cart.php',
    '/stock.php' => '/stock.php',
    '/stock_item.php' => '/stock_item.php',
    '/transactions.php' => '/transactions.php',
    '/orders.php' => '/orders.php',
    '/receiving.php' => '/receiving.php',
    '/order_confirmation.php' => '/order_confirmation.php',
    '/region_storage.php' => '/region_storage.php',
    '/travel.php' => '/travel.php',
    '/deployments.php' => '/deployments.php',
    '/forms.php' => '/forms.php',
    '/surveys.php' => '/surveys.php',
    '/form_submit.php' => '/form_submit.php',
    '/form_responses.php' => '/form_responses.php',
    '/form_response_detail.php' => '/form_response_detail.php',
    '/health.php' => '/health.php',
    '/public_form.php' => '/public_form.php',
    '/admin.php' => '/admin.php',
    '/admin/regions.php' => '/admin/regions.php',
    '/admin/stores.php' => '/admin/stores.php',
    '/admin/users.php' => '/admin/users.php',
    '/admin/migrations.php' => '/admin/migrations.php',
    '/admin/stock_settings.php' => '/admin/stock_settings.php',
    '/admin/email_templates.php' => '/admin/email_templates.php',
    '/admin/categories.php' => '/admin/categories.php',
    '/admin/manufacturers.php' => '/admin/manufacturers.php',
    '/admin/api_keys.php' => '/admin/api_keys.php',
    '/admin_logs.php' => '/admin_logs.php',
    '/admin_files.php' => '/admin_files.php',
    '/admin_sessions.php' => '/admin_sessions.php',
    '/kits' => '/kit_templates.php',
    '/kits.php' => '/kit_templates.php',
    '/kits/packing-slip' => '/packing_slip.php',
    '/api/kit-templates.php' => '/api/kit-templates.php',
];
// Add development/test routes if not in production
// Note: Test files have been removed from the codebase
if (($_ENV['APP_ENV'] ?? 'dev') !== 'production') {
// Utility routes (only in development)
    $routes['/backup'] = '/backup.php';
    $routes['/backup.php'] = '/backup.php';
    $routes['/migration_runner'] = '/app/migration_runner.php';
    $routes['/migration-runner'] = '/app/migration_runner.php';
    $routes['/app/migration_runner.php'] = '/app/migration_runner.php';
}

/**
 * Serve an error page with the correct HTTP status code
 */
function serveErrorPage(int $code, ?string $customMessage = null): void
{

    // Set the HTTP response code
    http_response_code($code);

    // Error pages (403/404 especially) are reached for unmatched routes
    // before config.php is ever loaded (it's only required below for 500s,
    // to log the error) -- so set_security_headers() in config.php never
    // runs for them unless we do it here too. Kept minimal/inline rather
    // than requiring config.php unconditionally, since that would start a
    // session and DB connection on every 404.
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
// Check if this is an AJAX/API request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0;
    $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) &&
                   strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    if ($isAjax || $isApi || $acceptsJson) {
    // Return JSON error for AJAX/API requests
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $customMessage ?? getErrorMessage($code),
            'code' => $code
        ]);
        exit;
    }

    // Generate error ID for 500 errors
    $error_id = null;
    if ($code === 500) {
        $error_id = uniqid('ERR-', true);
    // Log the error if logger is available
        if (file_exists(APP_ROOT . '/config.php')) {
            require_once APP_ROOT . '/config.php';
            if (class_exists('Logger')) {
                Logger::info('router_error_500', 'Internal server error occurred', [
                    'error_id' => $error_id,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            }
        }
    }

    // Determine which error view to use
    $errorViews = [
        403 => VIEW_ROOT . '/errors/403.view.php',
        404 => VIEW_ROOT . '/errors/404.view.php',
        500 => VIEW_ROOT . '/errors/500.view.php',
        502 => VIEW_ROOT . '/errors/500.view.php',
        503 => VIEW_ROOT . '/errors/500.view.php',
    ];
    $viewFile = $errorViews[$code] ?? $errorViews[500];
// Include the error view if it exists
    if (file_exists($viewFile)) {
        require $viewFile;
    } else {
    // Fallback error page if view doesn't exist
        echo generateFallbackErrorPage($code, $customMessage);
    }

    exit;
}

/**
 * Get a user-friendly error message based on the code
 */
function getErrorMessage(int $code): string
{

    $messages = [
        403 => 'Access Forbidden',
        404 => 'Page Not Found',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];
    return $messages[$code] ?? 'An error occurred';
}

/**
 * Generate a fallback error page if the view file doesn't exist
 */
function generateFallbackErrorPage(int $code, ?string $message = null): string
{

    $title = getErrorMessage($code);
    $message = $message ?? "Error $code occurred. Please try again later.";
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$code - $title | Quack Track</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            background: white;
            border-radius: 10px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        .error-code {
            font-size: 72px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        .error-title {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 20px;
        }
        .error-message {
            color: #718096;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        .btn {
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">$code</div>
        <div class="error-title">$title</div>
        <div class="error-message">$message</div>
        <div class="buttons">
            <a href="/" class="btn btn-primary">Go Home</a>
            <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Main routing logic
 */
// Short-circuit when invoked explicitly for error handling (via nginx error_page)
// Only nginx's internal `error_page ... /router.php?code=NNN` redirect is trusted: it is
// the one request whose SCRIPT_NAME is /router.php (every other URL is dispatched with its
// own path, and a direct request for /router.php is `internal` and gets a 404). Without
// that check any visitor could force an error page, or an invalid status, with ?code=.
if (isset($_GET['code']) && ($_SERVER['SCRIPT_NAME'] ?? '') === '/router.php') {
    $code = filter_var($_GET['code'], FILTER_VALIDATE_INT);
    if (is_int($code) && in_array($code, [403, 404, 500, 502, 503, 504], true)) {
        unset($_SESSION['redirect_count']);
        serveErrorPage($code);
    }
}

try {
// SEO OPTIMIZATION: Disabled temporarily to fix redirect loops
// Re-enable once redirect loop issues are resolved.

    // Check if the route exists
    if (!isset($routes[$requestUri])) {
// Check if it's trying to access a PHP file directly that's not in routes
        if (substr($requestUri, -4) === '.php') {
// This is a PHP file that's not whitelisted - return 404
            serveErrorPage(404, "The requested page does not exist.");
        }

        // Not a valid route - return 404
        serveErrorPage(404, "The requested page could not be found.");
    }

    // Get the actual file path
    $filePath = APP_ROOT . $routes[$requestUri];
// Security check: Ensure the file exists and is within APP_ROOT
    if (!file_exists($filePath)) {
        serveErrorPage(404, "The requested resource is not available.");
    }

    // Security check: Prevent directory traversal
    $realPath = realpath($filePath);
    $appRealPath = realpath(APP_ROOT);
    if ($realPath === false || $appRealPath === false || strpos($realPath, $appRealPath) !== 0) {
        serveErrorPage(403, "Access to this resource is forbidden.");
    }

    // Tell the page which route it resolved. /admin, /admin/ and /admin.php all map to
    // /admin.php; views (the sidebar highlight) read this instead of guessing from the URL.
    $_SERVER['QT_ROUTE'] = $routes[$requestUri];
    $_SERVER['QT_PAGE'] = basename($routes[$requestUri], '.php');

    // Include and execute the requested file
    require $filePath;
} catch (Exception $e) {
// Log the exception if possible
    if (file_exists(APP_ROOT . '/config.php')) {
        require_once APP_ROOT . '/config.php';
        if (qt_is_client_error($e)) {
            qt_respond_bad_request($e);
        }
        if (class_exists('Logger')) {
            Logger::error('router_exception', $e);
        }
    }

    // Show 500 error page
    serveErrorPage(500, "An unexpected error occurred. Please try again later.");
} catch (Error $e) {
// Handle fatal errors
    if (file_exists(APP_ROOT . '/config.php')) {
        require_once APP_ROOT . '/config.php';
        if (qt_is_client_error($e)) {
            qt_respond_bad_request($e);
        }
        if (class_exists('Logger')) {
            Logger::error('router_fatal_error', $e);
        }
    }

    // Show 500 error page
    serveErrorPage(500, "A critical error occurred. Please try again later.");
}
