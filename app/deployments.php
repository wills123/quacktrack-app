<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/deployment_service.php';
require_login();
require_permission('deployment.view');
$pdo = db();
$regionStmt = $pdo->query('SELECT id, name FROM regions ORDER BY name ASC');
$regions = $regionStmt->fetchAll() ?: [];
$canManageDeployments = has_permission('deployment.manage');
$canCustomizeTasks = has_permission('deployment.tasks.customize');
$canExportDeployments = has_permission('deployment.reports.export');
$canManageForms = has_permission('forms.manage');
$canManageFormCampaigns = has_permission('forms.campaigns.manage');
$canSubmitInternalForms = has_permission('forms.submit_internal');
$canRequestShipment = has_permission('deployment.shipments.request');
$canApproveShipment = has_permission('deployment.shipments.approve');
$canReceiveShipment = has_permission('deployment.shipments.receive') || has_permission('kit.shipments.manage');
$canDispatchShipment = has_permission('kit.shipments.create');
$stageOptions = [];
foreach (DEPLOYMENT_STAGE_VALUES as $value) {
    $stageOptions[] = [
        'value' => $value,
        'label' => ucwords(str_replace('_', ' ', $value)),
    ];
}

$taskStatusOptions = [];
foreach (DEPLOYMENT_TASK_STATUS_VALUES as $value) {
    $taskStatusOptions[] = [
        'value' => $value,
        'label' => ucwords(str_replace('_', ' ', $value)),
    ];
}

$title = 'Deployments';
$extra_styles = ['/assets/deployments-quest.css'];
$main_class = 'deployments-main';
require __DIR__ . '/views/deployments.view.php';
