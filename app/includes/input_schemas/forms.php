<?php

/**
 * Request input of api/forms.php, one schema per action (see includes/input_schema.php).
 * list_forms takes no input. The ids name rows of INT or BIGINT columns, so a number past the
 * column can never name a row and is refused rather than looked up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../input_schema.php';

/** forms.id, deployment_projects.id and form_campaigns.id are INT columns. */
const FORMS_MAX_ID = 2147483647;

/**
 * Every action's schema, keyed by action name.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function forms_input_schemas(): array
{
    $campaign = in_int(min: 1, max: FORMS_MAX_ID, required: true, label: 'Campaign');
    $deployment = [
        'form_id' => in_int(min: 1, max: FORMS_MAX_ID, required: true, label: 'Form'),
        'deployment_id' => in_int(min: 1, max: FORMS_MAX_ID, required: true, label: 'Deployment'),
    ];
    return [
        'resolve_campaign_for_deployment' => $deployment,
        // Deprecated alias: it reads the same fields (a quest id is not read).
        'resolve_campaign_for_quest' => $deployment,
        'create_share_link' => ['campaign_id' => $campaign],
        'list_share_links' => ['campaign_id' => $campaign],
        'revoke_share_link' => ['link_id' => in_int(min: 1, required: true, label: 'Link')],
        'export_csv_wide' => ['campaign_id' => $campaign],
        'export_csv_long' => ['campaign_id' => $campaign],
    ];
}

/**
 * Parse the request for $action. Changing actions read the body only; list_share_links reads the
 * query and falls back to the body, as it always did (a campaign_id in the query wins); exports
 * read the query only.
 *
 * @return array<string, mixed>
 */
function forms_input(string $action): array
{
    static $schemas = null;
    $schemas ??= forms_input_schemas();
    $source = match ($action) {
        'list_share_links' => isset($_GET['campaign_id']) ? $_GET : $_POST,
        'export_csv_wide', 'export_csv_long' => $_GET,
        default => $_POST,
    };
    return input_parse($schemas[$action], $source);
}
