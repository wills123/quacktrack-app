<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_login();
require_permission('assets.manage');
require_once __DIR__ . '/includes/SnipeItClient.php';

$title = 'Snipe-IT Asset Review';
$main_class = 'main-content';
$extra_styles = ['/assets/pages/stock.css', '/assets/pages/orders.css', '/assets/pages/asset-shared.css', '/assets/pages/asset_review.css'];
$extra_scripts = ['/assets/pages/asset-review.js'];
$pdo = db();

$reviews = $pdo->query('
    SELECT q.id, q.order_id, q.order_item_id, q.product_id, q.identifier, q.created_at,
           p.name AS product_name, p.product_id AS sku, p.image_path,
           t.template_name, t.model_id AS template_model_id,
           t.status_id AS template_status_id,
           t.asset_name_template, t.asset_tag_prefix, t.default_notes,
           COALESCE(NULLIF(u.username, \'\'), NULLIF(CONCAT_WS(\' \', u.first_name, u.last_name), \'\'), u.email) AS submitted_by
    FROM snipe_asset_review_queue q
    JOIN products p ON p.id = q.product_id
    JOIN users u ON u.id = q.submitted_by
    LEFT JOIN snipe_asset_templates t ON t.product_id = q.product_id AND t.is_active = 1
    WHERE q.status = \'pending\'
    ORDER BY q.created_at ASC, q.id ASC
')->fetchAll(PDO::FETCH_ASSOC);

$models = [];
$statuses = [];
$snipe_error = null;
try {
    $client = SnipeItClient::getInstance();
    $models = $client->referenceList('models')['rows'] ?? [];
    $statuses = $client->referenceList('statuslabels')['rows'] ?? [];
} catch (Throwable $e) {
    qt_rethrow_bad_input($e);
    $snipe_error = 'Snipe-IT lookups are unavailable. Review creation is temporarily disabled.';
    Logger::error('snipe_asset_review_page', $e);
}

require VIEW_ROOT . '/asset_review.view.php';
