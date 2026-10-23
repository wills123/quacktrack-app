<?php
/** @var array<int, array<string, mixed>> $orders */
/** @var bool $canManageAssets */
require __DIR__ . '/header.php';

$awaitingCount = 0;
$partialCount = 0;
$incomingUnits = 0;
foreach ($orders as $o) {
    if ($o['status'] === 'partially_received') {
        $partialCount++;
    } else {
        $awaitingCount++;
    }
    $incomingUnits += (int)$o['total_quantity'];
}
?>
<div class="stock-page receiving-page" data-can-manage-assets="<?= !empty($canManageAssets) ? '1' : '0' ?>">

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Receiving</h1>
        <p class="stock-masthead-sub">Orders waiting to be received</p>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <a href="/orders" class="masthead-tab">Order History</a>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="page-stat-rail stock-stats receiving-stats">
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $awaitingCount ?></span>
      <span class="page-stat-label stock-stat-label">Awaiting receipt</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $partialCount ?></span>
      <span class="page-stat-label stock-stat-label">Partially received</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= number_format($incomingUnits) ?></span>
      <span class="page-stat-label stock-stat-label">Units incoming</span>
    </div>
  </div>

  <div class="receiving-body">
    <?php if (empty($orders)) : ?>
      <div class="orders-empty">
        <svg class="orders-empty-icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
          <rect x="8" y="10" width="32" height="34" rx="3" stroke="currentColor" stroke-width="2.5" fill="none"/>
          <path d="M16 10V8a8 8 0 0 1 16 0v2" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
          <path d="M16 28l6 6 10-12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <h2 class="orders-empty-title">Nothing waiting to be received</h2>
        <p class="orders-empty-text">New orders will appear here once they are sent.</p>
      </div>
    <?php else : ?>
      <div class="orders-list">
        <?php foreach ($orders as $order) : ?>
            <?php $isPartial = $order['status'] === 'partially_received'; ?>
          <div class="order-card receiving-card"
               data-order-id="<?= (int)$order['id'] ?>"
               data-order-number="<?= h($order['order_number']) ?>"
               data-technician="<?= h($order['technician']) ?>"
               data-store="<?= h($order['store_name'] ?? 'N/A') ?>"
               data-placed="<?= h(date('M j, Y', strtotime($order['created_at']))) ?>"
               data-destination="<?= h($order['destination']) ?>"
               data-can-match="<?= $order['can_match_tracked'] ? '1' : '0' ?>"
               data-can-receive="<?= $order['can_receive_supplies'] ? '1' : '0' ?>"
               data-van="<?= $order['post_van'] ? '1' : '0' ?>"
               data-region="<?= $order['post_region'] ? '1' : '0' ?>"
               data-supplies-pending="<?= (int)$order['supplies_pending'] ?>"
               data-can-cancel="<?= $order['can_cancel'] ? '1' : '0' ?>">

            <div class="order-card-header">
              <div class="order-card-identity">
                <span class="order-number"><?= h($order['order_number']) ?></span>
                <span class="order-date">Placed <?= h(date('M j, Y', strtotime($order['created_at']))) ?></span>
              </div>
              <span class="qt-status-badge qt-status-badge-<?= $isPartial ? 'partial' : 'sent' ?>"><?= h(str_replace('_', ' ', $order['status'])) ?></span>
            </div>

            <div class="order-card-meta">
              <div class="order-meta-row">
                <span class="order-meta-label">Technician</span>
                <span class="order-meta-value"><?= h($order['technician']) ?></span>
              </div>
              <div class="order-meta-row">
                <span class="order-meta-label">Store</span>
                <span class="order-meta-value"><?= h($order['store_name'] ?? 'N/A') ?></span>
              </div>
              <div class="order-meta-row">
                <span class="order-meta-label">Items</span>
                <span class="order-meta-value"><?= (int)$order['total_quantity'] ?> across <?= (int)$order['line_count'] ?> line<?= (int)$order['line_count'] !== 1 ? 's' : '' ?></span>
              </div>
              <div class="order-meta-row">
                <span class="order-meta-label">Receives into</span>
                <span class="order-meta-value"><?= h($order['destination']) ?></span>
              </div>
              <?php if ((int)$order['tracked_lines'] > 0) : ?>
                <div class="order-meta-row">
                  <span class="order-meta-label">Tracked assets</span>
                  <span class="order-meta-value" data-tracked-meta><?= (int)$order['tracked_matched'] ?> of <?= (int)$order['tracked_qty'] ?> matched</span>
                </div>
              <?php endif; ?>
            </div>

            <div class="order-card-footer receiving-card-footer">
              <?php if ($order['can_receive_supplies'] || $order['can_match_tracked']) : ?>
                <button class="orders-btn-primary receiving-open-btn" type="button">Receive</button>
              <?php else : ?>
                <button class="orders-btn-secondary receiving-open-btn" type="button">View order</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Order pop-out: items, receive supplies, tracked-asset matching / add to Snipe-IT -->
<div id="receivingModal" class="orders-modal receiving-modal" hidden role="dialog" aria-modal="true" aria-labelledby="receivingModalTitle">
  <div class="orders-modal-backdrop"></div>
  <div class="orders-modal-box">
    <div class="orders-modal-header">
      <div class="receiving-modal-heading">
        <h2 class="orders-modal-title" id="receivingModalTitle">Order</h2>
        <span class="qt-status-badge" id="receivingModalBadge"></span>
      </div>
      <button type="button" class="orders-modal-close" id="receivingModalClose" aria-label="Close">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="18" height="18">
          <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
        </svg>
      </button>
    </div>
    <div class="receiving-modal-message" id="receivingModalMessage" role="alert" hidden></div>
    <div class="orders-modal-body" id="receivingModalBody"></div>
    <div class="receiving-modal-footer">
      <button type="button" class="orders-btn-secondary" id="receivingModalCancelOrder" hidden>Cancel order</button>
      <button type="button" class="orders-btn-primary" id="receivingModalDone">Done</button>
    </div>
  </div>
</div>

<!-- Cancel confirmation -->
<dialog class="receiving-dialog" id="receivingCancelDialog" aria-labelledby="receivingCancelTitle">
  <form method="dialog" class="receiving-dialog-body">
    <h2 id="receivingCancelTitle">Cancel this order?</h2>
    <p>Order <strong id="receivingCancelOrder"></strong> will be marked cancelled and removed from the inbox. This can’t be undone from here.</p>
    <div class="receiving-dialog-actions">
      <button class="orders-btn-secondary" value="cancel" type="submit">Keep order</button>
      <button class="orders-btn-primary" value="confirm" type="submit">Cancel order</button>
    </div>
  </form>
</dialog>

<?php
$modal_id = 'trackedAssetScannerModal';
$video_id = 'trackedAssetScannerVideo';
$result_container_id = 'trackedAssetScannerResult';
$close_btn_id = 'trackedAssetScannerClose';
$close_footer_btn_id = 'trackedAssetScannerCloseFooter';
$stop_btn_id = 'trackedAssetScannerStop';
$modal_title = 'Scan Tracked Asset';
require __DIR__ . '/components/modals/scanner-modal.php';
?>
<?php require __DIR__ . '/footer.php'; ?>
