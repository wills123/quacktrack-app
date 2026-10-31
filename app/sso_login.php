<?php

$_SERVER['REQUEST_URI'] = '/sso/login';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/company_sso.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
try {
    $config = company_sso_config();
    $bucket = 'company_sso:start:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!auth_rate_limit_hit(db(), $bucket, max(1, (int)env('AUTH_LOGIN_IP_LIMIT', '100')), 900)) {
        http_response_code(429);
        header('Retry-After: 900');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Too many sign-in attempts. Please try again later.';
        exit;
    }
    $client = new CompanyOidcClient($config);
    // Library request parsing is restricted to a clean authorization start, never cookies/POST/query input.
    $_REQUEST = [];
    $client->authenticate();
} catch (Throwable $error) {
    qt_rethrow_bad_input($error);
    company_sso_failure($error);
}
