<?php
$current_user_id = $current_user_id ?? 0;
$is_admin = $is_admin ?? false;
$can_manage_regions = $can_manage_regions ?? false;
require __DIR__ . '/header.php';
?>

<div class="stock-page travel-page"
     data-current-user-id="<?= (int)$current_user_id ?>"
     data-is-admin="<?= $is_admin ? '1' : '0' ?>"
     data-can-manage-regions="<?= $can_manage_regions ? '1' : '0' ?>">

  <!-- =====================================================================
       MASTHEAD
  ====================================================================== -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Travel Spots</h1>
        <p class="stock-masthead-sub">Share great spots, flag places to avoid, and keep regional notes in one place.</p>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <button class="masthead-tab" type="button" id="travelRefreshBtn">Refresh</button>
      </div>
    </div>
  </div>

  <!-- =====================================================================
       STAT STRIP
  ====================================================================== -->
  <div class="stock-stats">
    <div class="stock-stat">
      <span class="stock-stat-value" id="statTotalPlaces">—</span>
      <span class="stock-stat-label">Total Places</span>
    </div>
    <div class="stock-stat">
      <span class="stock-stat-value" id="statRecommended">—</span>
      <span class="stock-stat-label">Recommended</span>
    </div>
    <div class="stock-stat">
      <span class="stock-stat-value" id="statAvoid">—</span>
      <span class="stock-stat-label">Avoid</span>
    </div>
    <div class="stock-stat">
      <span class="stock-stat-value" id="statVisited">—</span>
      <span class="stock-stat-label">Visited</span>
    </div>
  </div>

  <?php foreach (flashes() as [$t, $m]) : ?>
    <div class="flash <?= h($t) ?>"><?= h($m) ?></div>
  <?php endforeach; ?>

  <div id="travelMessage" class="travel-message" role="status" aria-live="polite"></div>

  <!-- =====================================================================
       BODY — sidebar + main
  ====================================================================== -->
  <div class="stock-layout">

    <!-- Sidebar: filters + region notes -->
    <aside class="filters-sidebar" aria-label="Travel filters">

      <div class="filters-header">
        <h2>Filters</h2>
      </div>

      <div class="filters-group">
        <label for="travelSearchInput">Search</label>
        <input type="search" id="travelSearchInput" placeholder="Places, notes, regions…">
      </div>

      <div class="filters-group">
        <label for="travelRegionFilter">Region</label>
        <select id="travelRegionFilter">
          <option value="0">All regions</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="travelTypeFilter">Type</label>
        <select id="travelTypeFilter">
          <option value="all">All types</option>
          <option value="recommended">Recommended</option>
          <option value="avoid">Avoid</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="travelVisitedFilter">Visited</label>
        <select id="travelVisitedFilter">
          <option value="all">All</option>
          <option value="1">Visited</option>
          <option value="0">Not visited</option>
        </select>
      </div>

      <div class="filters-group">
        <label for="travelTagFilter">Tag</label>
        <select id="travelTagFilter">
          <option value="">All tags</option>
        </select>
      </div>

      <!-- Region notes -->
      <div class="travel-region-notes-panel">
        <h3 class="travel-sidebar-section-title">Region Notes</h3>
        <div class="filters-group">
          <label for="travelRegionNotes">Notes for selected region</label>
          <textarea id="travelRegionNotes" rows="4" placeholder="Select a region to view notes" disabled></textarea>
        </div>
        <button class="travel-btn-primary" type="button" id="travelSaveRegionNotes" disabled>Save Notes</button>
      </div>

    </aside>

    <!-- Main: add-place form + results -->
    <main class="stock-main">

      <!-- Add / Edit place form -->
      <div class="travel-form-card">
        <div class="travel-form-card-header">
          <h2 class="travel-form-card-title" id="travelPlaceFormTitle">Add a place</h2>
          <button type="button" class="travel-btn-link" id="travelCancelEdit" hidden>Cancel edit</button>
        </div>

        <form id="travelPlaceForm" class="travel-form no-loading no-validation" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="id" id="travelPlaceId" value="">

          <!-- Row 1: Name + Region -->
          <div class="travel-form-row">
            <div class="travel-form-group">
              <label class="travel-form-label" for="travelPlaceName">Place name <span class="travel-required">*</span></label>
              <input class="travel-form-input" type="text" id="travelPlaceName" name="name" required placeholder="e.g. Blue Ridge Diner">
            </div>
            <div class="travel-form-group">
              <label class="travel-form-label" for="travelPlaceRegion">Region <span class="travel-required">*</span></label>
              <select class="travel-form-select" id="travelPlaceRegion" name="region_id" required>
                <option value="">Select region</option>
              </select>
            </div>
          </div>

          <!-- Row 2: Type + Safety Level (conditional, same row — no gap when hidden) -->
          <div class="travel-form-row">
            <div class="travel-form-group">
              <label class="travel-form-label" for="travelPlaceType">Type <span class="travel-required">*</span></label>
              <select class="travel-form-select" id="travelPlaceType" name="type" required>
                <option value="recommended">Recommended</option>
                <option value="avoid">Avoid</option>
              </select>
            </div>
            <div class="travel-form-group" id="travelSafetyGroup">
              <label class="travel-form-label" for="travelSafetyLevel">Safety level <span class="travel-form-hint-inline">(avoid only)</span></label>
              <select class="travel-form-select" id="travelSafetyLevel" name="safety_level">
                <option value="">Not set</option>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select>
            </div>
          </div>

          <!-- Row 3: Notes full width -->
          <div class="travel-form-group">
            <label class="travel-form-label" for="travelPlaceDescription">Notes</label>
            <textarea class="travel-form-input travel-form-textarea" id="travelPlaceDescription" name="description" rows="3" placeholder="Why it's great or why it should be avoided"></textarea>
          </div>

          <!-- Row 4: Tags + Photo side by side, top-aligned -->
          <div class="travel-form-row travel-form-row--top">
            <div class="travel-form-group">
              <label class="travel-form-label" for="travelPlaceTags">Tags</label>
              <input class="travel-form-input" type="text" id="travelPlaceTags" name="tags" placeholder="beach, quiet, late-night">
              <p class="travel-form-hint">Comma separated — e.g. beach, coffee, day-trip</p>
            </div>
            <div class="travel-form-group">
              <label class="travel-form-label" for="travelPlacePhoto">Photo <span class="travel-form-hint-inline">(optional)</span></label>
              <label class="travel-photo-upload-zone" for="travelPlacePhoto">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 3v10M6 7l4-4 4 4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 14v1a2 2 0 002 2h10a2 2 0 002-2v-1" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
                <span>Choose photo</span>
                <input class="travel-form-file" type="file" id="travelPlacePhoto" name="photo" accept="image/*">
              </label>
              <div id="travelPhotoPreview" class="travel-photo-preview" hidden></div>
              <label class="travel-remove-photo-label" id="travelRemovePhotoWrap" hidden>
                <input type="checkbox" id="travelRemovePhoto" name="remove_photo" value="1" class="travel-check">
                Remove current photo
              </label>
            </div>
          </div>

          <!-- Footer: Visited toggle + Save button -->
          <div class="travel-form-footer">
            <label class="travel-visited-chip" for="travelVisited">
              <input type="checkbox" id="travelVisited" name="visited" value="1" class="travel-check">
              <svg class="travel-visited-chip-icon" width="13" height="13" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              Visited
            </label>
            <button type="submit" class="travel-btn-primary" id="travelPlaceSubmit">Save Place</button>
          </div>
        </form>
      </div>

      <!-- Results -->
      <div class="travel-results">
        <div class="travel-results-header">
          <h2 class="travel-results-title">Places</h2>
          <span class="travel-results-meta" id="travelResultsMeta"></span>
        </div>

        <div id="travelPlaces" class="travel-cards"></div>

        <div id="travelEmptyState" class="travel-empty-state" hidden>
          <svg class="travel-empty-icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <circle cx="24" cy="20" r="10" stroke="currentColor" stroke-width="2.5"/>
            <path d="M24 10v2M24 28v2M14 20h-2M36 20h-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M24 30c0 0-10 8-10 14h20c0-6-10-14-10-14z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" fill="none"/>
          </svg>
          <p class="travel-empty-title">No places yet</p>
          <p class="travel-empty-text">Add the first spot to start building the list.</p>
        </div>
      </div>

    </main>
  </div>
</div>

<script src="/assets/pages/travel.js"></script>

<script>
// Update stat strip from rendered places data
(function () {
  var container = document.getElementById('travelPlaces');
  if (!container) return;

  var observer = new MutationObserver(function () {
    var cards = container.querySelectorAll('.travel-card');
    var total = cards.length;
    var recommended = 0, avoid = 0, visited = 0;
    cards.forEach(function (card) {
      if (card.querySelector('.travel-badge.recommended')) recommended++;
      if (card.querySelector('.travel-badge.avoid:not([class*="Safety"])')) avoid++;
      if (card.querySelector('.travel-badge.visited')) visited++;
    });
    var s = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
    s('statTotalPlaces', total);
    s('statRecommended', recommended);
    s('statAvoid', avoid);
    s('statVisited', visited);
  });

  observer.observe(container, { childList: true, subtree: false });
}());
</script>

<?php require __DIR__ . '/footer.php'; ?>
