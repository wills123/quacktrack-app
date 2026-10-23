<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/survey_service.php';
require_login();
// Require at least one survey permission
if (!has_permission('surveys.submit') && !has_permission('surveys.view_own')) {
    require_permission('surveys.submit');
// Will trigger 403
}

$pdo = db();
// Fetch regions for dropdowns
$regionStmt = $pdo->query('SELECT id, name FROM regions ORDER BY name ASC');
$regions = $regionStmt->fetchAll() ?: [];
// Fetch stores with region info for dropdowns
$storeStmt = $pdo->query('SELECT s.id, s.name, s.region_id FROM stores s ORDER BY s.name ASC');
$stores = $storeStmt->fetchAll() ?: [];
// Permission flags for the view
$canSubmit = has_permission('surveys.submit');
$canViewAll = has_permission('surveys.view_all');
$canManageTemplates = has_permission('surveys.templates.manage');
$canCompare = has_permission('surveys.compare');
// Determine which view to show
$view = req_string($_GET, 'view', 'list');
$allowedViews = ['list', 'submit', 'detail', 'compare', 'template'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'list';
}

// View-specific permission gates
if ($view === 'template' && !$canManageTemplates) {
    require_permission('surveys.templates.manage');
}
if ($view === 'compare' && !$canCompare) {
    require_permission('surveys.compare');
}
if ($view === 'submit' && !$canSubmit) {
    require_permission('surveys.submit');
}

$title       = 'Store Surveys';
$main_class  = 'main-content';
$extra_styles = ['/assets/opdash.css', '/assets/pages/surveys.css'];
// Determine the view file
$viewMap = [
    'list'     => 'surveys.view.php',
    'submit'   => 'survey_submit.view.php',
    'detail'   => 'survey_detail.view.php',
    'compare'  => 'survey_compare.view.php',
    'template' => 'survey_template.view.php',
];
require __DIR__ . '/views/' . $viewMap[$view];
