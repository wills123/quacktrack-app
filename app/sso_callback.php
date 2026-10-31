<?php

// Remove bearer credentials before bootstrap diagnostics or application request logging runs.
$_SERVER['REQUEST_URI'] = '/sso/callback';
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
    $flow = company_sso_take_flow($_GET);
    if ($flow['issuer'] !== $config['issuer'] || $flow['client_id'] !== $config['client_id']) {
        throw new RuntimeException('The SSO configuration changed during sign-in.');
    }
    $client = new CompanyOidcClient($config);
    // The library must see the same one-use flow that our early state check has just consumed.
    foreach ($flow['keys'] as $key => $value) {
        $_SESSION[$key] = $value;
    }
    $_REQUEST = ['code' => $_GET['code'], 'state' => $_GET['state']];
    try {
        if (!$client->authenticate()) {
            throw new RuntimeException('The SSO provider did not authenticate the user.');
        }
    } finally {
        foreach (array_keys($flow['keys']) as $key) {
            unset($_SESSION[$key]);
        }
    }
    $claims = $client->getVerifiedClaims();
    if (!is_int($claims->auth_time ?? null) || $claims->auth_time < time() - $config['max_age'] || $claims->auth_time > time() + 60) {
        throw new RuntimeException('The SSO authentication is too old.');
    }
    $user = company_sso_user(db(), $config['issuer'], $claims->sub);
    company_sso_create_session($user, $config['issuer'], $claims->auth_time);
    redirect('/');
} catch (Throwable $error) {
    qt_rethrow_bad_input($error);
    company_sso_failure($error);
}
