<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_login();
require_permission('assets.view');

require_once __DIR__ . '/includes/SnipeTokenManager.php';

// Check if Snipe-IT integration is enabled
if (env('SNIPE_IT_ENABLED', '0') !== '1') {
    flash('Snipe-IT integration is not enabled.', 'error');
    redirect('/');
}

$userId = (int)($_SESSION['uid'] ?? 0);

// Resolve a valid personal token — silently refreshes if near expiry.
// Returns null when the user has no linked account or refresh fails.
$snipeToken = SnipeTokenManager::getValid($userId);

$title      = 'IT Assets';
$main_class = 'main-content';

// Base URL for the "Open Snipe-IT" external link
$snipeBaseUrl = env('SNIPE_IT_OAUTH_BASE_URL', '');
if ($snipeBaseUrl === '') {
    $snipeBaseUrl = rtrim(preg_replace('#/api/v\d+$#i', '', env('SNIPE_IT_API_URL', '')), '/');
}

// Not connected — show the "Sign in with Snipe-IT" screen
if ($snipeToken === null) {
    $oauthEnabled = env('SNIPE_IT_OAUTH_ENABLED', '0') === '1';
    require VIEW_ROOT . '/assets-connect.view.php';
    exit;
}

// Snipe-IT permissions, re-read from Snipe-IT every few minutes so a right revoked there stops showing here.
$snipePermissions = SnipeTokenManager::getFreshPermissions($userId, $snipeToken);

// Derive action capabilities from Snipe-IT permissions.
// superuser and admin flags grant full access.
$snipeAdmin   = ($snipePermissions['superuser'] ?? '0') === '1'
             || ($snipePermissions['admin']     ?? '0') === '1';

$canCheckout    = $snipeAdmin || ($snipePermissions['assets.checkout']    ?? '0') === '1';
$canCheckin     = $snipeAdmin || ($snipePermissions['assets.checkin']     ?? '0') === '1';
$canManage      = $snipeAdmin || ($snipePermissions['assets.edit']        ?? '0') === '1';
$canLicenses    = $snipeAdmin || ($snipePermissions['licenses.view']      ?? '0') === '1';
$canMaintenance = $snipeAdmin || ($snipePermissions['assets.edit']        ?? '0') === '1';
$canCategories  = $snipeAdmin || ($snipePermissions['categories.view']    ?? '0') === '1';
$canReports     = $snipeAdmin || ($snipePermissions['reports.view']       ?? '0') === '1';
$canAdmin       = $snipeAdmin;

require VIEW_ROOT . '/assets.view.php';
