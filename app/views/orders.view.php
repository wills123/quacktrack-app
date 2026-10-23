<?php
$ordersPagination = $ordersPagination ?? [];
$orders = $orders ?? [];
$sentCount = $sentCount ?? 0;
$receivedCount = $receivedCount ?? 0;
$order_user_options = $order_user_options ?? [];
$can_manage_orders = $can_manage_orders ?? false;
$can_view_all    = $ordersPagination['can_view_all']    ?? false;
$can_view_region = $ordersPagination['can_view_region'] ?? false;
$currentScope    = $ordersPagination['scope']           ?? 'own';
$selected_user_id = $ordersPagination['selected_user_id'] ?? ($_SESSION['uid'] ?? 0);
$orderScopeOptions = $orderScopeOptions ?? ['own' => 'My Orders'];
require __DIR__ . '/header.php';
?>

<div class="stock-page orders-page">

  <!-- =====================================================================
       MASTHEAD
  ====================================================================== -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Orders</h1>
        <p class="stock-masthead-sub">
          <?php if ($ordersPagination['total'] === 0) : ?>
            No orders found
          <?php else : ?>
              <?= number_format($ordersPagination['total']) ?> order<?= $ordersPagination['total'] !== 1 ? 's' : '' ?>
              <?php if ($currentScope === 'all') : ?>
              across all technicians
              <?php elseif ($currentScope === 'region') : ?>
              in your region
              <?php else : ?>
              placed by you
              <?php endif; ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <a href="/cart" class="masthead-tab">View Cart</a>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       STAT STRIP
  ====================================================================== -->
  <div class="page-stat-rail stock-stats">
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= number_format($ordersPagination['total']) ?></span>
      <span class="page-stat-label stock-stat-label">Total orders</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= count($orders) ?></span>
      <span class="page-stat-label stock-stat-label">Showing</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $sentCount ?></span>
      <span class="page-stat-label stock-stat-label">In transit</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $receivedCount ?></span>
      <span class="page-stat-label stock-stat-label">Received</span>
    </div>
  </div>

  <!-- =====================================================================
       BODY — sidebar + main
  ====================================================================== -->
  <div class="stock-layout">

    <!-- Sidebar: filters -->
    <aside class="filters-sidebar">
      <form method="get" id="ordersFilterForm">
        <input type="hidden" name="page" value="1">

        <div class="orders-filter-section">
          <h3 class="orders-filter-heading">Filters</h3>

          <div class="orders-filter-field">
            <label class="orders-filter-label" for="ordersLimit">Items per page</label>
            <select id="ordersLimit" name="limit" class="orders-filter-select" onchange="this.form.submit()">
              <?php foreach ([25, 50, 100] as $opt) : ?>
                <option value="<?= $opt ?>" <?= $ordersPagination['limit'] == $opt ? 'selected' : '' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if (!empty($orderScopeOptions) && count($orderScopeOptions) > 1) : ?>
            <div class="orders-filter-field">
              <label class="orders-filter-label" for="ordersScope">View</label>
              <select id="ordersScope" name="scope" class="orders-filter-select" onchange="this.form.submit()">
                <?php foreach ($orderScopeOptions as $scopeValue => $label) : ?>
                  <option value="<?= h($scopeValue) ?>" <?= $currentScope === $scopeValue ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <?php if ($can_view_all && $currentScope === 'all') : ?>
            <div class="orders-filter-field">
              <label class="orders-filter-label" for="ordersUser">Technician</label>
              <select id="ordersUser" name="user_id" class="orders-filter-select" onchange="this.form.submit()">
                <option value="0" <?= $selected_user_id === 0 ? 'selected' : '' ?>>All technicians</option>
                <?php foreach ($order_user_options as $userOption) : ?>
                    <?php $uid = (int)$userOption['id']; ?>
                  <option value="<?= $uid ?>" <?= $selected_user_id === $uid ? 'selected' : '' ?>>
                    <?= h($userOption['display_name'] ?? $userOption['email']) ?><?= $userOption['role'] !== 'user' ? ' (' . h(ucfirst($userOption['role'])) . ')' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

        </div>

        <!-- Status summary in sidebar -->
        <?php if ($ordersPagination['total'] > 0) : ?>
          <div class="orders-status-summary">
            <h3 class="orders-filter-heading">Status Breakdown</h3>
            <?php
            $statusInfo = [
            'draft'     => ['label' => 'Draft',     'cls' => 'qt-status-badge-draft'],
            'sent'      => ['label' => 'Sent',       'cls' => 'qt-status-badge-sent'],
            'partially_received' => ['label' => 'Partially received', 'cls' => 'qt-status-badge-partial'],
            'received'  => ['label' => 'Received',   'cls' => 'qt-status-badge-received'],
              'cancelled' => ['label' => 'Cancelled',  'cls' => 'qt-status-badge-cancelled'],
            ];
            foreach ($statusInfo as $sKey => $sData) :
                $count = $statusCounts[$sKey] ?? 0;
                if ($count === 0) {
                    continue;
                }
                ?>
              <div class="orders-status-row">
                <span class="qt-status-badge <?= $sData['cls'] ?>"><?= $sData['label'] ?></span>
                <span class="orders-status-count"><?= $count ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </form>
    </aside>

    <!-- Main: orders list -->
    <main class="stock-main">

      <?php foreach (flashes() as [$t, $m]) : ?>
        <div class="flash <?= h($t) ?>"><?= h($m) ?></div>
      <?php endforeach; ?>

      <?php if (empty($orders)) : ?>
        <!-- Empty state -->
        <div class="orders-empty">
          <svg class="orders-empty-icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <rect x="8" y="10" width="32" height="34" rx="3" stroke="currentColor" stroke-width="2.5" fill="none"/>
            <path d="M16 10V8a8 8 0 0 1 16 0v2" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
            <path d="M16 22h16M16 30h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <h2 class="orders-empty-title">No orders found</h2>
            <?php if ($can_view_all && $currentScope === 'all' && $selected_user_id !== 0 && $selected_user_id !== ($current_user_id ?? 0)) : ?>
            <p class="orders-empty-text">No orders found for the selected technician.</p>
            <?php else : ?>
            <p class="orders-empty-text">Start shopping to create your first order.</p>
            <a href="/products" class="orders-empty-btn">Browse Products</a>
            <?php endif; ?>
        </div>

      <?php else : ?>
        <div class="orders-list">
          <?php foreach ($orders as $order) : ?>
            <div class="order-card" data-order-id="<?= (int)$order['id'] ?>">

              <div class="order-card-header">
                <div class="order-card-identity">
                  <span class="order-number"><?= h($order['order_number']) ?></span>
                  <span class="order-date"><?= date('M j, Y \a\t g:i A', strtotime($order['created_at'])) ?></span>
                </div>
                <span class="qt-status-badge qt-status-badge-<?= h($order['status']) ?>"><?= ucfirst(h($order['status'])) ?></span>
              </div>

              <div class="order-card-meta">
                <?php if ($can_view_all || $can_view_region) : ?>
                  <div class="order-meta-row">
                    <span class="order-meta-label">Technician</span>
                    <span class="order-meta-value"><?= h($order['user_display'] ?? $order['user_email'] ?? 'User #' . $order['user_id']) ?></span>
                  </div>
                <?php endif; ?>
                <div class="order-meta-row">
                  <span class="order-meta-label">Store</span>
                  <span class="order-meta-value"><?= h($order['store_name'] ?? 'N/A') ?></span>
                </div>
                <div class="order-meta-row">
                  <span class="order-meta-label">Items</span>
                  <span class="order-meta-value"><?= (int)$order['item_count'] ?> item<?= $order['item_count'] != 1 ? 's' : '' ?></span>
                </div>
                <?php if ($order['notes']) : ?>
                  <div class="order-meta-row">
                    <span class="order-meta-label">Notes</span>
                    <span class="order-meta-value order-meta-notes"><?= h($order['notes']) ?></span>
                  </div>
                <?php endif; ?>
                <?php if ($order['sent_at']) : ?>
                  <div class="order-meta-row">
                    <span class="order-meta-label">Sent</span>
                    <span class="order-meta-value"><?= date('M j, Y \a\t g:i A', strtotime($order['sent_at'])) ?></span>
                  </div>
                <?php endif; ?>
              </div>

              <div class="order-card-footer">
                <?php if (in_array($order['status'], ['sent', 'partially_received'], true)) : ?>
                  <a class="orders-btn-secondary" href="/receiving">Open in Receiving</a>
                <?php endif; ?>
                <button class="orders-btn-secondary view-details" data-order-id="<?= (int)$order['id'] ?>">View Details</button>
                <?php if (($order['user_id'] ?? null) === ($current_user_id ?? null)) : ?>
                  <form method="post" style="display:contents">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                    <button type="submit" class="orders-btn-primary">Reorder</button>
                  </form>
                <?php endif; ?>
              </div>

            </div>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
          <?php if ($ordersPagination['total_pages'] > 1) : ?>
          <div class="orders-pagination">
                <?php if ($ordersPagination['has_prev']) : ?>
                    <?php $prevQuery = http_build_query(array_merge($ordersPagination['query_params'], ['page' => $ordersPagination['page'] - 1])); ?>
              <a class="orders-btn-secondary" href="?<?= $prevQuery ?>">Previous</a>
                <?php else : ?>
              <span class="orders-pagination-placeholder"></span>
                <?php endif; ?>

            <span class="orders-pagination-info">
              Page <?= $ordersPagination['page'] ?> of <?= $ordersPagination['total_pages'] ?>
              <span class="orders-pagination-total">(<?= number_format($ordersPagination['total']) ?> orders)</span>
            </span>

                <?php if ($ordersPagination['has_next']) : ?>
                    <?php $nextQuery = http_build_query(array_merge($ordersPagination['query_params'], ['page' => $ordersPagination['page'] + 1])); ?>
              <a class="orders-btn-secondary" href="?<?= $nextQuery ?>">Next</a>
                <?php else : ?>
              <span class="orders-pagination-placeholder"></span>
                <?php endif; ?>
          </div>
          <?php endif; ?>

      <?php endif; ?>

    </main>
  </div>
</div>

<!-- =====================================================================
     ORDER DETAILS MODAL
====================================================================== -->
<div id="orderDetailsModal" class="orders-modal" hidden aria-modal="true" role="dialog" aria-labelledby="orderModalTitle">
  <div class="orders-modal-backdrop"></div>
  <div class="orders-modal-box">
    <div class="orders-modal-header">
      <h2 class="orders-modal-title" id="orderModalTitle">Order Details</h2>
      <button type="button" class="orders-modal-close" aria-label="Close">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="18" height="18">
          <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
        </svg>
      </button>
    </div>
    <div id="orderDetailsContent" class="orders-modal-body">
      <!-- Content loaded via AJAX -->
    </div>
  </div>
</div>

<script>
const isAdminOrdersView = <?= $can_view_all ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', function () {
  const modal   = document.getElementById('orderDetailsModal');
  const backdrop = modal.querySelector('.orders-modal-backdrop');
  const closeBtn = modal.querySelector('.orders-modal-close');

  function openModal() {
    modal.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    closeBtn.focus();
  }

  function closeModal() {
    modal.setAttribute('hidden', '');
    document.body.style.overflow = '';
  }

  // View-details buttons
  document.querySelectorAll('.view-details').forEach(btn => {
    btn.addEventListener('click', function () {
      document.getElementById('orderDetailsContent').innerHTML =
        '<div class="orders-modal-loading">Loading order details\u2026</div>';
      openModal();
      loadOrderDetails(this.dataset.orderId);
    });
  });

  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal();
  });
});

async function loadOrderDetails(orderId) {
  try {
    const data = await makeAPICall(`/api/order_details.php?order_id=${orderId}`, {}, {
      timeout: 10000,
      retries: 1,
      context: 'Loading order details',
      showErrors: true
    });

    const order = data.order;
    const items = data.items;

    const technicianLabel = order.user_display || order.user_email || `User #${order.user_id}`;
    const technicianHtml  = isAdminOrdersView
      ? `<div class="orders-detail-row"><span class="orders-detail-label">Technician</span><span class="orders-detail-value">${technicianLabel}</span></div>`
      : '';

    const statusBadgeClass = `qt-status-badge qt-status-badge-${order.status}`;
    const statusLabel = order.status.charAt(0).toUpperCase() + order.status.slice(1);

    let html = `
      <div class="orders-modal-summary">
        <div class="orders-modal-order-number">${order.order_number}</div>
        <div class="orders-detail-grid">
          <div class="orders-detail-row">
            <span class="orders-detail-label">Store</span>
            <span class="orders-detail-value">${order.store_name || 'N/A'}</span>
          </div>
          ${technicianHtml}
          <div class="orders-detail-row">
            <span class="orders-detail-label">Date</span>
            <span class="orders-detail-value">${new Date(order.created_at).toLocaleString()}</span>
          </div>
          <div class="orders-detail-row">
            <span class="orders-detail-label">Status</span>
            <span class="orders-detail-value">
              <span id="orderStatusBadge" class="${statusBadgeClass}">${statusLabel}</span>
            </span>
          </div>
          ${order.notes ? `<div class="orders-detail-row orders-detail-row--full"><span class="orders-detail-label">Notes</span><span class="orders-detail-value">${order.notes}</span></div>` : ''}
        </div>
      </div>

      <div class="orders-modal-items">
        <h4 class="orders-section-title">Items (${items.length})</h4>
        <div class="orders-items-list">
    `;

    items.forEach(item => {
      const isTracked = Number(item.is_tracked_asset) === 1;
      const trackedReceipts = Array.isArray(item.tracked_receipts) ? item.tracked_receipts : [];
      const trackedReceiptHtml = isTracked ? `
        <div class="tracked-receive-box">
          <div class="tracked-receive-summary">
            <strong>Tracked assets</strong>
            <span>${trackedReceipts.length} of ${Number(item.quantity)} matched</span>
          </div>
          ${trackedReceipts.length ? `<ul class="tracked-receipt-list">${trackedReceipts.map(receipt => {
            const label = escapeOrdersHtml(receipt.serial || receipt.asset_tag || `Asset #${receipt.snipe_asset_id}`);
            return `<li><div class="tracked-receipt-asset">${label}</div><span class="tracked-receipt-disposition">${escapeOrdersHtml(String(receipt.disposition || 'active'))}</span></li>`;
          }).join('')}</ul>` : ''}
        </div>
      ` : '';
      html += `
        <div class="orders-item">
          ${item.image_path
            ? `<img class="orders-item-img" src="${imageUrl(item.image_path)}" alt="${item.name}">`
            : '<div class="orders-item-img orders-item-no-img">No Image</div>'
          }
          <div class="orders-item-info">
            <p class="orders-item-name">${escapeOrdersHtml(item.name)}</p>
            <p class="orders-item-sku">SKU: ${escapeOrdersHtml(item.sku)}</p>
            <p class="orders-item-qty">Qty: ${item.quantity}</p>
            <p class="orders-item-qty">Charge to: ${escapeOrdersHtml(item.bill_to_store_name || order.store_name || 'Unassigned')}</p>
            ${item.notes ? `<p class="orders-item-notes">${escapeOrdersHtml(item.notes)}</p>` : ''}
            ${trackedReceiptHtml}
          </div>
        </div>
      `;
    });

    html += `
        </div>
      </div>
    `;

    document.getElementById('orderDetailsContent').innerHTML = html;

  } catch (error) {
    const errorMsg = error.message || 'Unknown error occurred';
    document.getElementById('orderDetailsContent').innerHTML =
      `<p class="orders-modal-error">Failed to load order details: ${errorMsg}</p>`;
  }
}

function escapeOrdersHtml(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
