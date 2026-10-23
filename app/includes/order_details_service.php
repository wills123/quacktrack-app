<?php

declare(strict_types=1);

/**
 * Owners and global viewers may read any applicable order. Other viewers must share the owner's
 * region and either have region scope or be receiving an order into regional storage.
 * admin.access and system.full_access are already expanded by has_permission().
 *
 * @param array<string, mixed> $order
 * @param array{user_id: int, region_id: int, all: bool, region: bool} $viewer
 */
function order_details_can_view(array $order, array $viewer): bool
{
    if ((int)$order['user_id'] === $viewer['user_id'] || $viewer['all']) {
        return true;
    }
    return $viewer['region_id'] > 0
        && (int)$order['owner_region_id'] === $viewer['region_id']
        && ($viewer['region'] || !empty($order['receive_to_region']));
}
