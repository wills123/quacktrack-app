<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_login();
require_permission('assets.manage');
require_once __DIR__ . '/includes/SnipeItClient.php';

$pdo = db();
$actor_id = (int)($_SESSION['uid'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = req_raw_string($_POST, 'action');
    $product_id = req_int($_POST, 'product_id');

    try {
        if ($action === 'save') {
            $template_name = req_string($_POST, 'template_name');
            $model_id = req_int($_POST, 'model_id');
            $status_id = req_int($_POST, 'status_id');
            $name_template = req_string($_POST, 'asset_name_template');
            $tag_prefix = req_string($_POST, 'asset_tag_prefix');
            $default_notes = req_string($_POST, 'default_notes');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($product_id <= 0 || $template_name === '' || $model_id <= 0 || $status_id <= 0) {
                throw new InvalidArgumentException('Product, template name, model, and status are required.');
            }
            if (strlen($template_name) > 120 || strlen($name_template) > 255 || strlen($tag_prefix) > 100 || strlen($default_notes) > 500) {
                throw new InvalidArgumentException('One or more template fields are too long.');
            }
            $productCheck = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_tracked_asset = 1 LIMIT 1');
            $productCheck->execute([$product_id]);
            if (!$productCheck->fetchColumn()) {
                throw new InvalidArgumentException('That product is not a tracked asset.');
            }

            $stmt = $pdo->prepare('
                INSERT INTO snipe_asset_templates
                    (product_id, template_name, model_id, status_id, asset_name_template, asset_tag_prefix, default_notes, is_active, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    template_name = VALUES(template_name), model_id = VALUES(model_id), status_id = VALUES(status_id),
                    asset_name_template = VALUES(asset_name_template), asset_tag_prefix = VALUES(asset_tag_prefix),
                    default_notes = VALUES(default_notes), is_active = VALUES(is_active), updated_by = VALUES(updated_by)
            ');
            $stmt->execute([$product_id, $template_name, $model_id, $status_id, $name_template ?: '{{product_name}} {{serial}}', $tag_prefix ?: null, $default_notes ?: null, $is_active, $actor_id, $actor_id]);
            flash_message('Asset template saved.', 'success');
        } elseif ($action === 'delete') {
            if ($product_id <= 0) {
                throw new InvalidArgumentException('Invalid product.');
            }
            $stmt = $pdo->prepare('DELETE FROM snipe_asset_templates WHERE product_id = ?');
            $stmt->execute([$product_id]);
            flash_message('Asset template removed.', 'success');
        }
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        flash_message($e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save the asset template.', 'error');
        Logger::error('snipe_asset_template_save', $e, ['user_id' => $actor_id, 'product_id' => $product_id]);
    }
    header('Location: /asset-templates');
    exit;
}

$products = $pdo->query('
    SELECT p.id, p.product_id AS sku, p.name, p.image_path,
           t.template_name, t.model_id, t.status_id, t.asset_name_template, t.asset_tag_prefix, t.default_notes, t.is_active
    FROM products p
    LEFT JOIN snipe_asset_templates t ON t.product_id = p.id
    WHERE p.is_tracked_asset = 1
    ORDER BY p.name ASC, p.product_id ASC
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
    $snipe_error = 'Snipe-IT lookups are unavailable. Templates cannot be changed until the connection is restored.';
    Logger::error('snipe_asset_templates_page', $e);
}

$title = 'Snipe-IT Asset Templates';
$main_class = 'main-content';
$extra_styles = ['/assets/pages/stock.css', '/assets/pages/orders.css', '/assets/pages/asset-shared.css', '/assets/pages/asset_templates.css'];
$extra_scripts = ['/assets/pages/asset-templates.js'];
require VIEW_ROOT . '/asset_templates.view.php';
