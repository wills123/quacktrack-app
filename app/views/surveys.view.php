<?php
// @var array $regions
// @var array $stores
// @var bool $canSubmit
// @var bool $canViewAll
// @var bool $canManageTemplates
// @var bool $canCompare
$regions = $regions ?? [];
$stores = $stores ?? [];
$canSubmit = $canSubmit ?? false;
$canViewAll = $canViewAll ?? false;
$canManageTemplates = $canManageTemplates ?? false;
$canCompare = $canCompare ?? false;

require __DIR__ . '/header.php';
?>
<script>
/* Anti-flash: apply saved theme + hide legacy topbar before first paint */
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
  var m = document.querySelector('.app-main');
  if (m) m.classList.add('od-active');
}());
</script>

<div class="svx-page survey-page" data-survey-view="list">

  <!-- ── Page header ──────────────────────────────────────────── -->
  <div class="page-masthead svx-header">
    <div class="svx-header__inner">
      <div class="svx-header__brand">
        <h1 class="svx-header__title">Store Surveys</h1>
        <p class="svx-header__sub">IT standards walkaround by store</p>
      </div>
      <div class="masthead-tabs svx-header__actions">
        <?php if ($canManageTemplates) : ?>
          <a href="/surveys?view=template" class="masthead-tab">Manage Template</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Store selector ───────────────────────────────────────── -->
  <div class="svx-selector-bar">
    <div class="svx-selector-group">
      <label class="svx-label" for="surveyRegionSelect">Region</label>
      <select id="surveyRegionSelect" class="svx-select">
        <option value="">All Regions</option>
        <?php foreach ($regions as $region) : ?>
          <option value="<?= (int)$region['id'] ?>"><?= h($region['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="svx-selector-group">
      <label class="svx-label" for="surveyStoreSelect">Store</label>
      <select id="surveyStoreSelect" class="svx-select" disabled>
        <option value="">Select a store…</option>
        <?php foreach ($stores as $store) : ?>
          <option value="<?= (int)$store['id'] ?>" data-region-id="<?= (int)($store['region_id'] ?? 0) ?>"><?= h($store['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- ── Store content (hidden until store selected) ──────────── -->
  <div id="surveyStoreContent" class="svx-content" style="display:none;">

    <div class="svx-content-header">
      <h2 class="svx-content-title" id="surveyStoreTitle">Survey History</h2>
      <div id="surveyActionButtons" class="svx-content-actions" style="display:none;">
        <?php if ($canSubmit) : ?>
          <button type="button" class="svx-btn svx-btn--primary" id="surveyNewBtn">+ New Survey</button>
        <?php endif; ?>
      </div>
    </div>

    <div id="surveyDraftNotice" class="svx-notice svx-notice--info" style="display:none;">
      You have an in-progress draft for this store.
      <a href="#" id="btnResumeDraft" class="svx-notice__link">Resume Draft</a>
    </div>

    <!-- Filters -->
    <div class="svx-filters">
      <div class="svx-filter-group">
        <label class="svx-label" for="surveyFilterRole">Role</label>
        <select id="surveyFilterRole" class="svx-select svx-select--sm">
          <option value="">All</option>
          <option value="tech">Tech</option>
          <option value="manager">Manager</option>
        </select>
      </div>
      <div class="svx-filter-group">
        <label class="svx-label" for="surveyFilterDateFrom">From</label>
        <input type="date" id="surveyFilterDateFrom" class="svx-input svx-input--sm">
      </div>
      <div class="svx-filter-group">
        <label class="svx-label" for="surveyFilterDateTo">To</label>
        <input type="date" id="surveyFilterDateTo" class="svx-input svx-input--sm">
      </div>
    </div>

    <!-- History table -->
    <div id="surveyHistoryContainer" class="svx-table-wrap">
      <table class="svx-table" id="surveyHistoryTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Submitter</th>
            <th>Role</th>
            <th>Version</th>
            <th>Avg Score</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="surveyHistoryBody">
          <tr><td colspan="6" class="svx-table__empty">Select a store to view survey history.</td></tr>
        </tbody>
      </table>
      <div id="surveyPagination" class="svx-pagination"></div>
    </div>

  </div>
</div>

<script>
  window.SURVEY_PAGE = {
    canSubmit: <?= json_encode($canSubmit) ?>,
    canViewAll: <?= json_encode($canViewAll) ?>,
    canManageTemplates: <?= json_encode($canManageTemplates) ?>,
    canCompare: <?= json_encode($canCompare) ?>
  };
</script>
<script src="/assets/pages/surveys.js"></script>

<?php require __DIR__ . '/footer.php'; ?>
