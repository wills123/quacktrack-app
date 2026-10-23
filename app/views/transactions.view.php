<?php
$tab = $tab ?? 'store_usage';
$start_date = $start_date ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $end_date ?? date('Y-m-d');
$store_id = $store_id ?? 0;
$scope = $scope ?? 'own';
$limit = $limit ?? 50;
$page = $page ?? 1;
$can_view_reports = $can_view_reports ?? false;
$pagination = $pagination ?? [];
$stores = $stores ?? [];
$title = $page_title ?? 'Usage & Transactions';
require __DIR__ . '/header.php';

// Build filter params for form submissions and exports
$filter_params = [
  'tab' => $tab,
  'start_date' => $start_date,
  'end_date' => $end_date,
  'store_id' => $store_id ?: null,
  'scope' => $scope,
  'limit' => $limit,
];
$filter_params = array_filter($filter_params, fn($v) => $v !== null && $v !== '');

// Export params
$summary_export_params = http_build_query(array_merge($filter_params, ['export' => 'summary']));
$detailed_export_params = http_build_query(array_merge($filter_params, ['export' => 'detailed']));

// Build base URL for tab switches (preserve filters but reset page)
$base_filter_params = array_diff_key($filter_params, ['tab' => true, 'page' => true]);
$store_usage_params = http_build_query(array_merge($base_filter_params, ['tab' => 'store_usage']));
$all_activity_params = http_build_query(array_merge($base_filter_params, ['tab' => 'all']));
?>

<style>
/* ===== PAGE LAYOUT ===== */
.transactions-page {
  max-width: 1400px;
  margin: 0 auto;
  padding: var(--space-lg, 1.5rem);
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-lg, 1.5rem);
  flex-wrap: wrap;
  gap: var(--space-md, 1rem);
}

.page-header h1 {
  margin: 0;
  font-size: var(--font-size-2xl, 1.5rem);
  font-weight: var(--font-weight-bold, 700);
  color: var(--color-gray-900, #111827);
}

.page-header-actions {
  display: flex;
  gap: var(--space-sm, 0.5rem);
}

/* ===== TAB NAVIGATION ===== */
.transactions-tabs {
  display: flex;
  gap: 0;
  background: var(--color-white, #fff);
  border-radius: var(--radius-lg, 0.5rem) var(--radius-lg, 0.5rem) 0 0;
  border: 1px solid var(--color-gray-200, #e5e7eb);
  border-bottom: none;
  overflow: hidden;
}

.transactions-tabs .tab-link {
  flex: 1;
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  text-align: center;
  background: var(--color-gray-50, #f9fafb);
  border: none;
  cursor: pointer;
  font-size: var(--font-size-sm, 0.875rem);
  font-weight: var(--font-weight-medium, 500);
  color: var(--color-gray-600, #4b5563);
  text-decoration: none;
  transition: all var(--transition-base, 0.2s ease-in-out);
  border-right: 1px solid var(--color-gray-200, #e5e7eb);
}

.transactions-tabs .tab-link:last-child {
  border-right: none;
}

.transactions-tabs .tab-link:hover {
  color: var(--color-primary, #006A4E);
  background: var(--color-white, #fff);
}

.transactions-tabs .tab-link.active {
  background: var(--color-white, #fff);
  color: var(--color-primary, #006A4E);
  font-weight: var(--font-weight-semibold, 600);
  box-shadow: inset 0 -3px 0 var(--color-primary, #006A4E);
}

/* ===== FILTER BAR ===== */
.filter-bar {
  display: flex;
  align-items: center;
  gap: var(--space-md, 1rem);
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  background: var(--color-white, #fff);
  border: 1px solid var(--color-gray-200, #e5e7eb);
  border-top: none;
  flex-wrap: wrap;
}

.filter-bar label {
  font-size: var(--font-size-sm, 0.875rem);
  font-weight: var(--font-weight-medium, 500);
  color: var(--color-gray-700, #374151);
  white-space: nowrap;
}

.filter-bar select,
.filter-bar input[type="date"] {
  padding: var(--space-sm, 0.5rem) var(--space-md, 1rem);
  border: 1px solid var(--color-gray-300, #d1d5db);
  border-radius: var(--radius-md, 0.375rem);
  font-size: var(--font-size-sm, 0.875rem);
  background: var(--color-white, #fff);
  color: var(--color-gray-900, #111827);
  min-height: 38px;
}

.filter-bar select:focus,
.filter-bar input[type="date"]:focus {
  outline: none;
  border-color: var(--color-primary, #006A4E);
  box-shadow: 0 0 0 3px rgba(0, 106, 78, 0.1);
}

.filter-group {
  display: flex;
  align-items: center;
  gap: var(--space-xs, 0.25rem);
}

.filter-divider {
  width: 1px;
  height: 24px;
  background: var(--color-gray-200, #e5e7eb);
  margin: 0 var(--space-sm, 0.5rem);
}

.quick-dates {
  display: flex;
  gap: var(--space-xs, 0.25rem);
}

.quick-dates .btn-chip {
  padding: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
  font-size: var(--font-size-xs, 0.75rem);
  border-radius: var(--radius-full, 9999px);
  border: 1px solid var(--color-gray-300, #d1d5db);
  background: var(--color-white, #fff);
  color: var(--color-gray-600, #4b5563);
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease-in-out);
}

.quick-dates .btn-chip:hover {
  border-color: var(--color-primary, #006A4E);
  color: var(--color-primary, #006A4E);
  background: rgba(0, 106, 78, 0.05);
}

.filter-actions {
  margin-left: auto;
  display: flex;
  gap: var(--space-sm, 0.5rem);
}

/* ===== SUMMARY CARD ===== */
.summary-card {
  background: var(--color-white, #fff);
  border: 1px solid var(--color-gray-200, #e5e7eb);
  border-radius: var(--radius-lg, 0.5rem);
  box-shadow: var(--shadow-sm, 0 1px 3px rgba(0, 0, 0, 0.1));
  margin-top: var(--space-lg, 1.5rem);
  overflow: hidden;
}

.summary-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-lg, 1.5rem);
  border-bottom: 1px solid var(--color-gray-100, #f3f4f6);
  background: linear-gradient(to right, var(--color-gray-50, #f9fafb), var(--color-white, #fff));
}

.summary-card-header h2 {
  margin: 0;
  font-size: var(--font-size-lg, 1.125rem);
  font-weight: var(--font-weight-semibold, 600);
  color: var(--color-gray-900, #111827);
}

.summary-card-header p {
  margin: var(--space-xs, 0.25rem) 0 0;
  font-size: var(--font-size-sm, 0.875rem);
  color: var(--color-gray-500, #6b7280);
}

/* ===== RESTOCK TOOLBAR ===== */
.restock-toolbar {
  display: flex;
  align-items: center;
  gap: var(--space-md, 1rem);
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  background: linear-gradient(to right, rgba(0, 106, 78, 0.03), rgba(0, 106, 78, 0.08));
  border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
}

.restock-toolbar .checkbox-wrapper {
  display: flex;
  align-items: center;
  gap: var(--space-sm, 0.5rem);
}

.restock-toolbar .selected-info {
  font-size: var(--font-size-sm, 0.875rem);
  color: var(--color-gray-600, #4b5563);
  padding: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
  background: var(--color-white, #fff);
  border-radius: var(--radius-md, 0.375rem);
  border: 1px solid var(--color-gray-200, #e5e7eb);
}

.restock-toolbar .selected-info strong {
  color: var(--color-primary, #006A4E);
}

/* ===== TABLE STYLING ===== */
.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th {
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  text-align: left;
  font-size: var(--font-size-xs, 0.75rem);
  font-weight: var(--font-weight-semibold, 600);
  color: var(--color-gray-500, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  background: var(--color-gray-50, #f9fafb);
  border-bottom: 1px solid var(--color-gray-200, #e5e7eb);
}

.data-table td {
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  font-size: var(--font-size-sm, 0.875rem);
  color: var(--color-gray-900, #111827);
  border-bottom: 1px solid var(--color-gray-100, #f3f4f6);
  vertical-align: middle;
}

.data-table tbody tr {
  transition: background var(--transition-fast, 0.15s ease-in-out);
}

.data-table tbody tr:hover {
  background: rgba(0, 106, 78, 0.02);
}

.data-table tbody tr:last-child td {
  border-bottom: none;
}

.data-table .checkbox-col {
  width: 48px;
  text-align: center;
  padding-left: var(--space-md, 1rem);
  padding-right: var(--space-sm, 0.5rem);
}

.data-table .numeric {
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: var(--font-weight-medium, 500);
}

.data-table .qty-positive {
  color: var(--color-success, #10b981);
}

.data-table .qty-negative {
  color: var(--color-error, #ef4444);
}

.data-table .muted {
  color: var(--color-gray-400, #9ca3af);
  font-style: italic;
}

/* ===== REASON BADGES ===== */
.reason-badge {
  display: inline-flex;
  align-items: center;
  padding: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
  border-radius: var(--radius-full, 9999px);
  font-size: var(--font-size-xs, 0.75rem);
  font-weight: var(--font-weight-medium, 500);
  text-transform: capitalize;
}

.reason-badge.use { background: #fef2f2; color: #dc2626; }
.reason-badge.add { background: #ecfdf5; color: #059669; }
.reason-badge.transfer { background: #eff6ff; color: #2563eb; }
.reason-badge.adjust { background: #fffbeb; color: #d97706; }
.reason-badge.return { background: #ecfdf5; color: #059669; }
.reason-badge.damage { background: #fef2f2; color: #dc2626; }
.reason-badge.loss { background: #fef2f2; color: #dc2626; }

/* ===== PAGINATION ===== */
.table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  background: var(--color-gray-50, #f9fafb);
  border-top: 1px solid var(--color-gray-200, #e5e7eb);
  flex-wrap: wrap;
  gap: var(--space-sm, 0.5rem);
}

.pagination-info {
  font-size: var(--font-size-sm, 0.875rem);
  color: var(--color-gray-600, #4b5563);
}

.pagination-nav {
  display: flex;
  align-items: center;
  gap: var(--space-xs, 0.25rem);
}

.pagination-nav .page-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 32px;
  height: 32px;
  padding: 0 var(--space-sm, 0.5rem);
  border: 1px solid var(--color-gray-300, #d1d5db);
  border-radius: var(--radius-md, 0.375rem);
  background: var(--color-white, #fff);
  color: var(--color-gray-700, #374151);
  font-size: var(--font-size-sm, 0.875rem);
  text-decoration: none;
  transition: all var(--transition-fast, 0.15s ease-in-out);
}

.pagination-nav .page-btn:hover:not(.disabled) {
  border-color: var(--color-primary, #006A4E);
  color: var(--color-primary, #006A4E);
  background: rgba(0, 106, 78, 0.05);
}

.pagination-nav .page-btn.disabled {
  opacity: 0.4;
  cursor: not-allowed;
  pointer-events: none;
}

.pagination-nav .page-current {
  padding: 0 var(--space-md, 1rem);
  font-size: var(--font-size-sm, 0.875rem);
  color: var(--color-gray-600, #4b5563);
}

/* ===== EMPTY STATE ===== */
.empty-state {
  padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
  text-align: center;
}

.empty-state p {
  margin: 0;
  color: var(--color-gray-500, #6b7280);
  font-size: var(--font-size-sm, 0.875rem);
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
  .transactions-page {
    padding: var(--space-md, 1rem);
  }

  .page-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .filter-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-group {
    flex-wrap: wrap;
  }

  .filter-divider {
    display: none;
  }

  .filter-actions {
    margin-left: 0;
    width: 100%;
    justify-content: flex-end;
  }

  .restock-toolbar {
    flex-wrap: wrap;
  }

  .data-table th,
  .data-table td {
    padding: var(--space-sm, 0.5rem);
  }

  .summary-card {
    overflow-x: auto;
  }

  .data-table {
    min-width: 560px;
  }
}

/* ===== IN-APP DIALOG MODAL ===== */
.app-dialog-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.2s ease, visibility 0.2s ease;
}

.app-dialog-overlay.active {
  opacity: 1;
  visibility: visible;
}

.app-dialog {
  background: var(--color-white, #fff);
  border-radius: var(--radius-lg, 0.5rem);
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  max-width: 400px;
  width: 90%;
  transform: scale(0.9) translateY(-20px);
  transition: transform 0.2s ease;
}

.app-dialog-overlay.active .app-dialog {
  transform: scale(1) translateY(0);
}

.app-dialog-header {
  display: flex;
  align-items: center;
  gap: var(--space-sm, 0.5rem);
  padding: var(--space-lg, 1.5rem);
  border-bottom: 1px solid var(--color-gray-100, #f3f4f6);
}

.app-dialog-icon {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.app-dialog-icon.success {
  background: #ecfdf5;
  color: #059669;
}

.app-dialog-icon.error {
  background: #fef2f2;
  color: #dc2626;
}

.app-dialog-icon.info {
  background: #eff6ff;
  color: #2563eb;
}

.app-dialog-icon svg {
  width: 24px;
  height: 24px;
}

.app-dialog-title {
  margin: 0;
  font-size: var(--font-size-lg, 1.125rem);
  font-weight: var(--font-weight-semibold, 600);
  color: var(--color-gray-900, #111827);
}

.app-dialog-body {
  padding: var(--space-lg, 1.5rem);
}

.app-dialog-message {
  margin: 0;
  font-size: var(--font-size-sm, 0.875rem);
  color: var(--color-gray-600, #4b5563);
  line-height: 1.5;
}

.app-dialog-footer {
  display: flex;
  gap: var(--space-sm, 0.5rem);
  justify-content: flex-end;
  padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
  background: var(--color-gray-50, #f9fafb);
  border-top: 1px solid var(--color-gray-100, #f3f4f6);
  border-radius: 0 0 var(--radius-lg, 0.5rem) var(--radius-lg, 0.5rem);
}

.app-dialog-btn {
  padding: var(--space-sm, 0.5rem) var(--space-lg, 1.5rem);
  border-radius: var(--radius-md, 0.375rem);
  font-size: var(--font-size-sm, 0.875rem);
  font-weight: var(--font-weight-medium, 500);
  cursor: pointer;
  transition: all 0.15s ease;
  border: 1px solid transparent;
}

.app-dialog-btn.secondary {
  background: var(--color-white, #fff);
  border-color: var(--color-gray-300, #d1d5db);
  color: var(--color-gray-700, #374151);
}

.app-dialog-btn.secondary:hover {
  background: var(--color-gray-50, #f9fafb);
  border-color: var(--color-gray-400, #9ca3af);
}

.app-dialog-btn.primary {
  background: var(--color-primary, #006A4E);
  color: white;
}

.app-dialog-btn.primary:hover {
  background: var(--color-primary-dark, #005a42);
}
</style>

<!-- In-App Dialog Modal -->
<div class="app-dialog-overlay" id="app-dialog">
  <div class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
    <div class="app-dialog-header">
      <div class="app-dialog-icon success" id="dialog-icon">
        <svg id="icon-success" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
        <svg id="icon-error" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
        <svg id="icon-info" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <h3 class="app-dialog-title" id="dialog-title">Success</h3>
    </div>
    <div class="app-dialog-body">
      <p class="app-dialog-message" id="dialog-message">Operation completed successfully.</p>
    </div>
    <div class="app-dialog-footer" id="dialog-footer">
      <button type="button" class="app-dialog-btn secondary" id="dialog-cancel">Cancel</button>
      <button type="button" class="app-dialog-btn primary" id="dialog-confirm">OK</button>
    </div>
  </div>
</div>

<div class="transactions-page">

<!-- Page Header -->
<div class="page-header">
  <h1><?= $tab === 'store_usage' ? 'Store Usage' : 'All Activity' ?></h1>
  <?php if ($can_view_reports) : ?>
  <div class="page-header-actions">
    <a class="btn ghost btn-sm" href="?<?= h($summary_export_params) ?>">Export Summary</a>
    <a class="btn ghost btn-sm" href="?<?= h($detailed_export_params) ?>">Export Details</a>
  </div>
  <?php endif; ?>
</div>

<!-- Tab Navigation -->
<nav class="transactions-tabs" role="tablist">
  <a href="?<?= h($store_usage_params) ?>"
     class="tab-link <?= $tab === 'store_usage' ? 'active' : '' ?>"
     role="tab"
     aria-selected="<?= $tab === 'store_usage' ? 'true' : 'false' ?>">
    Store Usage
  </a>
  <?php if ($can_view_reports) : ?>
  <a href="?<?= h($all_activity_params) ?>"
     class="tab-link <?= $tab === 'all' ? 'active' : '' ?>"
     role="tab"
     aria-selected="<?= $tab === 'all' ? 'true' : 'false' ?>">
    All Activity
  </a>
  <?php endif; ?>
</nav>

<!-- Filter Bar (inline like orders page) -->
<form method="get" class="filter-bar" id="transactions-filters">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">

  <div class="filter-group">
    <label for="start_date">From</label>
    <input type="date" id="start_date" name="start_date" value="<?= h($start_date) ?>">
  </div>

  <div class="filter-group">
    <label for="end_date">To</label>
    <input type="date" id="end_date" name="end_date" value="<?= h($end_date) ?>">
  </div>

  <div class="quick-dates">
    <button type="button" class="btn-chip" data-range="7">7d</button>
    <button type="button" class="btn-chip" data-range="30">30d</button>
    <button type="button" class="btn-chip" data-range="90">90d</button>
  </div>

  <span class="filter-divider"></span>

  <div class="filter-group">
    <label for="store_id">Store</label>
    <select id="store_id" name="store_id" onchange="this.form.submit()">
      <option value="0">All stores</option>
      <?php foreach ($stores as $store) : ?>
        <option value="<?= (int)$store['id'] ?>" <?= $store_id === (int)$store['id'] ? 'selected' : '' ?>>
            <?= h($store['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($can_view_reports) : ?>
  <div class="filter-group">
    <label for="scope">View</label>
    <select id="scope" name="scope" onchange="this.form.submit()">
      <option value="own" <?= $scope === 'own' ? 'selected' : '' ?>>My usage</option>
      <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All techs</option>
    </select>
  </div>
  <?php endif; ?>

  <div class="filter-group">
    <label for="limit">Show</label>
    <select id="limit" name="limit" onchange="this.form.submit()">
      <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
      <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
      <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
    </select>
  </div>

  <div class="filter-actions">
    <button type="submit" class="btn primary btn-sm">Apply</button>
    <a href="/transactions?tab=<?= h($tab) ?>" class="btn btn-sm">Clear</a>
  </div>
</form>

<?php if ($tab === 'store_usage') : ?>
<!-- ===== STORE USAGE TAB ===== -->

<!-- Usage Summary with Restock -->
<div class="summary-card">
  <div class="summary-card-header">
    <div>
      <h2>Usage Summary</h2>
      <p>Billable items used at a different store than allocated. Select to restock.</p>
    </div>
  </div>

    <?php if (empty($usage_summary)) : ?>
    <div class="empty-state">
      <p>No billable usage found for this date range.</p>
    </div>
    <?php else : ?>
    <!-- Restock Toolbar -->
    <div class="restock-toolbar">
      <div class="checkbox-wrapper">
        <input type="checkbox" id="select-all-summary">
        <label for="select-all-summary">Select all</label>
      </div>
      <span class="selected-info"><strong id="selected-count">0</strong> items selected</span>
      <button type="button" class="btn primary btn-sm" id="restock-btn" disabled>
        Add to Cart
      </button>
    </div>

    <table class="data-table" id="usage-summary-table">
      <thead>
        <tr>
          <th class="checkbox-col"></th>
          <th>Used At</th>
          <th>From</th>
          <th>Product</th>
          <th>SKU</th>
          <th class="numeric">Qty</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_summary as $row) : ?>
          <tr>
            <td class="checkbox-col">
              <input type="checkbox"
                     class="restock-item"
                     data-product-id="<?= (int)$row['product_db_id'] ?>"
                     data-quantity="<?= (int)$row['total_qty'] ?>"
                     data-store-id="<?= (int)$row['target_store_id'] ?>"
                     data-product-name="<?= h($row['product_name'] ?? '') ?>">
            </td>
            <td><?= h($row['target_store_name'] ?? 'Unknown') ?></td>
            <td class="<?= empty($row['source_store_name']) ? 'muted' : '' ?>">
              <?= h($row['source_store_name'] ?? 'Van (Unallocated)') ?>
            </td>
            <td><?= h($row['product_name'] ?? '') ?></td>
            <td><?= h($row['sku'] ?? '') ?></td>
            <td class="numeric"><?= (int)$row['total_qty'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Detailed Transactions -->
<div class="summary-card">
  <div class="summary-card-header">
    <div>
      <h2>Transaction Details</h2>
      <p>Individual transaction records for the selected date range.</p>
    </div>
  </div>

    <?php if (empty($usage_rows)) : ?>
    <div class="empty-state">
      <p>No transactions found for this date range.</p>
    </div>
    <?php else : ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <?php if ($can_view_reports && $scope === 'all') : ?>
          <th>User</th>
          <?php endif; ?>
          <th>Product</th>
          <th>SKU</th>
          <th class="numeric">Qty</th>
          <th>From</th>
          <th>Used At</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_rows as $row) : ?>
          <tr>
            <td><?= h(date('M j, g:ia', strtotime($row['created_at']))) ?></td>
            <?php if ($can_view_reports && $scope === 'all') : ?>
            <td><?= h($row['user_display'] ?? $row['user_email'] ?? '') ?></td>
            <?php endif; ?>
            <td><?= h($row['product_name'] ?? '') ?></td>
            <td><?= h($row['sku'] ?? '') ?></td>
            <?php $qty = (int)$row['quantity_change']; ?>
            <td class="numeric <?= $qty >= 0 ? 'qty-positive' : 'qty-negative' ?>">
              <?= $qty >= 0 ? '+' . $qty : $qty ?>
            </td>
            <td class="<?= empty($row['source_store_name']) ? 'muted' : '' ?>">
              <?= h($row['source_store_name'] ?? 'Van') ?>
            </td>
            <td><?= h($row['target_store_name'] ?? '') ?></td>
            <td class="muted"><?= h((string)($row['note'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

        <?php if ($pagination['total_pages'] > 1) : ?>
    <div class="table-footer">
      <span class="pagination-info">
        Showing <?= $pagination['start_item'] ?>-<?= $pagination['end_item'] ?> of <?= number_format($pagination['total']) ?>
      </span>
      <div class="pagination-nav">
            <?php $page_params = $filter_params; ?>

            <?php $page_params['page'] = 1; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">First</a>

            <?php $page_params['page'] = $page - 1; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">Prev</a>

        <span class="page-current"><?= $page ?> / <?= $pagination['total_pages'] ?></span>

            <?php $page_params['page'] = $page + 1; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">Next</a>

            <?php $page_params['page'] = $pagination['total_pages']; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">Last</a>
      </div>
    </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php else : ?>
<!-- ===== ALL ACTIVITY TAB ===== -->

<div class="summary-card">
  <div class="summary-card-header">
    <div>
      <h2>Activity Log</h2>
      <p>Complete transaction history for auditing and review.</p>
    </div>
  </div>

    <?php if (empty($usage_rows)) : ?>
    <div class="empty-state">
      <p>No activity found for this date range.</p>
    </div>
    <?php else : ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <?php if ($scope === 'all') : ?>
          <th>User</th>
          <?php endif; ?>
          <th>Product</th>
          <th>SKU</th>
          <th class="numeric">Change</th>
          <th>Reason</th>
          <th>Source</th>
          <th>Target</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_rows as $row) : ?>
          <tr>
            <td><?= h(date('M j, g:ia', strtotime($row['created_at']))) ?></td>
            <?php if ($scope === 'all') : ?>
            <td><?= h($row['user_display'] ?? $row['user_email'] ?? '') ?></td>
            <?php endif; ?>
            <td><?= h($row['product_name'] ?? '') ?></td>
            <td><?= h($row['sku'] ?? '') ?></td>
            <?php $qty = (int)$row['quantity_change']; ?>
            <td class="numeric <?= $qty >= 0 ? 'qty-positive' : 'qty-negative' ?>">
              <?= $qty >= 0 ? '+' . $qty : $qty ?>
            </td>
            <td>
              <span class="reason-badge <?= h($row['reason'] ?? '') ?>"><?= h($row['reason'] ?? '') ?></span>
            </td>
            <td><?= h($row['source_store_name'] ?? '-') ?></td>
            <td><?= h($row['target_store_name'] ?? '-') ?></td>
            <td class="muted"><?= h((string)($row['note'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

        <?php if ($pagination['total_pages'] > 1) : ?>
    <div class="table-footer">
      <span class="pagination-info">
        Showing <?= $pagination['start_item'] ?>-<?= $pagination['end_item'] ?> of <?= number_format($pagination['total']) ?>
      </span>
      <div class="pagination-nav">
            <?php $page_params = $filter_params; ?>

            <?php $page_params['page'] = 1; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">First</a>

            <?php $page_params['page'] = $page - 1; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">Prev</a>

        <span class="page-current"><?= $page ?> / <?= $pagination['total_pages'] ?></span>

            <?php $page_params['page'] = $page + 1; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">Next</a>

            <?php $page_params['page'] = $pagination['total_pages']; ?>
        <a href="?<?= http_build_query($page_params) ?>"
           class="page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">Last</a>
      </div>
    </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

    <?php if (!empty($legacy_transactions)) : ?>
<div class="summary-card">
  <div class="summary-card-header">
    <div>
      <h2>Legacy Transactions</h2>
      <p>Historical data from the previous transaction system.</p>
    </div>
  </div>
  <table class="data-table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Product</th>
        <th>SKU</th>
        <th class="numeric">Change</th>
        <th>Reason</th>
        <th>Store</th>
        <th>Note</th>
      </tr>
    </thead>
    <tbody>
        <?php foreach ($legacy_transactions as $t) : ?>
        <tr>
          <td><?= h(date('M j, g:ia', strtotime($t['created_at']))) ?></td>
          <td><?= h($t['product_name']) ?></td>
          <td><?= h($t['sku']) ?></td>
          <td class="numeric"><?= (int)$t['change_qty'] ?></td>
          <td><span class="reason-badge <?= h($t['reason'] ?? '') ?>"><?= h($t['reason']) ?></span></td>
          <td><?= h((string)($t['store_name'] ?? $t['store'] ?? '')) ?></td>
          <td class="muted"><?= h((string)($t['note'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
  </table>
</div>
    <?php endif; ?>

<?php endif; ?>

</div>

<script>
(function() {
  'use strict';

  // ===== IN-APP DIALOG SYSTEM =====
  const dialogOverlay = document.getElementById('app-dialog');
  const dialogIcon = document.getElementById('dialog-icon');
  const dialogTitle = document.getElementById('dialog-title');
  const dialogMessage = document.getElementById('dialog-message');
  const dialogConfirm = document.getElementById('dialog-confirm');
  const dialogCancel = document.getElementById('dialog-cancel');
  const iconSuccess = document.getElementById('icon-success');
  const iconError = document.getElementById('icon-error');
  const iconInfo = document.getElementById('icon-info');

  let dialogResolve = null;

  function showDialog(options) {
    const {
      type = 'info',
      title = 'Notice',
      message = '',
      confirmText = 'OK',
      cancelText = 'Cancel',
      showCancel = false
    } = options;

    // Set icon and colors
    dialogIcon.className = 'app-dialog-icon ' + type;
    iconSuccess.style.display = type === 'success' ? 'block' : 'none';
    iconError.style.display = type === 'error' ? 'block' : 'none';
    iconInfo.style.display = type === 'info' ? 'block' : 'none';

    // Set content
    dialogTitle.textContent = title;
    dialogMessage.textContent = message;
    dialogConfirm.textContent = confirmText;
    dialogCancel.textContent = cancelText;

    // Show/hide cancel button
    dialogCancel.style.display = showCancel ? 'inline-flex' : 'none';

    // Show dialog
    dialogOverlay.classList.add('active');
    dialogConfirm.focus();

    return new Promise((resolve) => {
      dialogResolve = resolve;
    });
  }

  function hideDialog(result) {
    dialogOverlay.classList.remove('active');
    if (dialogResolve) {
      dialogResolve(result);
      dialogResolve = null;
    }
  }

  // Dialog event listeners
  dialogConfirm.addEventListener('click', () => hideDialog(true));
  dialogCancel.addEventListener('click', () => hideDialog(false));
  dialogOverlay.addEventListener('click', (e) => {
    if (e.target === dialogOverlay) hideDialog(false);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && dialogOverlay.classList.contains('active')) {
      hideDialog(false);
    }
  });

  // Helper functions
  function showAlert(message, type = 'info', title = null) {
    const titles = { success: 'Success', error: 'Error', info: 'Notice' };
    return showDialog({
      type,
      title: title || titles[type] || 'Notice',
      message,
      confirmText: 'OK',
      showCancel: false
    });
  }

  function showConfirm(message, options = {}) {
    return showDialog({
      type: options.type || 'info',
      title: options.title || 'Confirm',
      message,
      confirmText: options.confirmText || 'Yes',
      cancelText: options.cancelText || 'No',
      showCancel: true
    });
  }

  // ===== FILTER FORM =====
  const form = document.getElementById('transactions-filters');
  if (!form) return;

  // Quick date range buttons
  const startInput = form.querySelector('#start_date');
  const endInput = form.querySelector('#end_date');
  const rangeButtons = form.querySelectorAll('.btn-chip[data-range]');

  const toLocalISODate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  rangeButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const days = parseInt(btn.dataset.range, 10);
      if (!Number.isFinite(days) || !startInput || !endInput) return;

      const today = new Date();
      const startDate = new Date();
      startDate.setDate(today.getDate() - (days - 1));

      endInput.value = toLocalISODate(today);
      startInput.value = toLocalISODate(startDate);
      form.submit();
    });
  });

  // ===== RESTOCK FUNCTIONALITY =====
  const selectAllCheckbox = document.getElementById('select-all-summary');
  const restockBtn = document.getElementById('restock-btn');
  const selectedCountEl = document.getElementById('selected-count');
  const itemCheckboxes = document.querySelectorAll('.restock-item');

  if (selectAllCheckbox && restockBtn && itemCheckboxes.length > 0) {
    const updateSelectedCount = () => {
      const checked = document.querySelectorAll('.restock-item:checked');
      const count = checked.length;
      selectedCountEl.textContent = count;
      restockBtn.disabled = count === 0;

      // Update select all state
      selectAllCheckbox.checked = count === itemCheckboxes.length;
      selectAllCheckbox.indeterminate = count > 0 && count < itemCheckboxes.length;
    };

    selectAllCheckbox.addEventListener('change', () => {
      itemCheckboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
      updateSelectedCount();
    });

    itemCheckboxes.forEach(cb => {
      cb.addEventListener('change', updateSelectedCount);
    });

    // Restock button click handler
    restockBtn.addEventListener('click', async () => {
      const checked = document.querySelectorAll('.restock-item:checked');
      if (checked.length === 0) {
        await showAlert('Please select items to restock.', 'info', 'No Items Selected');
        return;
      }

      const items = Array.from(checked).map(cb => ({
        product_id: parseInt(cb.dataset.productId, 10),
        quantity: parseInt(cb.dataset.quantity, 10),
        store_id: parseInt(cb.dataset.storeId, 10)
      }));

      restockBtn.disabled = true;
      restockBtn.textContent = 'Adding...';

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch('/api/transactions.php?action=restock', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            csrf: csrfToken,
            items: items
          })
        });

        const data = await response.json();

        if (data.success) {
          const message = data.message || `Added ${data.added} item(s) to cart.`;

          itemCheckboxes.forEach(cb => cb.checked = false);
          selectAllCheckbox.checked = false;
          updateSelectedCount();

          const goToCart = await showConfirm(message + ' Would you like to go to your cart now?', {
            type: 'success',
            title: 'Items Added to Cart',
            confirmText: 'Go to Cart',
            cancelText: 'Stay Here'
          });

          if (goToCart) {
            window.location.href = '/cart';
          }
        } else {
          await showAlert(data.error || 'Failed to add items to cart.', 'error', 'Error');
        }
      } catch (err) {
        console.error('Restock error:', err);
        await showAlert('An error occurred. Please try again.', 'error', 'Error');
      } finally {
        restockBtn.disabled = false;
        restockBtn.textContent = 'Add to Cart';
        updateSelectedCount();
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
