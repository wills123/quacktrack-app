<?php

/**
 * Quack-Map Asset Export API
 *
 * Stateless, API-key authenticated endpoint returning Snipe-IT assets for a
 * given Quack-Track store. Uses the key rules of quackmap-stores.php.
 *
 * Usage: GET /api/quackmap-assets.php?store_id=123[&limit=100&offset=0]
 * Auth:  X-Api-Key header, key must carry the "quackmap:read" scope.
 *
 * The checks and answers are in includes/quackmap_assets_api.php.
 */

declare(strict_types=1);

define('QUACKTRACK_NO_SESSION', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/quackmap_assets_api.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');

quackmap_assets_dispatch();
