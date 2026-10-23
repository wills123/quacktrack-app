<?php

require_once __DIR__ . '/config.php';
require_login();

// Redirect the legacy route to the unified reports page.
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$redirect = '/reports' . ($queryString !== '' ? '?' . $queryString : '');
header('Location: ' . $redirect, true, 301);
exit;
