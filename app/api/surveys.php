<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/survey_service.php';
require_once __DIR__ . '/../includes/survey_design_api.php';
require_once __DIR__ . '/../includes/survey_responses_api.php';
require_once __DIR__ . '/../includes/survey_media_reporting_api.php';

require_login();

$action = req_action();
$action = strtolower(trim($action));
$userId = (int)($_SESSION['uid'] ?? 0);

// Security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Each group of actions has its own checks and error handling.
switch ($action) {
    // ── Template design (includes/survey_design_api.php) ──
    case 'get_active_template':
    case 'get_template':
    case 'create_template':
    case 'update_template':
    case 'add_category':
    case 'update_category':
    case 'delete_category':
    case 'reorder_categories':
    case 'add_question':
    case 'update_question':
    case 'delete_question':
    case 'reorder_questions':
    case 'publish_version':
    case 'list_versions':
        survey_design_dispatch($action, $userId);
        break;

    // ── Drafts (includes/survey_responses_api.php) ──
    case 'start_survey':
    case 'get_draft':
    case 'save_draft':
    case 'submit_survey':
    case 'delete_draft':
        survey_responses_dispatch($action, $userId);
        break;

    // ── Photos, history and comparison (includes/survey_media_reporting_api.php) ──
    case 'upload_photo':
    case 'delete_photo':
    case 'list_submissions':
    case 'get_submission':
    case 'get_comparison':
    case 'list_tech_surveys':
        survey_media_reporting_dispatch($action, $userId);
        break;

    default:
        api_error('Unsupported action', 400);
}
