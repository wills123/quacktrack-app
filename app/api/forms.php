<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/input_schemas/forms.php';
require_once __DIR__ . '/../includes/forms_api_service.php';

require_login();

$action = strtolower(trim(req_action()));
$userId = (int)($_SESSION['uid'] ?? 0);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/** @param list<string> $allowed */
function forms_require_method(array $allowed): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ClientErrorException('Method not allowed', 405);
    }
}

function forms_require_csrf(): void
{
    if (!csrf_verify_api()) {
        throw new ForbiddenException('Invalid or missing CSRF token. Please refresh and try again.');
    }
}

/** list_forms is open to anyone who can submit or view responses, and to campaign managers. */
function forms_require_list_access(): void
{
    if (!has_permission('forms.campaigns.manage') && !has_permission('forms.submit_internal') && !has_permission('forms.view_responses')) {
        require_permission('forms.campaigns.manage');
    }
}

/**
 * Send a finished CSV with its attachment name; nothing is sent before the whole file is built.
 *
 * @param resource $stream
 */
function forms_send_csv($stream, string $filename): never
{
    rewind($stream);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    fpassthru($stream);
    exit;
}

/** The campaign (and its deployment) a "resolve" request names; the quest alias takes the same fields. */
function forms_handle_resolve(string $action, int $userId): void
{
    forms_require_method(['POST']);
    require_permission('forms.submit_internal');
    forms_require_csrf();
    $input = forms_input($action);
    $result = forms_resolve_campaign(db(), $userId, $input['form_id'], $input['deployment_id']);
    api_success([
        'campaign_id' => $result['campaign_id'],
        'redirect' => '/form_submit?campaign_id=' . $result['campaign_id'],
    ], $result['created'] ? 'Campaign created.' : 'Success');
}

/** Share-link actions need forms.invites.send; the changing ones need a POST with a CSRF token. */
function forms_handle_share_links(string $action, int $userId): void
{
    $changes = $action !== 'list_share_links';
    forms_require_method($changes ? ['POST'] : ['GET', 'HEAD', 'POST']);
    require_permission('forms.invites.send');
    if ($changes) {
        forms_require_csrf();
    }
    $input = forms_input($action);
    $pdo = db();
    if ($action === 'create_share_link') {
        api_success(forms_create_share_link($pdo, $userId, $input['campaign_id']), 'Share link created.');
    }
    if ($action === 'list_share_links') {
        api_success(['links' => forms_list_share_links($pdo, $input['campaign_id'])]);
    }
    forms_revoke_share_link($pdo, $input['link_id']);
    api_success(null, 'Link revoked.');
}

function forms_handle_list(): void
{
    forms_require_method(['GET', 'HEAD']);
    forms_require_list_access();
    api_success(['forms' => forms_list(db())]);
}

function forms_handle_export(string $action): void
{
    forms_require_method(['GET', 'HEAD']);
    require_permission('forms.export');
    $campaignId = forms_input($action)['campaign_id'];
    $wide = $action === 'export_csv_wide';
    $stream = $wide ? forms_export_wide(db(), $campaignId) : forms_export_long(db(), $campaignId);
    forms_send_csv($stream, 'form_campaign_' . $campaignId . ($wide ? '_wide.csv' : '_long.csv'));
}

try {
    switch ($action) {
        case 'list_forms':
            forms_handle_list();
            break;
        case 'resolve_campaign_for_deployment':
        case 'resolve_campaign_for_quest':
            forms_handle_resolve($action, $userId);
            break;
        case 'create_share_link':
        case 'list_share_links':
        case 'revoke_share_link':
            forms_handle_share_links($action, $userId);
            break;
        case 'export_csv_wide':
        case 'export_csv_long':
            forms_handle_export($action);
            break;
        default:
            throw new BadRequestException('Unsupported action');
    }
} catch (Throwable $e) {
    api_fail($e, 'forms_api_error', ['action' => $action, 'user_id' => $userId], 'Unable to complete request.');
}
