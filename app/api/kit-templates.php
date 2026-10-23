<?php

/**
 * Kit items and asset lookups: `get_kit_snipe_items`, `lookup_serial` and `search_items` read (GET), `add_item`,
 * `remove_item` and `update_item_quantity` change a kit (POST, kit.manage). Everyone here needs kit.view. The checks
 * and steps are in includes/kit_templates_api.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SnipeItClient.php';
require_once __DIR__ . '/../includes/kit_templates_api.php';
require_login();
require_permission('kit.view');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$userId = (int)($_SESSION['uid'] ?? 0);
$action = req_action();
match ($action) {
    'get_kit_snipe_items' => kit_templates_dispatch('get_kit_snipe_items', $userId),
    'lookup_serial' => kit_templates_dispatch('lookup_serial', $userId),
    'search_items' => kit_templates_dispatch('search_items', $userId),
    'add_item' => kit_templates_dispatch('add_item', $userId),
    'remove_item' => kit_templates_dispatch('remove_item', $userId),
    'update_item_quantity' => kit_templates_dispatch('update_item_quantity', $userId),
    default => api_error('Unknown action.', 400),
};
