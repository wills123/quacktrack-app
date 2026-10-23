<?php

/**
 * Transactions API: the van stock transaction history (`list`, GET) and restock (`restock`, POST with a JSON body:
 * add billable usage lines to the caller's cart). The checks and answers are in includes/transactions_api.php.
 *
 * @see /docs/transactions-rework/PROPOSAL.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/transactions_api.php';

require_login();
// Security headers for API endpoints
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$action = req_action();
match ($action) {
    'list' => transactions_dispatch($action, db(), (int)$_SESSION['uid']),
    'restock' => transactions_dispatch($action, db(), (int)$_SESSION['uid']),
    // An unknown action is answered as a 400 by the dispatcher.
    default => transactions_dispatch($action, db(), (int)$_SESSION['uid']),
};
