<?php

/**
 * Quack-Map Store Directory API
 *
 * Stateless, API-key authenticated endpoint returning every store's id and
 * store_number, so Quack-Map can match its own locations to Quack-Track stores
 * by store number in one bulk request instead of one lookup per store.
 * Mirrors the auth pattern in quackmap-assets.php / powerbi.php.
 *
 * Usage: GET /api/quackmap-stores.php
 * Auth:  X-Api-Key header, key must carry the "quackmap:read" scope.
 *
 * Answers: 200 {success, stores}; 405 for any other method; 401 for a missing, unknown, inactive, expired
 * or wrongly scoped key (one answer for all of them); 500 for a database fault, which is never reported
 * as an authentication failure and never carries SQL or key material. An authenticated request counts
 * against its key (request count, last-used time and address) before the directory is read, so a fault
 * while reading still counts as a use.
 */

declare(strict_types=1);

define('QUACKTRACK_NO_SESSION', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/quackmap_store_service.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');

/** @param array<string, mixed> $body */
function quackmap_stores_respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    quackmap_stores_respond(405, ['success' => false, 'error' => 'Only GET requests are allowed.']);
}

try {
    $pdo = db();
    $keyId = quackmap_stores_authenticate($pdo, (string)($_SERVER['HTTP_X_API_KEY'] ?? ''), time());
    if ($keyId === null) {
        quackmap_stores_respond(401, ['success' => false, 'error' => 'Unauthorized.']);
    }
    quackmap_stores_record_use($pdo, $keyId, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    quackmap_stores_respond(200, ['success' => true, 'stores' => quackmap_stores_directory($pdo)]);
} catch (Throwable $e) {
    qt_rethrow_bad_input($e);
    Logger::error('quackmap_stores_api_error', $e);
    quackmap_stores_respond(500, ['success' => false, 'error' => 'Internal server error.']);
}
