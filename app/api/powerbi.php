<?php

/**
 * Power BI Export API
 *
 * Provides read-only survey data for Power BI Web connector.
 * Authentication via API key in X-Api-Key header (keys managed in admin panel).
 *
 * Endpoints (via ?table= parameter):
 *   submissions  – one row per submitted survey
 *   answers      – one row per question answer (with question text, category)
 *   stores       – dimension table
 *   regions      – dimension table
 *   versions     – template version dimension table
 *   categories   – version category dimension table
 *   questions    – version question dimension table
 *   meta         – returns list of available tables and row counts
 *
 * The checks and tables are in includes/powerbi_api.php and includes/powerbi_service.php.
 */

declare(strict_types=1);

// This endpoint is API-key authenticated and should be stateless.
define('QUACKTRACK_NO_SESSION', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/powerbi_api.php';

// ── Security headers ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');

$table = powerbi_table();
match ($table) {
    'meta' => powerbi_dispatch($table),
    'submissions' => powerbi_dispatch($table),
    'answers' => powerbi_dispatch($table),
    'stores' => powerbi_dispatch($table),
    'regions' => powerbi_dispatch($table),
    'versions' => powerbi_dispatch($table),
    'categories' => powerbi_dispatch($table),
    'questions' => powerbi_dispatch($table),
    // An unknown table is answered as a 400, after the key checks, by the dispatcher.
    default => powerbi_dispatch($table),
};
