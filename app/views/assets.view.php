<?php
$title      = 'IT Assets';
$main_class = 'main-content';
$canCheckout = $canCheckout ?? false;
$canCheckin = $canCheckin ?? false;
$canManage = $canManage ?? false;
$canLicenses = $canLicenses ?? false;
$canMaintenance = $canMaintenance ?? false;
$canCategories = $canCategories ?? false;
$canReports = $canReports ?? false;
$canAdmin = $canAdmin ?? false;
$snipeBaseUrl = $snipeBaseUrl ?? '';
require __DIR__ . '/header.php';
?>
<script>
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
}());
</script>

<div class="stock-page assets-page"
     data-can-checkout="<?= $canCheckout ? '1' : '0' ?>"
     data-can-checkin="<?= $canCheckin ? '1' : '0' ?>"
     data-can-manage="<?= $canManage ? '1' : '0' ?>"
     data-can-licenses="<?= $canLicenses ? '1' : '0' ?>"
     data-can-maintenance="<?= $canMaintenance ? '1' : '0' ?>"
     data-can-categories="<?= $canCategories ? '1' : '0' ?>"
     data-can-reports="<?= $canReports ? '1' : '0' ?>"
     data-can-admin="<?= $canAdmin ? '1' : '0' ?>">

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">IT Assets</h1>
        <div class="stock-masthead-sub" id="assets-masthead-sub">Loading assets…</div>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <?php if ($canManage && $snipeBaseUrl !== '') : ?>
          <a href="<?= h($snipeBaseUrl) ?>" target="_blank" rel="noopener" class="masthead-tab">
            Open Snipe-IT ↗
          </a>
        <?php endif; ?>
        <form method="post" action="/api/snipe-disconnect" class="masthead-inline-form">
          <?= csrf_field() ?>
          <button type="submit" class="masthead-tab" title="Stop attributing your actions to your Snipe-IT account">
            Disconnect Snipe-IT
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="stock-stats">
    <div class="stock-stat">
      <div class="stock-stat-value" id="stat-total">—</div>
      <div class="stock-stat-label">Total Assets</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value" id="stat-deployed">—</div>
      <div class="stock-stat-label">Deployed</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value warn" id="stat-available">—</div>
      <div class="stock-stat-label">Available</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value" id="stat-showing">—</div>
      <div class="stock-stat-label">Showing</div>
    </div>
  </div>

  <!-- Two-panel body -->
  <div class="stock-layout" id="assetsLayout" data-filters-open="true">

    <!-- Filters sidebar -->
    <aside class="filters-sidebar" id="filtersSidebar">
      <div class="filters-header">
        <h2>Filters</h2>
        <button class="btn-link" type="button" id="clearFiltersBtn">Clear</button>
      </div>

      <div class="filters-group">
        <label for="search-input">Search</label>
        <input type="text" id="search-input" placeholder="Asset name, tag…">
      </div>

      <div class="filters-group">
        <label for="status-filter">Status</label>
        <select id="status-filter">
          <option value="">All Statuses</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="category-filter">Category</label>
        <select id="category-filter">
          <option value="">All Categories</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="model-filter">Model</label>
        <select id="model-filter">
          <option value="">All Models</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="location-filter">Location</label>
        <select id="location-filter">
          <option value="">All Locations</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="manufacturer-filter">Manufacturer</label>
        <select id="manufacturer-filter">
          <option value="">All Manufacturers</option>
        </select>
      </div>
    </aside>

    <div class="filters-backdrop" id="filtersBackdrop"></div>

    <!-- Main content -->
    <section class="stock-main">
      <div class="stock-toolbar">
        <button type="button" class="od-btn od-btn--secondary" id="filtersToggle">Filters</button>
        <div class="stock-search">
          <label for="assets-search-toolbar" class="sr-only">Search assets</label>
          <input type="search" id="assets-search-toolbar" placeholder="Search by name, tag, serial…">
        </div>
        <div class="stock-sort">
          <label for="per-page-select" class="sr-only">Assets per page</label>
          <select id="per-page-select" class="od-btn od-btn--secondary assets-per-page" title="Assets per page">
            <option value="25">25 / page</option>
            <option value="50" selected>50 / page</option>
            <option value="100">100 / page</option>
          </select>
        </div>
      </div>

      <!-- Asset cards grid -->
      <div id="assets-container" class="assets-grid">
        <div class="stock-empty">
          <div class="stock-empty-icon">💻</div>
          <div class="stock-empty-title">Loading assets…</div>
          <div class="stock-empty-body">Fetching from Snipe-IT</div>
        </div>
      </div>

      <!-- Pagination -->
      <div class="assets-pagination" id="assets-pagination">
        <button id="prev-page" class="od-btn od-btn--secondary" disabled>← Prev</button>
        <span class="assets-pagination-info">
          Page <span id="current-page">1</span> of <span id="total-pages">1</span>
          &nbsp;·&nbsp; <span id="total-assets">0</span> assets
        </span>
        <button id="next-page" class="od-btn od-btn--secondary" disabled>Next →</button>
      </div>
    </section>

  </div><!-- /stock-layout -->

</div><!-- /stock-page.assets-page -->

<!-- Asset Detail Panel — slides in from the right -->
<div id="asset-detail-overlay" class="asset-detail-overlay" hidden onclick="assetsModule.closeDetail()"></div>

<div id="asset-detail-panel" class="asset-detail-panel" hidden role="dialog" aria-labelledby="detail-asset-name">

  <!-- HEADER -->
  <div class="adp-header">
    <div class="adp-title-group">
      <h2 id="detail-asset-name" class="adp-name">Loading…</h2>
      <span id="detail-asset-tag" class="adp-tag"></span>
    </div>
    <div class="adp-header-right">
      <span id="detail-status-badge" class="asset-status"></span>
      <button class="adp-close" onclick="assetsModule.closeDetail()" aria-label="Close panel">×</button>
    </div>
  </div>

  <!-- BODY -->
  <div class="adp-body">

    <!-- Loading skeleton -->
    <div id="detail-loading" class="detail-loading-state">
      <div class="detail-skeleton"></div>
      <div class="detail-skeleton detail-skeleton--short"></div>
      <div class="detail-skeleton"></div>
      <div class="detail-skeleton detail-skeleton--short"></div>
    </div>

    <!-- Content -->
    <div id="adp-content" hidden>

      <!-- Action buttons row -->
      <div id="detail-actions" class="adp-action-row"></div>

      <!-- Checkout / Checkin drawer -->
      <div id="adp-drawer" class="adp-drawer" hidden>
        <div class="adp-drawer-header">
          <h3 id="adp-drawer-title" class="adp-drawer-title"></h3>
          <button class="adp-drawer-cancel" type="button"
                  onclick="assetsModule.closeDrawer()" aria-label="Cancel">Cancel</button>
        </div>

        <!-- Checkout -->
        <form id="adp-checkout-form" class="adp-compact-form adp-drawer-form" hidden>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="checkout">
          <input type="hidden" name="asset_id" id="adp-checkout-asset-id">
          <div class="adp-compact-row">
            <label for="adp-checkout-user">Assign to</label>
            <select id="adp-checkout-user" name="assigned_to" required>
              <option value="">Select a user…</option>
            </select>
          </div>
          <div class="adp-compact-row">
            <label for="adp-checkout-note">Note</label>
            <input type="text" id="adp-checkout-note" name="note" placeholder="Optional note…">
          </div>
          <div class="adp-compact-actions">
            <button type="submit" class="btn-primary btn-sm">Confirm Check Out</button>
          </div>
        </form>

        <!-- Checkin -->
        <form id="adp-checkin-form" class="adp-compact-form adp-drawer-form" hidden>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="checkin">
          <input type="hidden" name="asset_id" id="adp-checkin-asset-id">
          <div class="adp-compact-row">
            <label for="adp-checkin-note">Condition note</label>
            <input type="text" id="adp-checkin-note" name="note" placeholder="Optional note…">
          </div>
          <div class="adp-compact-actions">
            <button type="submit" class="btn-primary btn-sm">Confirm Return</button>
          </div>
        </form>
      </div><!-- /#adp-drawer -->

      <!-- Manage save bar -->
      <div id="adp-manage-bar" class="adp-manage-bar" hidden>
        <span class="adp-manage-bar-label">Editing asset — unsaved changes</span>
        <div class="adp-manage-bar-actions">
          <button type="button" class="btn-cancel btn-sm" onclick="assetsModule.cancelManage()">Discard</button>
          <button type="button" class="btn-primary btn-sm" id="adp-save-btn" onclick="assetsModule.saveManage()">Save Changes</button>
        </div>
      </div>

      <!-- Info sections -->
      <div class="adp-section">
        <h4 class="adp-section-title">Identity</h4>
        <div class="adp-grid" id="detail-grid-identity"></div>
      </div>

      <div class="adp-section">
        <h4 class="adp-section-title">Assignment</h4>
        <div class="adp-grid" id="detail-grid-assignment"></div>
      </div>

      <div class="adp-section">
        <h4 class="adp-section-title">Purchase &amp; Warranty</h4>
        <div class="adp-grid" id="detail-grid-purchase"></div>
      </div>

      <div class="adp-section" id="detail-section-custom" hidden>
        <h4 class="adp-section-title">Custom Fields</h4>
        <div class="adp-grid" id="detail-grid-custom"></div>
      </div>

      <div class="adp-section">
        <h4 class="adp-section-title">Activity History</h4>
        <div id="detail-activity" class="asset-detail-activity">
          <p class="asset-detail-activity-empty">Loading history…</p>
        </div>
      </div>

    </div><!-- /#adp-content -->

  </div><!-- /.adp-body -->
</div><!-- /#asset-detail-panel -->

<script>
// Sync toolbar search with sidebar search
document.addEventListener('DOMContentLoaded', function() {
  const sidebarSearch = document.getElementById('search-input');
  const toolbarSearch = document.getElementById('assets-search-toolbar');
  if (sidebarSearch && toolbarSearch) {
    toolbarSearch.addEventListener('input', function() {
      sidebarSearch.value = this.value;
      sidebarSearch.dispatchEvent(new Event('input', { bubbles: true }));
    });
    sidebarSearch.addEventListener('input', function() {
      if (toolbarSearch.value !== this.value) toolbarSearch.value = this.value;
    });
  }

  // Filters toggle (reuses stock-layout data attr pattern)
  const filtersToggle = document.getElementById('filtersToggle');
  const filtersBackdrop = document.getElementById('filtersBackdrop');
  const assetsLayout = document.getElementById('assetsLayout');
  const filtersKey = 'assets_filters_open';

  function setFiltersOpen(open) {
    if (!assetsLayout) return;
    assetsLayout.dataset.filtersOpen = open ? 'true' : 'false';
    if (filtersToggle) filtersToggle.textContent = open ? 'Hide filters' : 'Filters';
    try { localStorage.setItem(filtersKey, open ? '1' : '0'); } catch(e) {}
  }

  if (filtersToggle && assetsLayout) {
    let init = true;
    try { if (localStorage.getItem(filtersKey) === '0') init = false; } catch(e) {}
    setFiltersOpen(init);
    filtersToggle.addEventListener('click', function() {
      setFiltersOpen(assetsLayout.dataset.filtersOpen === 'false');
    });
  }
  if (filtersBackdrop) {
    filtersBackdrop.addEventListener('click', function() { setFiltersOpen(false); });
  }

  // Clear filters button
  const clearFiltersBtn = document.getElementById('clearFiltersBtn');
  if (clearFiltersBtn) {
    clearFiltersBtn.addEventListener('click', function() {
      ['search-input','status-filter','category-filter','model-filter','location-filter','manufacturer-filter'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) { el.value = ''; el.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
  }
});
</script>

<style>
.snipe-reauth-banner {
  display: flex;
  align-items: center;
  gap: .75rem;
  flex-wrap: wrap;
  background: var(--color-warning-bg, #fffbeb);
  border: 1px solid var(--color-warning-border, #f6ad55);
  border-radius: 8px;
  padding: .75rem 1rem;
  margin-bottom: 1rem;
  font-size: .875rem;
}
[data-kit-theme="dark"] .snipe-reauth-banner {
  background: rgba(246,173,85,.1);
  border-color: rgba(246,173,85,.4);
}
.snipe-reauth-msg  { flex: 1; color: var(--color-text-primary, #1a202c); }
[data-kit-theme="dark"] .snipe-reauth-msg { color: var(--color-text-primary, #f0f4ff); }
.snipe-reauth-btn  {
  background: var(--color-accent, #4f6ef7);
  color: #fff;
  font-weight: 600;
  padding: .375rem .875rem;
  border-radius: 6px;
  text-decoration: none;
  white-space: nowrap;
  font-size: .8125rem;
}
.snipe-reauth-btn:hover { opacity: .88; }
.snipe-reauth-dismiss {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.125rem;
  line-height: 1;
  color: var(--color-text-muted, #718096);
  padding: 0 .25rem;
}
</style>

<script src="/assets/api-response-validation.js"></script>
<script src="/assets/pages/assets.js?v=<?= filemtime(__DIR__ . '/../../assets/pages/assets.js') ?>"></script>
<?php require __DIR__ . '/footer.php'; ?>
