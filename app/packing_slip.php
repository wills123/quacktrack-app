<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/SnipeItClient.php';
require_once __DIR__ . '/includes/AcumaticaClient.php';
require_login();
require_permission('kit.shipments.view');

$pdo         = db();
$shipment_id = req_int($_GET, 'shipment');

if ($shipment_id <= 0) {
    header('Location: /kits?tab=shipments');
    exit;
}

// ── Load shipment ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT ks.*,
            s.name  AS store_name,
            s.address AS store_address,
            r.name  AS region_name,
            CONCAT(u.first_name, ' ', u.last_name) AS dispatched_by_name
     FROM kit_shipments ks
     LEFT JOIN stores s ON ks.store_id = s.id
     LEFT JOIN regions r ON s.region_id = r.id
     LEFT JOIN users u ON ks.dispatched_by = u.id
     WHERE ks.id = ?"
);
$stmt->execute([$shipment_id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    header('Location: /kits?tab=shipments');
    exit;
}

// ── Load line items ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT ksi.*, p.name AS product_name, p.product_id AS product_sku,
            p.manufacturer AS product_manufacturer,
            p.acumatica_inventory_id, p.acumatica_default_vendor_id
     FROM kit_shipment_items ksi
     LEFT JOIN products p ON ksi.product_id = p.id
     WHERE ksi.kit_shipment_id = ?
     ORDER BY ksi.id ASC'
);
$stmt->execute([$shipment_id]);
$line_items = $stmt->fetchAll() ?: [];

// ── Fetch live Acumatica stock levels for any mapped line items ──────────────
// Read-only: shows what's actually on hand right now so this is a real packing
// list, not just what was planned. Never creates or changes anything here.
$acumatica_enabled = env('ACUMATICA_ENABLED', '0') === '1';
$acumatica_levels = [];
if ($acumatica_enabled) {
    $inventory_ids = array_values(array_unique(array_filter(array_column($line_items, 'acumatica_inventory_id'))));
    if (!empty($inventory_ids)) {
        try {
            $acumatica_client = AcumaticaClient::getInstance();
            $acumatica_client->setCurrentUserId((int)($_SESSION['uid'] ?? 0));
            $acumatica_levels = $acumatica_client->getStockLevels($inventory_ids);
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('packing_slip_acumatica', $e);
        }
    }
}

// ── Fetch Snipe-IT asset details for checked-out devices ─────────────────────
// Build a map of asset_id => { serial, asset_tag, name } for display
$asset_details = [];

$snipe_enabled = env('SNIPE_IT_ENABLED', '0') === '1';
if ($snipe_enabled) {
    // Collect all asset IDs across all line items
    $all_asset_ids = [];
    foreach ($line_items as $line) {
        if ($line['snipe_asset_ids']) {
            $ids = json_decode($line['snipe_asset_ids'], true) ?? [];
            foreach ($ids as $id) {
                $all_asset_ids[] = (int)$id;
            }
        }
    }
    $all_asset_ids = array_unique($all_asset_ids);

    if (!empty($all_asset_ids)) {
        try {
            $client = SnipeItClient::getInstance();
            $client->setCurrentUserId((int)($_SESSION['uid'] ?? 0));
            // A dispatched shipment's devices rarely change, so keep the four printed fields for
            // 15 minutes. Reprinting a slip (or opening it again) then costs no Snipe-IT calls.
            foreach ($all_asset_ids as $asset_id) {
                $details = $client->cachedGet('packing_asset_' . $asset_id, static function () use ($client, $asset_id): ?array {
                    $asset = $client->getAsset($asset_id);
                    return $asset ? [
                        'name'      => $asset['name']      ?? '',
                        'serial'    => $asset['serial']    ?? '',
                        'asset_tag' => $asset['asset_tag'] ?? '',
                        'model'     => $asset['model']['name'] ?? '',
                    ] : null;
                }, 900);
                if ($details !== null) {
                    $asset_details[$asset_id] = $details;
                }
            }
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            Logger::error('packing_slip_snipe', $e);
        }
    }
}

$app_name = env('APP_NAME', 'Quack Track');
$slip_number = 'PS-' . str_pad((string)$shipment_id, 6, '0', STR_PAD_LEFT);
$dispatch_date = date('F j, Y', strtotime($shipment['dispatched_at'] ?? $shipment['created_at']));
$destination_name = $shipment['destination_name'] ?: ($shipment['store_name'] ?: 'Destination pending');
$destination_address = $shipment['destination_address'] ?: ($shipment['store_address'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title>Packing Slip <?= h($slip_number) ?> — <?= h($app_name) ?></title>
<style>
/* ── Reset & base ─────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 13px; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: #111;
  background: #fff;
  padding: 32px;
  max-width: 800px;
  margin: 0 auto;
}

/* ── Screen-only controls ────────────────────────────────────────────────── */
.screen-only {
  margin-bottom: 24px;
  display: flex;
  gap: 10px;
  align-items: center;
}
.btn-print {
  background: #2563eb;
  color: #fff;
  border: none;
  padding: 8px 20px;
  border-radius: 6px;
  font-size: 0.9rem;
  cursor: pointer;
  font-weight: 600;
}
.btn-print:hover { background: #1d4ed8; }
.btn-back {
  color: #2563eb;
  text-decoration: none;
  font-size: 0.9rem;
}
.btn-back:hover { text-decoration: underline; }

/* ── Slip header ─────────────────────────────────────────────────────────── */
.slip-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  border-bottom: 2px solid #111;
  padding-bottom: 16px;
  margin-bottom: 20px;
}
.slip-brand {
  display: flex;
  align-items: center;
  gap: 10px;
}
.slip-brand img {
  height: 38px;
  width: auto;
}
.slip-brand-name {
  font-size: 1.3rem;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.slip-title {
  text-align: right;
}
.slip-title h1 {
  font-size: 1.4rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.slip-number {
  font-size: 0.85rem;
  color: #555;
  margin-top: 3px;
}

/* ── Address block ───────────────────────────────────────────────────────── */
.slip-addresses {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  margin-bottom: 20px;
}
.address-block h3 {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #666;
  margin-bottom: 6px;
  border-bottom: 1px solid #ddd;
  padding-bottom: 4px;
}
.address-block p {
  font-size: 0.9rem;
  line-height: 1.5;
}
.address-block strong {
  font-size: 1rem;
}

/* ── Meta row ────────────────────────────────────────────────────────────── */
.slip-meta {
  display: flex;
  gap: 32px;
  flex-wrap: wrap;
  background: #f5f5f5;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 10px 16px;
  margin-bottom: 20px;
  font-size: 0.85rem;
}
.slip-meta-item { display: flex; flex-direction: column; gap: 2px; }
.slip-meta-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: .06em; color: #666; }
.slip-meta-value { font-weight: 600; }

/* ── Items table ─────────────────────────────────────────────────────────── */
.items-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 24px;
  font-size: 0.88rem;
}
.items-table th {
  background: #111;
  color: #fff;
  text-align: left;
  padding: 7px 10px;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-weight: 600;
}
.items-table td {
  padding: 8px 10px;
  border-bottom: 1px solid #e5e5e5;
  vertical-align: top;
}
.items-table tr:last-child td { border-bottom: none; }
.items-table tr:nth-child(even) td { background: #fafafa; }
.item-name { font-weight: 600; }
.item-sub  { font-size: 0.78rem; color: #555; margin-top: 2px; }
.badge-source {
  display: inline-block;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 3px;
  letter-spacing: 0.04em;
}
.badge-catalog { background: #dbeafe; color: #1d4ed8; }
.badge-snipe   { background: #fed7aa; color: #c2410c; }
.asset-row {
  font-size: 0.78rem;
  color: #333;
  margin-top: 3px;
  padding: 3px 6px;
  background: #f0f4ff;
  border-radius: 3px;
  border-left: 3px solid #2563eb;
}
.asset-row + .asset-row { margin-top: 2px; }
.status-fulfilled  { color: #16a34a; font-weight: 600; }
.status-partial    { color: #d97706; font-weight: 600; }
.status-unavailable{ color: #dc2626; font-weight: 600; }
.qty-cell { text-align: center; }

/* ── Summary ─────────────────────────────────────────────────────────────── */
.slip-summary {
  border-top: 2px solid #111;
  padding-top: 12px;
  display: flex;
  justify-content: flex-end;
  gap: 24px;
  font-size: 0.88rem;
  margin-bottom: 28px;
}
.summary-stat { text-align: right; }
.summary-stat .label { color: #555; font-size: 0.78rem; }
.summary-stat .value { font-size: 1.1rem; font-weight: 700; }

/* ── Notes ───────────────────────────────────────────────────────────────── */
.slip-notes {
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 10px 14px;
  margin-bottom: 24px;
  font-size: 0.88rem;
}
.slip-notes h4 {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: #666;
  margin-bottom: 5px;
}

/* ── Signature area ──────────────────────────────────────────────────────── */
.slip-signatures {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 32px;
  margin-bottom: 28px;
}
.sig-block { border-top: 1px solid #999; padding-top: 6px; }
.sig-label { font-size: 0.75rem; color: #666; }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.slip-footer {
  text-align: center;
  font-size: 0.75rem;
  color: #888;
  border-top: 1px solid #ddd;
  padding-top: 12px;
}

/* ── Confirm/notice modal (replaces native confirm()/alert()) ───────────────── */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(17, 17, 17, 0.5);
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal-overlay.open { display: flex; }
.modal-box {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.25);
  max-width: 420px;
  width: calc(100% - 40px);
  padding: 20px 22px;
}
.modal-box p {
  font-size: 0.9rem;
  line-height: 1.5;
  color: #222;
  white-space: pre-line;
}
.modal-box.is-error p { color: #991b1b; }
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}
.modal-btn {
  border: none;
  border-radius: 6px;
  padding: 7px 16px;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
}
.modal-btn-secondary { background: #e5e5e5; color: #222; }
.modal-btn-secondary:hover { background: #d4d4d4; }
.modal-btn-primary { background: #2563eb; color: #fff; }
.modal-btn-primary:hover { background: #1d4ed8; }

/* ── Print styles ────────────────────────────────────────────────────────── */
@media print {
  body { padding: 0; max-width: none; }
  .screen-only { display: none !important; }
  .modal-overlay { display: none !important; }
  .items-table th { background: #111 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .slip-meta { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .asset-row { background: #f0f4ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  @page { margin: 1.5cm 2cm; size: A4 portrait; }
}
</style>
</head>
<body>

<!-- Screen controls (hidden on print) -->
<div class="screen-only">
  <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
  <a href="/kits?tab=shipments&shipment=<?= (int)$shipment_id ?>" class="btn-back">← Back to Shipment</a>
</div>

<!-- ── Slip header ──────────────────────────────────────────────────────────── -->
<div class="slip-header">
  <div class="slip-brand">
    <img src="/assets/logo2.png" alt="<?= h($app_name) ?> logo">
    <span class="slip-brand-name"><?= h($app_name) ?></span>
  </div>
  <div class="slip-title">
    <h1>Packing Slip</h1>
    <div class="slip-number"><?= h($slip_number) ?></div>
  </div>
</div>

<!-- ── Addresses ────────────────────────────────────────────────────────────── -->
<div class="slip-addresses">
  <div class="address-block">
    <h3>Dispatched By</h3>
    <p>
      <strong><?= h($shipment['dispatched_by_name'] ?? 'Unknown') ?></strong><br>
      <?= h($app_name) ?>
    </p>
  </div>
  <div class="address-block">
    <h3>Ship To</h3>
    <p>
      <strong><?= h($destination_name) ?></strong><br>
      <?php if ($shipment['region_name']) : ?>
            <?= h($shipment['region_name']) ?><br>
      <?php endif; ?>
      <?php if ($destination_address) : ?>
            <?= nl2br(h($destination_address)) ?>
      <?php endif; ?>
    </p>
  </div>
</div>

<!-- ── Meta row ─────────────────────────────────────────────────────────────── -->
<div class="slip-meta">
  <div class="slip-meta-item">
    <span class="slip-meta-label">Slip #</span>
    <span class="slip-meta-value"><?= h($slip_number) ?></span>
  </div>
  <div class="slip-meta-item">
    <span class="slip-meta-label">Kit</span>
    <span class="slip-meta-value"><?= h($shipment['kit_name_snapshot']) ?></span>
  </div>
  <div class="slip-meta-item">
    <span class="slip-meta-label">Dispatch Date</span>
    <span class="slip-meta-value"><?= h($dispatch_date) ?></span>
  </div>
  <div class="slip-meta-item">
    <span class="slip-meta-label">Status</span>
    <span class="slip-meta-value"><?= ucfirst(h($shipment['status'])) ?></span>
  </div>
  <?php if ($shipment['received_at']) : ?>
  <div class="slip-meta-item">
    <span class="slip-meta-label">Received</span>
    <span class="slip-meta-value"><?= h(date('F j, Y', strtotime($shipment['received_at']))) ?></span>
  </div>
  <?php endif; ?>
</div>

<!-- ── Line items table ──────────────────────────────────────────────────────── -->
<?php
$total_planned   = 0;
$total_fulfilled = 0;
?>
<table class="items-table">
  <thead>
    <tr>
      <th style="width:36%">Item</th>
      <th>Source</th>
      <th class="qty-cell">Qty</th>
      <th class="qty-cell">Fulfilled</th>
      <?php if ($acumatica_enabled) : ?>
      <th class="qty-cell">On Hand (Acumatica)</th>
      <?php endif; ?>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($line_items as $line) :
        $total_planned   += (int)$line['quantity_planned'];
        $total_fulfilled += (int)$line['quantity_fulfilled'];
        $is_product = $line['item_type'] === 'product';

      // Collect asset detail rows for this line
        $asset_rows = [];
        if (!$is_product && $line['snipe_asset_ids']) {
            $ids = json_decode($line['snipe_asset_ids'], true) ?? [];
            foreach ($ids as $aid) {
                $aid = (int)$aid;
                if (isset($asset_details[$aid])) {
                    $asset_rows[] = $asset_details[$aid];
                } else {
                  // Fallback if Snipe-IT wasn't reachable — just show the ID
                    $asset_rows[] = ['name' => '', 'serial' => '', 'asset_tag' => '', 'model' => '', '_id' => $aid];
                }
            }
        }

        $status_class = match ($line['line_status']) {
            'fulfilled'   => 'status-fulfilled',
            'partial'     => 'status-partial',
            'unavailable' => 'status-unavailable',
            default       => '',
        };
    ?>
    <tr>
      <td>
        <div class="item-name">
          <?php if ($is_product) : ?>
                <?= h($line['product_name'] ?? 'Product #' . $line['product_id']) ?>
          <?php else : ?>
              <?= h($line['snipe_model_name'] ?? 'Snipe-IT Model #' . $line['snipe_model_id']) ?>
          <?php endif; ?>
        </div>
        <?php if ($is_product && ($line['product_sku'] || $line['product_manufacturer'])) : ?>
          <div class="item-sub">
            <?php if ($line['product_sku']) :
                ?>SKU: <?= h($line['product_sku']) ?><?php
            endif; ?>
            <?php if ($line['product_sku'] && $line['product_manufacturer']) :
                ?> &middot; <?php
            endif; ?>
            <?php if ($line['product_manufacturer']) :
                ?><?= h($line['product_manufacturer']) ?><?php
            endif; ?>
          </div>
        <?php endif; ?>
        <?php foreach ($asset_rows as $ar) : ?>
          <div class="asset-row">
            <?php
              $parts = [];
            if (!empty($ar['name'])) {
                $parts[] = $ar['name'];
            }
            if (!empty($ar['serial'])) {
                $parts[] = 'S/N: ' . $ar['serial'];
            }
            if (!empty($ar['asset_tag'])) {
                $parts[] = 'Tag: ' . $ar['asset_tag'];
            }
            if (empty($parts) && !empty($ar['_id'])) {
                $parts[] = 'Asset ID: ' . $ar['_id'];
            }
              echo h(implode('  ·  ', $parts));
            ?>
          </div>
        <?php endforeach; ?>
        <?php if ($line['line_notes']) : ?>
          <div class="item-sub" style="margin-top:4px; color:#b45309;">
            ⚠ <?= h($line['line_notes']) ?>
          </div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($is_product) : ?>
          <span class="badge-source badge-catalog">Catalog</span>
        <?php else : ?>
          <span class="badge-source badge-snipe">Snipe-IT</span>
        <?php endif; ?>
      </td>
      <td class="qty-cell"><?= (int)$line['quantity_planned'] ?></td>
      <td class="qty-cell"><?= (int)$line['quantity_fulfilled'] ?></td>
        <?php if ($acumatica_enabled) :
            $inv_id = $line['acumatica_inventory_id'] ?? null;
            $level = $inv_id !== null ? ($acumatica_levels[$inv_id] ?? null) : null;
            $shortfall = $level !== null ? max(0.0, (float)$line['quantity_planned'] - $level['qtyOnHand']) : null;
            ?>
      <td class="qty-cell">
            <?php if ($level === null) : ?>
          <span class="item-sub">&mdash;</span>
            <?php else : ?>
          <span class="<?= $shortfall > 0 ? 'status-unavailable' : 'status-fulfilled' ?>"><?= h((string)(int)$level['qtyOnHand']) ?></span>
                <?php if ($shortfall > 0) : ?>
            <div class="item-sub screen-only">
              short <?= h((string)(int)$shortfall) ?>
                    <?php if (has_permission('acumatica.po.create') && $line['acumatica_default_vendor_id']) : ?>
                &middot; <button type="button" class="btn-back" style="background:none;border:none;cursor:pointer;padding:0;font:inherit;"
                  onclick="acumaticaOrderShortfall(<?= (int)$shipment_id ?>, '<?= h($inv_id) ?>', '<?= h($line['acumatica_default_vendor_id']) ?>', <?= (int)$shortfall ?>, this)">Order shortfall</button>
                    <?php endif; ?>
            </div>
                <?php endif; ?>
            <?php endif; ?>
      </td>
        <?php endif; ?>
      <td>
        <span class="<?= $status_class ?>">
          <?= ucfirst(h($line['line_status'])) ?>
        </span>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- ── Summary ──────────────────────────────────────────────────────────────── -->
<div class="slip-summary">
  <div class="summary-stat">
    <div class="label">Total Items Planned</div>
    <div class="value"><?= $total_planned ?></div>
  </div>
  <div class="summary-stat">
    <div class="label">Total Items Fulfilled</div>
    <div class="value"><?= $total_fulfilled ?></div>
  </div>
</div>

<?php if ($shipment['notes']) : ?>
<!-- ── Notes ────────────────────────────────────────────────────────────────── -->
<div class="slip-notes">
  <h4>Notes</h4>
  <p><?= nl2br(h($shipment['notes'])) ?></p>
</div>
<?php endif; ?>

<!-- ── Signature area ────────────────────────────────────────────────────────── -->
<div class="slip-signatures">
  <div class="sig-block">
    <div class="sig-label">Dispatched by &amp; date</div>
  </div>
  <div class="sig-block">
    <div class="sig-label">Received by &amp; date</div>
  </div>
</div>

<!-- ── Footer ───────────────────────────────────────────────────────────────── -->
<div class="slip-footer">
  Generated by <?= h($app_name) ?> &middot; <?= h($slip_number) ?> &middot; <?= h(date('F j, Y g:i a')) ?>
</div>

<!-- ── Confirm/notice modal (screen-only, replaces native confirm()/alert()) ──── -->
<div class="modal-overlay screen-only" id="appModalOverlay">
  <div class="modal-box" id="appModalBox">
    <p id="appModalMessage"></p>
    <div class="modal-actions" id="appModalActions"></div>
  </div>
</div>

<script>
// Lightweight in-page replacements for the native confirm()/alert() dialogs --
// those block the whole tab (and anything automating it) until dismissed.
function appModalConfirm(message) {
  return new Promise((resolve) => {
    const overlay = document.getElementById('appModalOverlay');
    const box = document.getElementById('appModalBox');
    const actions = document.getElementById('appModalActions');
    box.classList.remove('is-error');
    document.getElementById('appModalMessage').textContent = message;
    actions.innerHTML = '';

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'modal-btn modal-btn-secondary';
    cancelBtn.textContent = 'Cancel';

    const okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.className = 'modal-btn modal-btn-primary';
    okBtn.textContent = 'Create PO';

    function close(result) {
      overlay.classList.remove('open');
      resolve(result);
    }
    cancelBtn.onclick = () => close(false);
    okBtn.onclick = () => close(true);

    actions.appendChild(cancelBtn);
    actions.appendChild(okBtn);
    overlay.classList.add('open');
  });
}

function appModalNotify(message, isError) {
  return new Promise((resolve) => {
    const overlay = document.getElementById('appModalOverlay');
    const box = document.getElementById('appModalBox');
    const actions = document.getElementById('appModalActions');
    box.classList.toggle('is-error', !!isError);
    document.getElementById('appModalMessage').textContent = message;
    actions.innerHTML = '';

    const okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.className = 'modal-btn modal-btn-primary';
    okBtn.textContent = 'OK';
    okBtn.onclick = () => {
      overlay.classList.remove('open');
      resolve();
    };

    actions.appendChild(okBtn);
    overlay.classList.add('open');
  });
}

async function acumaticaOrderShortfall(shipmentId, inventoryId, vendorId, qty, btn) {
  const confirmed = await appModalConfirm(
    'Create a purchase order in Acumatica for ' + qty + ' of ' + inventoryId + ' from vendor ' + vendorId + '?\n\n' +
    'It will be created on Hold -- nothing is sent to the vendor until someone reviews and releases it in Acumatica.'
  );
  if (!confirmed) {
    return;
  }
  btn.disabled = true;
  const original = btn.textContent;
  btn.textContent = 'Creating…';
  try {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('/api/acumatica.php?action=create_po', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: csrf,
        vendor_id: vendorId,
        source_type: 'kit_shipment',
        source_id: shipmentId,
        description: 'Kit shipment shortfall (packing slip)',
        lines: [{ inventory_id: inventoryId, qty: qty }]
      })
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Failed to create purchase order.');
    }
    btn.closest('.item-sub').innerHTML = '✓ PO ' + data.order_nbr + ' created (Hold) &mdash; pending review';
  } catch (e) {
    await appModalNotify(e.message, true);
    btn.disabled = false;
    btn.textContent = original;
  }
}
</script>
</body>
</html>
