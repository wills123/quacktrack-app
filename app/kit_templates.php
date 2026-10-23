<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/SnipeItClient.php';
require_once __DIR__ . '/includes/kit_shipment_service.php';
require_login();
require_permission('kit.view');

$pdo = db();
$current_user_id = (int)($_SESSION['uid'] ?? 0);
$can_manage           = has_permission('kit.manage');
$can_ship             = has_permission('kit.shipments.create');
$can_view_shipments   = has_permission('kit.shipments.view');
$can_manage_shipments = has_permission('kit.shipments.manage');
$canApproveDeploymentShipments = has_permission('deployment.shipments.approve');
$can_receive_deployment_shipments = $can_manage_shipments || has_permission('deployment.shipments.receive');

// ── POST actions ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = req_raw_string($_POST, 'action');

    // ── Deployment shipment lifecycle ──────────────────────────────────────
    if ($action === 'approve_deployment_shipment') {
        require_permission('deployment.shipments.approve');
        $shipment_id = req_int($_POST, 'shipment_id');

        try {
            deployment_shipment_approve($shipment_id, $current_user_id, req_raw_string($_POST, 'approval_reason'));
            flash('Deployment shipment request approved for preparation.', 'success');
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('deployment_shipment_approval_failed', $e, ['shipment_id' => $shipment_id]);
            flash('Unable to approve this shipment request. Please review its current status and try again.', 'error');
        }
        header('Location: /kits?tab=shipments&shipment=' . $shipment_id);
        exit;
    }

    if ($action === 'decline_deployment_shipment') {
        require_permission('deployment.shipments.approve');
        $shipment_id = req_int($_POST, 'shipment_id');

        try {
            deployment_shipment_decline($shipment_id, $current_user_id, req_raw_string($_POST, 'decline_reason'));
            flash('Deployment shipment request declined.', 'success');
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('deployment_shipment_decline_failed', $e, ['shipment_id' => $shipment_id]);
            flash('Unable to decline this shipment request. A decline reason is required.', 'error');
        }
        header('Location: /kits?tab=shipments&shipment=' . $shipment_id);
        exit;
    }

    if ($action === 'dispatch_deployment_shipment') {
        require_permission('kit.shipments.create');
        $shipment_id = req_int($_POST, 'shipment_id');
        $serials = [];
        $serials_raw = req_string($_POST, 'snipe_serials');
        if ($serials_raw !== '') {
            $decoded = json_decode($serials_raw, true);
            if (is_array($decoded)) {
                $serials = $decoded;
            }
        }

        try {
            deployment_shipment_dispatch(
                $shipment_id,
                $current_user_id,
                $serials,
                req_raw_string($_POST, 'carrier'),
                req_raw_string($_POST, 'tracking_number')
            );
            flash('Deployment shipment dispatched.', 'success');
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('deployment_shipment_dispatch_failed', $e, ['shipment_id' => $shipment_id]);
            flash('Unable to dispatch this shipment. Please review its approval status and try again.', 'error');
        }
        header('Location: /kits?tab=shipments&shipment=' . $shipment_id);
        exit;
    }

    if ($action === 'receive_deployment_shipment') {
        if (!$can_receive_deployment_shipments) {
            require_permission('deployment.shipments.receive');
        }
        $shipment_id = req_int($_POST, 'shipment_id');
        $quantities = [];
        foreach ((array)($_POST['quantity_received'] ?? []) as $line_id => $quantity) {
            $quantities[(int)$line_id] = (int)$quantity;
        }

        try {
            deployment_shipment_receive($shipment_id, $current_user_id, $quantities, req_raw_string($_POST, 'receipt_notes'));
            flash('Deployment shipment receipt recorded.', 'success');
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('deployment_shipment_receipt_failed', $e, ['shipment_id' => $shipment_id]);
            flash('Unable to record this shipment receipt. Check the received quantities and try again.', 'error');
        }
        header('Location: /kits?tab=shipments&shipment=' . $shipment_id);
        exit;
    }

    // Create template
    if ($action === 'create_template') {
        require_permission('kit.manage');
        $name = req_string($_POST, 'name');
        $description = req_string($_POST, 'description');

        if ($name === '') {
            flash('Kit name is required.', 'error');
            header('Location: /kits');
            exit;
        }
        if (strlen($name) > 150) {
            flash('Kit name must be 150 characters or fewer.', 'error');
            header('Location: /kits');
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO kit_templates (name, description, is_active, created_by, updated_by)
             VALUES (?, ?, 1, ?, ?)'
        );
        $stmt->execute([$name, $description ?: null, $current_user_id, $current_user_id]);
        $newId = (int)$pdo->lastInsertId();

        flash('Kit "' . $name . '" created.', 'success');
        header('Location: /kits?id=' . $newId);
        exit;
    }

    // Update template name / description
    if ($action === 'update_template') {
        require_permission('kit.manage');
        $id = req_int($_POST, 'id');
        $name = req_string($_POST, 'name');
        $description = req_string($_POST, 'description');

        if ($id <= 0 || $name === '') {
            flash('Invalid request.', 'error');
            header('Location: /kits');
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE kit_templates SET name = ?, description = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([$name, $description ?: null, $current_user_id, $id]);

        flash('Kit updated.', 'success');
        header('Location: /kits?id=' . $id);
        exit;
    }

    // Toggle active / archived
    if ($action === 'toggle_active') {
        require_permission('kit.manage');
        $id = req_int($_POST, 'id');
        if ($id <= 0) {
            flash('Invalid request.', 'error');
            header('Location: /kits');
            exit;
        }
        $stmt = $pdo->prepare(
            'UPDATE kit_templates SET is_active = 1 - is_active, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([$current_user_id, $id]);
        flash('Kit status updated.', 'success');
        header('Location: /kits');
        exit;
    }

    // ── Create shipment (full bulk-checkout on create) ────────────────────────
    if ($action === 'create_shipment') {
        require_permission('kit.shipments.create');

        $kit_id   = req_int($_POST, 'kit_template_id');
        $store_id = req_int($_POST, 'store_id');
        $notes    = req_string($_POST, 'notes');

        // Serial numbers entered by the tech: JSON map of kit_item_id => serial string
        // e.g. {"42": "SN123456", "43": "SN789012"}
        $snipe_serials_raw = req_string($_POST, 'snipe_serials');
        $snipe_serials = [];
        if ($snipe_serials_raw !== '') {
            $decoded = json_decode($snipe_serials_raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $kit_item_id => $serial) {
                    $serial = trim((string)$serial);
                    if ($serial !== '') {
                        $snipe_serials[(int)$kit_item_id] = $serial;
                    }
                }
            }
        }

        if ($kit_id <= 0 || $store_id <= 0) {
            flash('Please select a kit and a destination store.', 'error');
            header('Location: /kits');
            exit;
        }

        // Verify kit exists and is active
        $stmt = $pdo->prepare('SELECT id, name FROM kit_templates WHERE id = ? AND is_active = 1');
        $stmt->execute([$kit_id]);
        $kit = $stmt->fetch();
        if (!$kit) {
            flash('Kit template not found or is archived.', 'error');
            header('Location: /kits');
            exit;
        }

        // Verify store exists
        $stmt = $pdo->prepare('SELECT id, name FROM stores WHERE id = ?');
        $stmt->execute([$store_id]);
        $store = $stmt->fetch();
        if (!$store) {
            flash('Store not found.', 'error');
            header('Location: /kits');
            exit;
        }

        // Load kit items
        $stmt = $pdo->prepare(
            'SELECT kti.*, p.name AS product_name
             FROM kit_template_items kti
             LEFT JOIN products p ON kti.product_id = p.id
             WHERE kti.kit_template_id = ?
             ORDER BY kti.sort_order ASC, kti.id ASC'
        );
        $stmt->execute([$kit_id]);
        $kit_items = $stmt->fetchAll() ?: [];

        if (empty($kit_items)) {
            flash('Cannot ship an empty kit. Add items to the kit template first.', 'error');
            header('Location: /kits?id=' . $kit_id);
            exit;
        }

        // ── Create the shipment record ─────────────────────────────────────
        $stmt = $pdo->prepare(
            'INSERT INTO kit_shipments
                (kit_template_id, store_id, status, notes, kit_name_snapshot, dispatched_by, dispatched_at, created_by)
             VALUES (?, ?, "dispatched", ?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([$kit_id, $store_id, $notes ?: null, $kit['name'], $current_user_id, $current_user_id]);
        $shipment_id = (int)$pdo->lastInsertId();

        // ── Try to find Snipe-IT location for the store ────────────────────
        // Priority: store-level mapping → region-level mapping → fallback to user
        $snipe_location_id = null;

        // 1. Check store-level mapping first
        $stmt = $pdo->prepare(
            'SELECT snipe_location_id FROM stores WHERE id = ? AND snipe_location_id IS NOT NULL'
        );
        $stmt->execute([$store_id]);
        $store_map = $stmt->fetch();
        if ($store_map) {
            $snipe_location_id = (int)$store_map['snipe_location_id'];
        }

        // 2. Fall back to region-level mapping
        if (!$snipe_location_id) {
            $stmt = $pdo->prepare(
                'SELECT m.snipe_location_id
                 FROM stores s
                 JOIN snipe_it_location_mappings m ON m.region_id = s.region_id
                 WHERE s.id = ?
                 LIMIT 1'
            );
            $stmt->execute([$store_id]);
            $region_map = $stmt->fetch();
            if ($region_map) {
                $snipe_location_id = (int)$region_map['snipe_location_id'];
            }
        }

        // ── Look up the dispatcher's Snipe-IT user ID ─────────────────────
        $snipe_user_id = null;
        $stmt = $pdo->prepare('SELECT snipe_user_id FROM users WHERE id = ?');
        $stmt->execute([$current_user_id]);
        $dispatcher = $stmt->fetch();
        if ($dispatcher && $dispatcher['snipe_user_id']) {
            $snipe_user_id = (int)$dispatcher['snipe_user_id'];
        }

        // ── Process each kit item ──────────────────────────────────────────
        $snipe_enabled = env('SNIPE_IT_ENABLED', '0') === '1';
        $snipe_client  = null;
        if ($snipe_enabled) {
            try {
                $snipe_client = SnipeItClient::getInstance();
                $snipe_client->setCurrentUserId($current_user_id);
            } catch (Throwable $e) {
                qt_rethrow_bad_input($e);
                Logger::error('kit_shipment_snipe_init', $e);
            }
        }

        $all_fulfilled = true;
        foreach ($kit_items as $item) {
            $line_status    = 'pending';
            $fulfilled      = 0;
            $asset_ids_out  = [];
            $line_notes     = null;

            if ($item['item_type'] === 'product') {
                // Catalog / van-stock item — mark fulfilled (physical delivery, no Snipe-IT)
                $line_status = 'fulfilled';
                $fulfilled   = (int)$item['quantity'];
            } elseif ($item['item_type'] === 'snipe_model' && $snipe_client && $item['snipe_model_id']) {
                $planned         = (int)$item['quantity'];
                $kit_item_id     = (int)$item['id'];
                $provided_serial = $snipe_serials[$kit_item_id] ?? null;
                $skip_checkout   = false;
                $to_checkout     = [];

                // ── Determine which assets to check out ────────────────────
                // If the tech supplied a serial for this kit item, look it up
                // directly.  Otherwise fall back to auto-grabbing any available
                // unassigned asset for that model.
                if ($provided_serial !== null) {
                    $asset = $snipe_client->getAssetBySerial($provided_serial);

                    if (!$asset) {
                        $line_status   = 'unavailable';
                        $all_fulfilled = false;
                        $line_notes    = 'Serial / asset tag "' . $provided_serial . '" not found in Snipe-IT.';
                        $skip_checkout = true;
                    } elseif ((int)(($asset['model']['id'] ?? 0)) !== (int)$item['snipe_model_id']) {
                        $line_status   = 'unavailable';
                        $all_fulfilled = false;
                        $line_notes    = 'Asset "' . $provided_serial . '" is not the expected model (' . ($item['snipe_model_name'] ?? '') . ').';
                        $skip_checkout = true;
                    } elseif (!empty($asset['assigned_to']) || ($asset['status_label']['status_meta'] ?? '') === 'deployed') {
                        $line_status   = 'unavailable';
                        $all_fulfilled = false;
                        $line_notes    = 'Asset "' . $provided_serial . '" is already checked out in Snipe-IT.';
                        $skip_checkout = true;
                    } else {
                        $to_checkout = [$asset];
                    }
                } else {
                    // No serial provided — auto-grab available assets for this model
                    $available = $snipe_client->listAssets([
                        'model_id' => $item['snipe_model_id'],
                        'limit'    => 50,
                        'offset'   => 0,
                    ]);

                    $rows = $available['rows'] ?? [];

                    Logger::info('kit_dispatch_debug', 'assets for model', [
                        'model_id'   => $item['snipe_model_id'],
                        'total_rows' => count($rows),
                        'statuses'   => array_map(fn($a) => [
                            'id'          => $a['id'],
                            'name'        => $a['name'] ?? '',
                            'assigned_to' => $a['assigned_to'] ?? null,
                            'status_meta' => $a['status_label']['status_meta'] ?? '',
                            'status_name' => $a['status_label']['name'] ?? '',
                        ], array_slice($rows, 0, 5)),
                    ]);

                    $unassigned  = array_filter($rows, function ($a) {
                        return empty($a['assigned_to']) && ($a['status_label']['status_meta'] ?? '') !== 'deployed';
                    });
                    $to_checkout = array_slice(array_values($unassigned), 0, $planned);
                }

                if (!$skip_checkout) {
                    foreach ($to_checkout as $asset) {
                        $asset_id = (int)$asset['id'];

                        if ($snipe_location_id) {
                            $checkout_payload = [
                                'checkout_to_type'  => 'location',
                                'assigned_location' => $snipe_location_id,
                                'note'              => 'Kit shipment #' . $shipment_id . ' → ' . $store['name'],
                            ];
                        } elseif ($snipe_user_id) {
                            $checkout_payload = [
                                'checkout_to_type' => 'user',
                                'assigned_user'    => $snipe_user_id,
                                'note'             => 'Kit shipment #' . $shipment_id . ' → ' . $store['name'] . ' (no location mapping — assigned to dispatcher)',
                            ];
                        } else {
                            $line_notes = 'No Snipe-IT location mapped for this store and dispatcher has no Snipe-IT account linked. Go to Admin → Regions to map a Snipe-IT location.';
                            break;
                        }

                        $result = $snipe_client->checkoutAsset($asset_id, $checkout_payload);

                        Logger::info('kit_dispatch_checkout', 'checkout result', [
                            'asset_id' => $asset_id,
                            'serial'   => $provided_serial,
                            'payload'  => $checkout_payload,
                            'result'   => $result,
                        ]);

                        if ($result && ($result['status'] ?? '') === 'success') {
                            $asset_ids_out[] = $asset_id;
                            $fulfilled++;
                        }
                    }

                    if ($fulfilled === 0) {
                        $line_status   = 'unavailable';
                        $all_fulfilled = false;
                        $line_notes    = $line_notes ?? 'No available assets found in Snipe-IT for this model.';
                    } elseif ($fulfilled < $planned) {
                        $line_status   = 'partial';
                        $all_fulfilled = false;
                        $line_notes    = "Only {$fulfilled} of {$planned} assets were available.";
                    } else {
                        $line_status = 'fulfilled';
                    }
                }
            } else {
                // Snipe-IT model but Snipe-IT not available — mark unavailable
                $line_status   = 'unavailable';
                $all_fulfilled = false;
                $line_notes    = 'Snipe-IT integration not active.';
            }

            // Insert shipment line item
            $stmt = $pdo->prepare(
                'INSERT INTO kit_shipment_items
                    (kit_shipment_id, item_type, product_id, snipe_model_id, snipe_model_name,
                     quantity_planned, snipe_asset_ids, quantity_fulfilled, line_status, line_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $shipment_id,
                $item['item_type'],
                $item['product_id'] ?: null,
                $item['snipe_model_id'] ?: null,
                $item['snipe_model_name'] ?: ($item['product_name'] ?? null),
                (int)$item['quantity'],
                empty($asset_ids_out) ? null : json_encode($asset_ids_out),
                $fulfilled,
                $line_status,
                $line_notes,
            ]);
        }

        $msg = $all_fulfilled
            ? 'Shipment dispatched to ' . $store['name'] . '.'
            : 'Shipment dispatched to ' . $store['name'] . ' — some items were unavailable.';

        flash($msg, $all_fulfilled ? 'success' : 'warning');
        header('Location: /kits?tab=shipments&shipment=' . $shipment_id);
        exit;
    }

    // ── Cancel shipment ───────────────────────────────────────────────────────
    if ($action === 'cancel_shipment') {
        require_permission('kit.shipments.manage');

        $shipment_id = req_int($_POST, 'shipment_id');
        if ($shipment_id <= 0) {
            flash('Invalid shipment.', 'error');
            header('Location: /kits?tab=shipments');
            exit;
        }

        $stmt = $pdo->prepare(
            'UPDATE kit_shipments SET status = "cancelled" WHERE id = ? AND status = "dispatched"'
        );
        $stmt->execute([$shipment_id]);

        flash('Shipment cancelled.', 'success');
        header('Location: /kits?tab=shipments');
        exit;
    }

    // ── Mark shipment received ────────────────────────────────────────────────
    if ($action === 'mark_received') {
        require_permission('kit.shipments.manage');

        $shipment_id = req_int($_POST, 'shipment_id');
        if ($shipment_id <= 0) {
            flash('Invalid shipment.', 'error');
            header('Location: /kits?tab=shipments');
            exit;
        }

        try {
            deployment_shipment_receive($shipment_id, $current_user_id);
            flash('Shipment marked as received.', 'success');
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('kit_shipment_receipt_failed', $e, ['shipment_id' => $shipment_id]);
            flash('Unable to mark this shipment as received. Please review its current status.', 'error');
        }
        header('Location: /kits?tab=shipments&shipment=' . $shipment_id);
        exit;
    }

    header('Location: /kits');
    exit;
}

// ── Data load ────────────────────────────────────────────────────────────────

// All templates with item count
$templates = $pdo->query(
    'SELECT kt.id, kt.name, kt.description, kt.is_active, kt.created_at, kt.updated_at,
            COUNT(kti.id) AS item_count
     FROM kit_templates kt
     LEFT JOIN kit_template_items kti ON kti.kit_template_id = kt.id
     GROUP BY kt.id
     ORDER BY kt.is_active DESC, kt.name ASC'
)->fetchAll() ?: [];

// Active template detail (if ?id= is set)
$active_template = null;
$active_items = [];
$active_id = req_int($_GET, 'id');

if ($active_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM kit_templates WHERE id = ?');
    $stmt->execute([$active_id]);
    $active_template = $stmt->fetch() ?: null;

    if ($active_template) {
        $stmt = $pdo->prepare(
            'SELECT kti.*, p.name AS product_name, p.product_id AS product_sku,
                    p.manufacturer AS product_manufacturer, p.is_tracked_asset
             FROM kit_template_items kti
             LEFT JOIN products p ON kti.product_id = p.id
             WHERE kti.kit_template_id = ?
             ORDER BY kti.sort_order ASC, kti.id ASC'
        );
        $stmt->execute([$active_id]);
        $active_items = $stmt->fetchAll() ?: [];
    }
}

// ── Stores list (for ship modal) ─────────────────────────────────────────────
$stores = $can_ship
    ? ($pdo->query('SELECT id, name FROM stores ORDER BY name ASC')->fetchAll() ?: [])
    : [];

// ── Shipments list ────────────────────────────────────────────────────────────
$shipments       = [];
$active_shipment = null;

if ($can_view_shipments) {
    $shipments = $pdo->query(
        "SELECT ks.id, ks.kit_name_snapshot, ks.status, ks.approval_status, ks.notes,
                ks.deployment_id, ks.destination_name, ks.requested_at, ks.needed_by,
                ks.dispatched_at, ks.received_at, ks.created_at,
                s.name AS store_name, dp.site_name AS deployment_site_name,
                COALESCE(NULLIF(CONCAT_WS(' ', requester.first_name, requester.last_name), ''), requester.username, requester.email) AS requested_by_name,
                CONCAT(u.first_name, ' ', u.last_name) AS dispatched_by_name,
                COUNT(ksi.id) AS item_count,
                SUM(CASE WHEN ksi.line_status = 'fulfilled' THEN 1 ELSE 0 END) AS fulfilled_count,
                SUM(CASE WHEN ksi.line_status IN ('partial','unavailable') THEN 1 ELSE 0 END) AS issue_count
         FROM kit_shipments ks
         LEFT JOIN stores s ON ks.store_id = s.id
         LEFT JOIN deployment_projects dp ON ks.deployment_id = dp.id
         LEFT JOIN users requester ON ks.requested_by = requester.id
         LEFT JOIN users u ON ks.dispatched_by = u.id
         LEFT JOIN kit_shipment_items ksi ON ksi.kit_shipment_id = ks.id
         GROUP BY ks.id
         ORDER BY FIELD(ks.status, 'pending', 'preparing', 'dispatching', 'dispatched', 'partially_received', 'received', 'declined', 'cancelled'),
                  ks.needed_by IS NULL, ks.needed_by ASC, ks.created_at DESC, ks.id DESC
         LIMIT 100"
    )->fetchAll() ?: [];

    // Active shipment detail (if ?shipment= is set)
    $active_shipment_id = req_int($_GET, 'shipment');
    if ($active_shipment_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT ks.*, s.name AS store_name, dp.site_name AS deployment_site_name,
                    COALESCE(NULLIF(CONCAT_WS(' ', requester.first_name, requester.last_name), ''), requester.username, requester.email) AS requested_by_name,
                    COALESCE(NULLIF(CONCAT_WS(' ', approver.first_name, approver.last_name), ''), approver.username, approver.email) AS approved_by_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS dispatched_by_name
             FROM kit_shipments ks
             LEFT JOIN stores s ON ks.store_id = s.id
             LEFT JOIN deployment_projects dp ON ks.deployment_id = dp.id
             LEFT JOIN users requester ON ks.requested_by = requester.id
             LEFT JOIN users approver ON ks.approved_by = approver.id
             LEFT JOIN users u ON ks.dispatched_by = u.id
             WHERE ks.id = ?"
        );
        $stmt->execute([$active_shipment_id]);
        $active_shipment = $stmt->fetch() ?: null;

        if ($active_shipment) {
            $stmt = $pdo->prepare(
                'SELECT ksi.*, p.name AS product_name
                 FROM kit_shipment_items ksi
                 LEFT JOIN products p ON ksi.product_id = p.id
                 WHERE ksi.kit_shipment_id = ?
                 ORDER BY ksi.id ASC'
            );
            $stmt->execute([$active_shipment_id]);
            $active_shipment['items'] = $stmt->fetchAll() ?: [];

            $stmt = $pdo->prepare(
                "SELECT kse.*, COALESCE(NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.username, u.email) AS actor_name
                 FROM kit_shipment_events kse
                 LEFT JOIN users u ON kse.actor_user_id = u.id
                 WHERE kse.kit_shipment_id = ?
                 ORDER BY kse.created_at DESC, kse.id DESC"
            );
            $stmt->execute([$active_shipment_id]);
            $active_shipment['events'] = $stmt->fetchAll() ?: [];
        }
    }
}

$active_tab = req_raw_string($_GET, 'tab', 'templates');

$title        = 'Kit Templates';
$main_class   = 'main-content';
$extra_styles = ['/assets/opdash.css'];
require __DIR__ . '/views/kit_templates.view.php';
