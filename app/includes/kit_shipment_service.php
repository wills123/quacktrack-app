<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/deployment_service.php';
require_once __DIR__ . '/deployment_constants.php';
require_once __DIR__ . '/SnipeItClient.php';
require_once __DIR__ . '/AcumaticaClient.php';

function deployment_shipment_bypass_enabled(): bool
{
    return get_app_setting('deployment_shipment_approval_bypass_enabled', '0') === '1';
}

function deployment_shipment_bypass_reason(): string
{
    return trim((string)get_app_setting('deployment_shipment_approval_bypass_reason', ''));
}

function deployment_shipment_validate_text(string $value, int $maxLength, string $label): string
{
    $value = trim($value);
    if (!mb_check_encoding($value, 'UTF-8')) {
        throw new UnprocessableException("{$label} contains invalid characters.");
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > $maxLength) {
        throw new UnprocessableException("{$label} cannot exceed {$maxLength} characters.");
    }
    return $value;
}

function deployment_shipment_normalize_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        throw new UnprocessableException('Needed-by date must use YYYY-MM-DD.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || !qt_year_is_storable($date)) {
        throw new UnprocessableException('Needed-by date must use YYYY-MM-DD.');
    }
    return $value;
}

function deployment_shipment_user_display_sql(string $alias): string
{
    return "COALESCE(NULLIF({$alias}.username, ''), NULLIF(CONCAT_WS(' ', {$alias}.first_name, {$alias}.last_name), ''), {$alias}.email)";
}

function deployment_shipment_get(int $shipmentId): ?array
{
    if ($shipmentId <= 0) {
        return null;
    }

    $pdo = db();
    $requestedDisplay = deployment_shipment_user_display_sql('requester');
    $approvedDisplay = deployment_shipment_user_display_sql('approver');
    $dispatchedDisplay = deployment_shipment_user_display_sql('dispatcher');
    // arch-ok: sql-interpolation - only the user-name expression from deployment_shipment_user_display_sql() and ? placeholders are put into the SQL
    $stmt = $pdo->prepare("
        SELECT ks.*, dp.site_name, dp.project_code, dp.stage AS deployment_stage,
               s.name AS store_name, kt.name AS kit_template_name,
               {$requestedDisplay} AS requested_by_display,
               {$approvedDisplay} AS approved_by_display,
               {$dispatchedDisplay} AS dispatched_by_display
        FROM kit_shipments ks
        LEFT JOIN deployment_projects dp ON dp.id = ks.deployment_id
        LEFT JOIN stores s ON s.id = ks.store_id
        JOIN kit_templates kt ON kt.id = ks.kit_template_id
        LEFT JOIN users requester ON requester.id = ks.requested_by
        LEFT JOIN users approver ON approver.id = ks.approved_by
        LEFT JOIN users dispatcher ON dispatcher.id = ks.dispatched_by
        WHERE ks.id = ?
        LIMIT 1
    ");
    $stmt->execute([$shipmentId]);
    $shipment = $stmt->fetch();
    if (!$shipment) {
        return null;
    }

    $itemStmt = $pdo->prepare("
        SELECT ksi.*, p.name AS product_name, p.product_id AS product_sku,
               p.acumatica_inventory_id, p.acumatica_default_vendor_id
        FROM kit_shipment_items ksi
        LEFT JOIN products p ON p.id = ksi.product_id
        WHERE ksi.kit_shipment_id = ?
        ORDER BY ksi.id ASC
    ");
    $itemStmt->execute([$shipmentId]);
    $shipment['items'] = $itemStmt->fetchAll() ?: [];
    foreach ($shipment['items'] as &$item) {
        $item['snipe_asset_ids'] = !empty($item['snipe_asset_ids'])
            ? (json_decode((string)$item['snipe_asset_ids'], true) ?: [])
            : [];
    }
    unset($item);

    $actorDisplay = deployment_shipment_user_display_sql('actor');
    // arch-ok: sql-interpolation - only the user-name expression from deployment_shipment_user_display_sql() and ? placeholders are put into the SQL
    $eventStmt = $pdo->prepare("
        SELECT kse.*, {$actorDisplay} AS actor_display
        FROM kit_shipment_events kse
        LEFT JOIN users actor ON actor.id = kse.actor_user_id
        WHERE kse.kit_shipment_id = ?
        ORDER BY kse.id DESC
    ");
    $eventStmt->execute([$shipmentId]);
    $shipment['events'] = $eventStmt->fetchAll() ?: [];
    foreach ($shipment['events'] as &$event) {
        $event['metadata'] = !empty($event['metadata'])
            ? (json_decode((string)$event['metadata'], true) ?: [])
            : [];
    }
    unset($event);

    return $shipment;
}

/**
 * Enrich a kit shipment's line items with live Acumatica data (on-hand qty,
 * shortfall vs. what the shipment needs) so a full packing list can be
 * assembled before the kit ships. Read-only -- never creates or changes
 * anything in Acumatica. Uses the generic AcumaticaClient, same as any other
 * feature would; this function is the only kit-specific part.
 *
 * @return array{enabled: bool, items: array} enabled=false (with items
 *   unmodified) when Acumatica isn't configured, so callers can render the
 *   plain shipment either way without special-casing.
 */
function deployment_shipment_packing_list(int $shipmentId): array
{
    $shipment = deployment_shipment_get($shipmentId);
    if ($shipment === null) {
        throw new NotFoundException('Shipment not found.');
    }

    $items = $shipment['items'];
    if (env('ACUMATICA_ENABLED', '0') !== '1') {
        return ['enabled' => false, 'items' => $items];
    }

    $inventoryIds = array_values(array_unique(array_filter(
        array_column($items, 'acumatica_inventory_id')
    )));
    if (empty($inventoryIds)) {
        return ['enabled' => true, 'items' => $items];
    }

    try {
        $client = AcumaticaClient::getInstance();
        $levels = $client->getStockLevels($inventoryIds);
    } catch (Throwable $e) {
        Logger::error('kit_packing_list_acumatica_lookup_failed', $e, ['kit_shipment_id' => $shipmentId]);
        // Degrade gracefully: show the shipment without stock data rather than failing the page.
        return ['enabled' => true, 'items' => $items, 'lookup_error' => 'Could not reach Acumatica right now.'];
    }

    foreach ($items as &$item) {
        $invId = $item['acumatica_inventory_id'] ?? null;
        if ($invId === null || !isset($levels[$invId])) {
            $item['acumatica_qty_on_hand'] = null;
            $item['acumatica_shortfall'] = null;
            continue;
        }
        $needed = (float)($item['quantity'] ?? 0);
        $onHand = $levels[$invId]['qtyOnHand'];
        $item['acumatica_qty_on_hand'] = $onHand;
        $item['acumatica_warehouses'] = $levels[$invId]['warehouses'];
        $item['acumatica_shortfall'] = max(0.0, $needed - $onHand);
    }
    unset($item);

    return ['enabled' => true, 'items' => $items];
}

function deployment_shipments_list(int $deploymentId, array $filters = []): array
{
    if ($deploymentId <= 0) {
        throw new UnprocessableException('Deployment ID is required.');
    }
    $pdo = db();
    $where = ['ks.deployment_id = ?'];
    $params = [$deploymentId];
    $status = strtolower(trim((string)($filters['status'] ?? '')));
    if ($status !== '' && in_array($status, DEPLOYMENT_SHIPMENT_STATUSES, true)) {
        $where[] = 'ks.status = ?';
        $params[] = $status;
    }
    $limit = max(1, min((int)($filters['limit'] ?? 100), 200));
    $requestedDisplay = deployment_shipment_user_display_sql('requester');
    // arch-ok: sql-interpolation - only the user-name expression from deployment_shipment_user_display_sql() and ? placeholders are put into the SQL
    $stmt = $pdo->prepare("
        SELECT ks.*, kt.name AS kit_template_name, s.name AS store_name,
               {$requestedDisplay} AS requested_by_display,
               COUNT(ksi.id) AS item_count,
               SUM(CASE WHEN ksi.line_status IN ('partial','unavailable') THEN 1 ELSE 0 END) AS issue_count,
               SUM(ksi.quantity_planned) AS quantity_planned_total,
               SUM(ksi.quantity_fulfilled) AS quantity_fulfilled_total,
               SUM(ksi.quantity_received) AS quantity_received_total
        FROM kit_shipments ks
        JOIN kit_templates kt ON kt.id = ks.kit_template_id
        LEFT JOIN stores s ON s.id = ks.store_id
        LEFT JOIN users requester ON requester.id = ks.requested_by
        LEFT JOIN kit_shipment_items ksi ON ksi.kit_shipment_id = ks.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY ks.id
        ORDER BY FIELD(ks.status, 'pending', 'preparing', 'dispatching', 'dispatched', 'partially_received', 'received', 'declined', 'cancelled'),
                 ks.needed_by IS NULL, ks.needed_by ASC, ks.id DESC
        LIMIT ?
    ");
    $stmt->execute([...$params, $limit]);
    return $stmt->fetchAll() ?: [];
}

function deployment_shipment_templates(): array
{
    $pdo = db();
    $templates = $pdo->query("
        SELECT kt.id, kt.name, kt.description, COUNT(kti.id) AS item_count
        FROM kit_templates kt
        LEFT JOIN kit_template_items kti ON kti.kit_template_id = kt.id
        WHERE kt.is_active = 1
        GROUP BY kt.id
        HAVING item_count > 0
        ORDER BY kt.name ASC
    ")->fetchAll() ?: [];
    if (empty($templates)) {
        return [];
    }
    $ids = array_column($templates, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // arch-ok: sql-interpolation - only the user-name expression from deployment_shipment_user_display_sql() and ? placeholders are put into the SQL
    $itemStmt = $pdo->prepare("
        SELECT kti.id, kti.kit_template_id, kti.item_type, kti.product_id,
               kti.snipe_model_id, kti.snipe_model_name, kti.quantity, kti.notes,
               p.name AS product_name, p.product_id AS product_sku
        FROM kit_template_items kti
        LEFT JOIN products p ON p.id = kti.product_id
        WHERE kti.kit_template_id IN ({$placeholders})
        ORDER BY kti.kit_template_id, kti.sort_order, kti.id
    ");
    $itemStmt->execute($ids);
    $itemsByTemplate = [];
    foreach ($itemStmt->fetchAll() ?: [] as $item) {
        $itemsByTemplate[(int)$item['kit_template_id']][] = $item;
    }
    foreach ($templates as &$template) {
        $template['items'] = $itemsByTemplate[(int)$template['id']] ?? [];
    }
    unset($template);
    return $templates;
}

function deployment_shipment_add_event(
    int $shipmentId,
    ?int $actorUserId,
    string $eventType,
    string $message,
    array $metadata = []
): void {
    $eventType = preg_replace('/[^a-z0-9_]/', '', strtolower($eventType)) ?: 'update';
    $message = deployment_shipment_validate_text($message, 750, 'Activity message');
    db()->prepare("
        INSERT INTO kit_shipment_events
            (kit_shipment_id, actor_user_id, event_type, message, metadata)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $shipmentId,
        $actorUserId ?: null,
        $eventType,
        $message,
        !empty($metadata) ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
    ]);
}

function deployment_shipment_publish_activity(int $shipmentId, string $eventType, string $message, array $metadata = []): void
{
    $shipment = deployment_shipment_get($shipmentId);
    if (!$shipment) {
        return;
    }
    if (!empty($shipment['deployment_quest_id'])) {
        deployment_quest_add_log(
            (int)$shipment['deployment_quest_id'],
            isset($metadata['actor_user_id']) ? (int)$metadata['actor_user_id'] : null,
            'update',
            $message,
            ['shipment_id' => $shipmentId, 'shipment_event_type' => $eventType]
        );
    }
    AuditLogger::log('deployment.shipment_' . $eventType, [
        'entity_type' => 'kit_shipment',
        'entity_id' => $shipmentId,
        'description' => $message,
        'metadata' => array_merge($metadata, [
            'deployment_id' => $shipment['deployment_id'],
            'deployment_quest_id' => $shipment['deployment_quest_id'],
        ]),
    ]);
}

function deployment_shipment_request(array $data, int $userId): array
{
    $deploymentId = (int)($data['deployment_id'] ?? 0);
    $questId = !empty($data['deployment_quest_id']) ? (int)$data['deployment_quest_id'] : null;
    $kitTemplateId = (int)($data['kit_template_id'] ?? 0);
    $requestKey = strtolower(trim((string)($data['request_key'] ?? '')));
    if ($deploymentId <= 0 || $kitTemplateId <= 0) {
        throw new UnprocessableException('Deployment and kit are required.');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $requestKey)) {
        throw new UnprocessableException('A valid request key is required.');
    }

    $pdo = db();
    $existingStmt = $pdo->prepare('SELECT id, requested_by FROM kit_shipments WHERE request_key = ? LIMIT 1');
    $existingStmt->execute([$requestKey]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        if ((int)$existing['requested_by'] !== $userId) {
            throw new ConflictException('Request key is already in use.');
        }
        return deployment_shipment_get((int)$existing['id']) ?? [];
    }

    $deploymentStmt = $pdo->prepare("
        SELECT dp.*, s.name AS store_name, s.address AS store_address
        FROM deployment_projects dp
        LEFT JOIN stores s ON s.id = dp.store_id
        WHERE dp.id = ? AND dp.is_archived = 0
        LIMIT 1
    ");
    $deploymentStmt->execute([$deploymentId]);
    $deployment = $deploymentStmt->fetch();
    if (!$deployment) {
        throw new NotFoundException('Deployment was not found or is archived.');
    }
    if ($questId) {
        $questStmt = $pdo->prepare('SELECT id FROM deployment_quests WHERE id = ? AND deployment_id = ? LIMIT 1');
        $questStmt->execute([$questId, $deploymentId]);
        if (!$questStmt->fetchColumn()) {
            throw new UnprocessableException('The selected quest does not belong to this deployment.');
        }
    }

    $kitStmt = $pdo->prepare('SELECT id, name FROM kit_templates WHERE id = ? AND is_active = 1 LIMIT 1');
    $kitStmt->execute([$kitTemplateId]);
    $kit = $kitStmt->fetch();
    if (!$kit) {
        throw new NotFoundException('Kit template was not found or is archived.');
    }
    $itemStmt = $pdo->prepare("
        SELECT kti.*, p.name AS product_name
        FROM kit_template_items kti
        LEFT JOIN products p ON p.id = kti.product_id
        WHERE kti.kit_template_id = ?
        ORDER BY kti.sort_order, kti.id
    ");
    $itemStmt->execute([$kitTemplateId]);
    $kitItems = $itemStmt->fetchAll() ?: [];
    if (empty($kitItems)) {
        throw new UnprocessableException('The selected kit has no items.');
    }

    $quantityOverrides = is_array($data['quantities'] ?? null) ? $data['quantities'] : [];
    $neededBy = deployment_shipment_normalize_date($data['needed_by'] ?? null);
    $requestNotes = deployment_shipment_validate_text((string)($data['request_notes'] ?? ''), 2000, 'Request notes');
    $requestedDestinationName = trim((string)($data['destination_name'] ?? ''));
    $destinationName = deployment_shipment_validate_text(
        $requestedDestinationName !== '' ? $requestedDestinationName : (string)$deployment['site_name'],
        190,
        'Destination name'
    );
    if ($destinationName === '') {
        throw new UnprocessableException('Destination name is required.');
    }
    $deploymentAddress = implode(', ', array_filter([
        $deployment['address_line1'] ?? null,
        $deployment['address_line2'] ?? null,
        trim(implode(' ', array_filter([$deployment['city'] ?? null, $deployment['state'] ?? null]))),
        $deployment['postal_code'] ?? null,
    ]));
    $requestedDestinationAddress = trim((string)($data['destination_address'] ?? ''));
    $destinationAddress = deployment_shipment_validate_text(
        $requestedDestinationAddress !== ''
            ? $requestedDestinationAddress
            : (string)($deploymentAddress ?: ($deployment['store_address'] ?? '')),
        2000,
        'Destination address'
    );

    $bypassEnabled = deployment_shipment_bypass_enabled();
    $bypassReason = deployment_shipment_bypass_reason();
    if ($bypassEnabled && $bypassReason === '') {
        throw new RuntimeException('Shipment approval bypass is enabled without a configured reason. Ask an administrator to update the setting.');
    }
    $bypassReasonLength = function_exists('mb_strlen') ? mb_strlen($bypassReason, 'UTF-8') : strlen($bypassReason);
    if ($bypassReasonLength > 500) {
        throw new RuntimeException('The configured shipment approval bypass reason is too long.');
    }
    $status = $bypassEnabled ? 'preparing' : 'pending';
    $approvalStatus = $bypassEnabled ? 'bypassed' : 'pending';

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO kit_shipments
                (kit_template_id, store_id, deployment_id, deployment_quest_id, status, approval_status,
                 request_key, requested_by, requested_at, needed_by, request_notes, approval_reason,
                 approved_at, destination_name, destination_address, kit_name_snapshot, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $kitTemplateId,
            !empty($deployment['store_id']) ? (int)$deployment['store_id'] : null,
            $deploymentId,
            $questId,
            $status,
            $approvalStatus,
            $requestKey,
            $userId,
            $neededBy,
            $requestNotes !== '' ? $requestNotes : null,
            $bypassEnabled ? $bypassReason : null,
            $bypassEnabled ? date('Y-m-d H:i:s') : null,
            $destinationName,
            $destinationAddress !== '' ? $destinationAddress : null,
            $kit['name'],
            $userId,
        ]);
        $shipmentId = (int)$pdo->lastInsertId();

        $lineInsert = $pdo->prepare("
            INSERT INTO kit_shipment_items
                (kit_shipment_id, item_type, product_id, snipe_model_id, snipe_model_name,
                 quantity_planned, quantity_fulfilled, quantity_received, line_status, line_notes)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'pending', ?)
        ");
        foreach ($kitItems as $item) {
            $itemId = (int)$item['id'];
            $quantity = array_key_exists((string)$itemId, $quantityOverrides)
                ? qt_parse_int($quantityOverrides[(string)$itemId])
                : (int)$item['quantity'];
            if ($quantity === null) {
                throw new UnprocessableException('Requested quantities must be whole numbers.');
            }
            if ($quantity < 0 || $quantity > 1000) {
                throw new UnprocessableException('Requested quantities must be between 0 and 1000.');
            }
            if ($quantity === 0) {
                continue;
            }
            $lineInsert->execute([
                $shipmentId,
                $item['item_type'],
                $item['product_id'] ?: null,
                $item['snipe_model_id'] ?: null,
                $item['snipe_model_name'] ?: ($item['product_name'] ?? null),
                $quantity,
                $item['notes'] ?: null,
            ]);
        }
        $lineCountStmt = $pdo->prepare('SELECT COUNT(*) FROM kit_shipment_items WHERE kit_shipment_id = ?');
        $lineCountStmt->execute([$shipmentId]);
        if ((int)$lineCountStmt->fetchColumn() === 0) {
            throw new UnprocessableException('At least one kit item must have a requested quantity.');
        }

        deployment_shipment_add_event($shipmentId, $userId, 'requested', 'Shipment requested.', [
            'approval_required' => !$bypassEnabled,
        ]);
        if ($bypassEnabled) {
            deployment_shipment_add_event(
                $shipmentId,
                null,
                'approval_bypassed',
                'Shipment approval was automatically bypassed. Reason: ' . $bypassReason,
                ['bypass_reason' => $bypassReason]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // A double click sends the same request key twice at once: both pass the lookup above,
        // and the second insert loses on the unique key. Answer it with the shipment the first
        // one created (the idempotent result) instead of a database error.
        if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
            $existingStmt->execute([$requestKey]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                if ((int)$existing['requested_by'] !== $userId) {
                    throw new ConflictException('Request key is already in use.');
                }
                return deployment_shipment_get((int)$existing['id']) ?? [];
            }
        }
        throw $e;
    }

    deployment_shipment_publish_activity($shipmentId, 'requested', 'Shipment requested.', [
        'actor_user_id' => $userId,
        'approval_status' => $approvalStatus,
        'bypass_reason' => $bypassEnabled ? $bypassReason : null,
    ]);
    if ($bypassEnabled) {
        deployment_shipment_publish_activity(
            $shipmentId,
            'approval_bypassed',
            'Shipment approval was automatically bypassed. Reason: ' . $bypassReason,
            ['actor_user_id' => null, 'bypass_reason' => $bypassReason]
        );
    }
    return deployment_shipment_get($shipmentId) ?? [];
}

/**
 * A state-changing update that touched no row is either a missing shipment (404) or one in the
 * wrong state (409); look it up to tell them apart.
 */
function deployment_shipment_fail_transition(int $shipmentId, string $conflictMessage): never
{
    $stmt = db()->prepare('SELECT 1 FROM kit_shipments WHERE id = ?');
    $stmt->execute([$shipmentId]);
    if ($stmt->fetchColumn() === false) {
        throw new NotFoundException('Shipment not found.');
    }
    throw new ConflictException($conflictMessage);
}

function deployment_shipment_approve(int $shipmentId, int $userId, string $reason = ''): array
{
    $reason = deployment_shipment_validate_text($reason, 500, 'Approval note');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status, approval_status FROM kit_shipments WHERE id = ? FOR UPDATE');
        $stmt->execute([$shipmentId]);
        $shipment = $stmt->fetch();
        if (!$shipment) {
            throw new NotFoundException('Shipment not found.');
        }
        if ($shipment['status'] !== 'pending' || $shipment['approval_status'] !== 'pending') {
            throw new ConflictException('Only pending shipment requests can be approved.');
        }
        $pdo->prepare("
            UPDATE kit_shipments
            SET status = 'preparing', approval_status = 'approved', approval_reason = ?,
                approved_by = ?, approved_at = NOW()
            WHERE id = ?
        ")->execute([$reason !== '' ? $reason : null, $userId, $shipmentId]);
        deployment_shipment_add_event(
            $shipmentId,
            $userId,
            'approved',
            $reason !== '' ? 'Shipment request approved. Note: ' . $reason : 'Shipment request approved.'
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    deployment_shipment_publish_activity($shipmentId, 'approved', 'Shipment request approved.', ['actor_user_id' => $userId]);
    return deployment_shipment_get($shipmentId) ?? [];
}

function deployment_shipment_decline(int $shipmentId, int $userId, string $reason): array
{
    $reason = deployment_shipment_validate_text($reason, 500, 'Decline reason');
    if ($reason === '') {
        throw new UnprocessableException('A reason is required when declining a shipment request.');
    }
    $pdo = db();
    $updated = $pdo->prepare("
        UPDATE kit_shipments
        SET status = 'declined', approval_status = 'declined', approval_reason = ?,
            approved_by = ?, approved_at = NOW()
        WHERE id = ? AND status = 'pending' AND approval_status = 'pending'
    ");
    $updated->execute([$reason, $userId, $shipmentId]);
    if ($updated->rowCount() !== 1) {
        deployment_shipment_fail_transition($shipmentId, 'Only pending shipment requests can be declined.');
    }
    deployment_shipment_add_event($shipmentId, $userId, 'declined', 'Shipment request declined. Reason: ' . $reason);
    deployment_shipment_publish_activity($shipmentId, 'declined', 'Shipment request declined.', ['actor_user_id' => $userId]);
    return deployment_shipment_get($shipmentId) ?? [];
}

function deployment_shipment_destination_assignment(array $shipment, int $actorUserId): array
{
    $pdo = db();
    $locationId = null;
    if (!empty($shipment['store_id'])) {
        $stmt = $pdo->prepare('SELECT snipe_location_id FROM stores WHERE id = ?');
        $stmt->execute([(int)$shipment['store_id']]);
        $locationId = (int)($stmt->fetchColumn() ?: 0);
    }
    if (!$locationId && !empty($shipment['deployment_id'])) {
        $stmt = $pdo->prepare("
            SELECT m.snipe_location_id
            FROM deployment_projects dp
            JOIN snipe_it_location_mappings m ON m.region_id = dp.region_id
            WHERE dp.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$shipment['deployment_id']]);
        $locationId = (int)($stmt->fetchColumn() ?: 0);
    }
    if ($locationId) {
        return ['checkout_to_type' => 'location', 'assigned_location' => $locationId];
    }
    $stmt = $pdo->prepare('SELECT snipe_user_id FROM users WHERE id = ?');
    $stmt->execute([$actorUserId]);
    $snipeUserId = (int)($stmt->fetchColumn() ?: 0);
    if ($snipeUserId) {
        return ['checkout_to_type' => 'user', 'assigned_user' => $snipeUserId];
    }
    return [];
}

/**
 * Serials arrive per shipment line, as a comma-separated string or a list of strings. Every
 * supplied entry is validated up front, before the shipment changes state: silently skipping a
 * malformed one would let the dispatch fall back to auto-selecting other assets.
 *
 * @param array<int|string, mixed> $serialsByLine
 * @return array<int, list<string>> serials keyed by line id
 */
function deployment_shipment_normalize_serials(array $serialsByLine): array
{
    $normalized = [];
    foreach ($serialsByLine as $lineKey => $values) {
        $lineId = qt_parse_int((string)$lineKey);
        if ($lineId === null || $lineId <= 0) {
            throw new UnprocessableException('Serials must be keyed by shipment line id.');
        }
        if (is_string($values)) {
            $values = explode(',', $values);
        } elseif (!is_array($values)) {
            throw new UnprocessableException('Serials for a line must be text or a list of text.');
        }
        $serials = [];
        foreach ($values as $serial) {
            if (!is_string($serial) && !is_int($serial)) {
                throw new UnprocessableException('Each serial must be text.');
            }
            $serial = trim((string)$serial);
            if ($serial === '') {
                continue;
            }
            deployment_require_max_length($serial, 190, 'Serial');
            $serials[] = $serial;
        }
        $normalized[$lineId] = $serials;
    }
    return $normalized;
}

function deployment_shipment_dispatch(
    int $shipmentId,
    int $userId,
    array $serialsByLine = [],
    string $carrier = '',
    string $trackingNumber = ''
): array {
    $carrier = deployment_shipment_validate_text($carrier, 100, 'Carrier');
    $trackingNumber = deployment_shipment_validate_text($trackingNumber, 190, 'Tracking number');
    $serialsByLine = deployment_shipment_normalize_serials($serialsByLine);
    $pdo = db();
    $lock = $pdo->prepare("
        UPDATE kit_shipments
        SET status = 'dispatching'
        WHERE id = ? AND status = 'preparing' AND approval_status IN ('approved','bypassed','not_required')
    ");
    $lock->execute([$shipmentId]);
    if ($lock->rowCount() !== 1) {
        deployment_shipment_fail_transition($shipmentId, 'Shipment must be approved and preparing before dispatch.');
    }
    deployment_shipment_add_event($shipmentId, $userId, 'dispatch_started', 'Shipment allocation started.');

    try {
        $shipment = deployment_shipment_get($shipmentId);
        if (!$shipment) {
            throw new NotFoundException('Shipment not found.');
        }
        $assignment = deployment_shipment_destination_assignment($shipment, $userId);
        $snipeClient = null;
        if (env('SNIPE_IT_ENABLED', '0') === '1') {
            $snipeClient = SnipeItClient::getInstance();
            $snipeClient->setCurrentUserId($userId);
        }
        $issueCount = 0;

        foreach ($shipment['items'] as $line) {
            $lineId = (int)$line['id'];
            $planned = (int)$line['quantity_planned'];
            $fulfilled = (int)$line['quantity_fulfilled'];
            $assetIds = is_array($line['snipe_asset_ids']) ? $line['snipe_asset_ids'] : [];
            $lineStatus = 'pending';
            $lineNotes = null;

            if ($line['item_type'] === 'product') {
                $fulfilled = $planned;
                $lineStatus = 'fulfilled';
            } elseif (!$snipeClient || empty($line['snipe_model_id'])) {
                $lineStatus = 'unavailable';
                $lineNotes = 'Snipe-IT integration is not available for this item.';
            } elseif (empty($assignment)) {
                $lineStatus = 'unavailable';
                $lineNotes = 'No Snipe-IT location is mapped and the dispatcher has no linked Snipe-IT account.';
            } else {
                $remaining = max(0, $planned - $fulfilled);
                $selectedAssets = [];
                $serialValues = $serialsByLine[$lineId] ?? [];
                foreach (array_slice($serialValues, 0, $remaining) as $serial) {
                    $asset = $snipeClient->getAssetBySerial($serial);
                    $assetId = (int)($asset['id'] ?? 0);
                    $alreadySelected = $assetId > 0 && (bool)array_filter(
                        $selectedAssets,
                        static fn(?array $selected): bool => (int)($selected['id'] ?? 0) === $assetId
                    );
                    if (
                        $assetId <= 0
                        || (int)($asset['model']['id'] ?? 0) !== (int)$line['snipe_model_id']
                        || !empty($asset['assigned_to'])
                        || ($asset['status_label']['status_meta'] ?? '') === 'deployed'
                        || in_array($assetId, $assetIds, true)
                        || $alreadySelected
                    ) {
                        continue;
                    }
                    $selectedAssets[] = $asset;
                }
                if (count($selectedAssets) < $remaining) {
                    $available = $snipeClient->listAssets([
                        'model_id' => (int)$line['snipe_model_id'],
                        'limit' => 100,
                        'offset' => 0,
                    ]);
                    foreach ($available['rows'] ?? [] as $asset) {
                        if (count($selectedAssets) >= $remaining) {
                            break;
                        }
                        $assetId = (int)($asset['id'] ?? 0);
                        if ($assetId <= 0 || in_array($assetId, $assetIds, true) || !empty($asset['assigned_to'])) {
                            continue;
                        }
                        if (($asset['status_label']['status_meta'] ?? '') === 'deployed') {
                            continue;
                        }
                        if (!array_filter($selectedAssets, static fn(?array $selected): bool => (int)($selected['id'] ?? 0) === $assetId)) {
                            $selectedAssets[] = $asset;
                        }
                    }
                }

                foreach ($selectedAssets as $asset) {
                    $assetId = (int)$asset['id'];
                    $payload = $assignment;
                    $payload['note'] = 'Kit shipment #' . $shipmentId . ' → ' . ($shipment['destination_name'] ?: 'deployment site');
                    $result = $snipeClient->checkoutAsset($assetId, $payload);
                    if ($result && ($result['status'] ?? '') === 'success') {
                        $assetIds[] = $assetId;
                        $fulfilled++;
                    }
                }
                if ($fulfilled === 0) {
                    $lineStatus = 'unavailable';
                    $lineNotes = 'No available assets were found for this model.';
                } elseif ($fulfilled < $planned) {
                    $lineStatus = 'partial';
                    $lineNotes = "Only {$fulfilled} of {$planned} assets were available.";
                } else {
                    $lineStatus = 'fulfilled';
                }
            }

            if (in_array($lineStatus, ['partial', 'unavailable'], true)) {
                $issueCount++;
            }
            $pdo->prepare("
                UPDATE kit_shipment_items
                SET snipe_asset_ids = ?, quantity_fulfilled = ?, line_status = ?, line_notes = ?
                WHERE id = ? AND kit_shipment_id = ?
            ")->execute([
                !empty($assetIds) ? json_encode(array_values(array_unique($assetIds)), JSON_THROW_ON_ERROR) : null,
                $fulfilled,
                $lineStatus,
                $lineNotes,
                $lineId,
                $shipmentId,
            ]);
        }

        $pdo->prepare("
            UPDATE kit_shipments
            SET status = 'dispatched', dispatched_by = ?, dispatched_at = NOW(), carrier = ?, tracking_number = ?
            WHERE id = ? AND status = 'dispatching'
        ")->execute([
            $userId,
            $carrier !== '' ? $carrier : null,
            $trackingNumber !== '' ? $trackingNumber : null,
            $shipmentId,
        ]);
        $message = $issueCount > 0
            ? "Shipment dispatched with {$issueCount} item issue(s)."
            : 'Shipment dispatched.';
        deployment_shipment_add_event($shipmentId, $userId, 'dispatched', $message, ['issue_count' => $issueCount]);
        deployment_shipment_publish_activity($shipmentId, 'dispatched', $message, ['actor_user_id' => $userId]);
        return deployment_shipment_get($shipmentId) ?? [];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE kit_shipments SET status = 'preparing' WHERE id = ? AND status = 'dispatching'")
            ->execute([$shipmentId]);
        deployment_shipment_add_event($shipmentId, $userId, 'dispatch_failed', 'Shipment allocation did not complete.');
        throw $e;
    }
}

function deployment_shipment_receive(int $shipmentId, int $userId, array $receivedQuantities = [], string $notes = ''): array
{
    $notes = deployment_shipment_validate_text($notes, 500, 'Receipt notes');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $shipmentStmt = $pdo->prepare('SELECT status FROM kit_shipments WHERE id = ? FOR UPDATE');
        $shipmentStmt->execute([$shipmentId]);
        $shipmentStatus = $shipmentStmt->fetchColumn();
        if ($shipmentStatus === false) {
            throw new NotFoundException('Shipment not found.');
        }
        if (!in_array($shipmentStatus, ['dispatched', 'partially_received'], true)) {
            throw new ConflictException('Only dispatched shipments can be received.');
        }

        $lineStmt = $pdo->prepare("
            SELECT id, quantity_fulfilled, quantity_received
            FROM kit_shipment_items
            WHERE kit_shipment_id = ?
            ORDER BY id
            FOR UPDATE
        ");
        $lineStmt->execute([$shipmentId]);
        $lines = $lineStmt->fetchAll() ?: [];
        if (empty($lines)) {
            throw new ConflictException('Shipment has no items to receive.');
        }

        $allReceived = true;
        $noteValue = $notes !== '' ? $notes : null;
        $lineUpdate = $pdo->prepare("
            UPDATE kit_shipment_items
            SET quantity_received = ?, received_notes = COALESCE(?, received_notes)
            WHERE id = ? AND kit_shipment_id = ?
        ");
        foreach ($lines as $line) {
            $lineId = (int)$line['id'];
            $fulfilled = (int)$line['quantity_fulfilled'];
            $previouslyReceived = (int)$line['quantity_received'];
            $received = $fulfilled;
            if (array_key_exists($lineId, $receivedQuantities)) {
                $received = qt_parse_int($receivedQuantities[$lineId]);
                if ($received === null) {
                    throw new UnprocessableException('Received quantities must be whole numbers.');
                }
            }
            if ($received < $previouslyReceived || $received > $fulfilled) {
                throw new UnprocessableException('Received quantities must not decrease or exceed fulfilled quantities.');
            }
            if ($received < $fulfilled) {
                $allReceived = false;
            }
            $lineUpdate->execute([$received, $noteValue, $lineId, $shipmentId]);
        }

        $newStatus = $allReceived ? 'received' : 'partially_received';
        $shipmentUpdate = $pdo->prepare("
            UPDATE kit_shipments
            SET status = ?, received_at = CASE WHEN ? = 'received' THEN NOW() ELSE received_at END
            WHERE id = ? AND status IN ('dispatched','partially_received')
        ");
        $shipmentUpdate->execute([$newStatus, $newStatus, $shipmentId]);
        if ($shipmentUpdate->rowCount() !== 1) {
            throw new ConflictException('Shipment status changed before receipt could be recorded.');
        }

        $message = $allReceived ? 'Shipment receipt confirmed.' : 'Partial shipment receipt recorded.';
        if ($notes !== '') {
            $message .= ' Note: ' . $notes;
        }
        deployment_shipment_add_event($shipmentId, $userId, $newStatus, $message);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    deployment_shipment_publish_activity($shipmentId, $newStatus, $message, ['actor_user_id' => $userId]);
    return deployment_shipment_get($shipmentId) ?? [];
}

function deployment_shipment_cancel(int $shipmentId, int $userId, string $reason): array
{
    $reason = deployment_shipment_validate_text($reason, 500, 'Cancellation reason');
    if ($reason === '') {
        throw new UnprocessableException('A cancellation reason is required.');
    }
    $stmt = db()->prepare("
        UPDATE kit_shipments
        SET status = 'cancelled'
        WHERE id = ? AND status IN ('pending','preparing')
    ");
    $stmt->execute([$shipmentId]);
    if ($stmt->rowCount() !== 1) {
        deployment_shipment_fail_transition($shipmentId, 'Only pending or preparing requests can be cancelled.');
    }
    deployment_shipment_add_event($shipmentId, $userId, 'cancelled', 'Shipment request cancelled. Reason: ' . $reason);
    deployment_shipment_publish_activity($shipmentId, 'cancelled', 'Shipment request cancelled.', ['actor_user_id' => $userId]);
    return deployment_shipment_get($shipmentId) ?? [];
}
