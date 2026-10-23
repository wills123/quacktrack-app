<?php
$title = 'Region Management';
$regions = $regions ?? [];
$total_regions = $total_regions ?? 0;
$search = $search ?? '';
$total_pages = $total_pages ?? 1;
$page = $page ?? 1;
$main_class = 'container admin-page';
require __DIR__ . '/../header.php';
?>
<style>
.badge { display:inline-block; font-size:0.72rem; font-weight:600; padding:2px 8px; border-radius:999px; text-transform:uppercase; letter-spacing:.03em; }
.badge--green { background:var(--badge-in-bg); color:var(--badge-in-text); }
</style>

<?php foreach (flashes() as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<h1>Region Management</h1>

<h2>Add New Region</h2>
<form method="post" action="/admin/regions.php">
  <?=csrf_field()?>
  <input type="hidden" name="action" value="add_region">
  <label>Region Name<input type="text" name="name" required></label>
  <button type="submit" class="btn btn-primary">Add Region</button>
</form>

<h2>Search & Filter Regions (Showing <?= count($regions) ?> of <?= $total_regions ?>)</h2>

<!-- Search Form -->
<form method="get" action="/admin/regions.php" style="margin-bottom: 20px;">
  <label style="display: flex; gap: 10px; align-items: center;">
    Search:
    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by region name...">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if (!empty($search)) : ?>
      <a href="/admin/regions.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </label>
</form>

<h2>Existing Regions</h2>
<?php if (empty($regions)) : ?>
  <p>No regions found.</p>
<?php else : ?>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Allow Registration</th>
        <th>Snipe-IT Location</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($regions as $region) : ?>
        <tr>
          <td data-label="ID"><?= (int)$region['id'] ?></td>
          <td data-label="Name"><?= h($region['name']) ?></td>
          <td data-label="Allow Registration">
            <form method="post" action="/admin/regions.php" class="allow-registration-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="set_region_registration">
              <input type="hidden" name="region_id" value="<?= (int)$region['id'] ?>">
              <label style="display: inline-flex; gap: 5px; align-items: center;">
                <input type="checkbox" name="allow_registration" value="1" <?= $region['allow_registration'] ? 'checked' : '' ?> onchange="this.form.submit()">
                <span>Allow registration</span>
              </label>
              <noscript>
                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
              </noscript>
            </form>
          </td>
          <td data-label="Snipe-IT Location">
            <?php if ($region['snipe_location_id']) : ?>
              <span class="badge badge--green"><?= h($region['snipe_location_name'] ?? 'Location #' . $region['snipe_location_id']) ?></span>
            <?php else : ?>
              <span class="muted-text">Not mapped</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <button
              class="btn btn-secondary btn-sm js-region-edit"
              data-region-id="<?= (int)$region['id'] ?>"
              data-region-name="<?= h($region['name']) ?>"
            >Edit</button>
            <button
              class="btn btn-secondary btn-sm js-snipe-map"
              data-region-id="<?= (int)$region['id'] ?>"
              data-region-name="<?= h($region['name']) ?>"
              data-snipe-location-id="<?= (int)($region['snipe_location_id'] ?? 0) ?>"
            >Snipe-IT Location</button>
            <form method="post" action="/admin/regions.php" style="display:inline-block;" class="delete-region-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete_region">
              <input type="hidden" name="region_id" value="<?= (int)$region['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Pagination Controls -->
    <?php if ($total_pages > 1) : ?>
    <div style="display: flex; gap: 10px; align-items: center; margin-top: 20px; flex-wrap: wrap;">
      <span>Page <?= $page ?> of <?= $total_pages ?></span>

        <?php if ($page > 1) : ?>
        <a href="<?= "/admin/regions.php?" . http_build_query(['page' => 1, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">First</a>
        <a href="<?= "/admin/regions.php?" . http_build_query(['page' => $page - 1, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">Previous</a>
        <?php endif; ?>

      <!-- Page number selector -->
      <form method="get" action="/admin/regions.php" style="display: flex; gap: 5px; align-items: center;">
        <input type="hidden" name="search" value="<?= h($search) ?>">
        <label style="display: flex; gap: 5px; align-items: center;">
          Go to page:
          <input type="number" name="page" min="1" max="<?= $total_pages ?>" value="<?= $page ?>" style="width: 60px;">
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
      </form>

        <?php if ($page < $total_pages) : ?>
        <a href="<?= "/admin/regions.php?" . http_build_query(['page' => $page + 1, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">Next</a>
        <a href="<?= "/admin/regions.php?" . http_build_query(['page' => $total_pages, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">Last</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Snipe-IT Location Mapping Modal -->
<div id="snipeMapModal" class="modal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:480px;">
    <div class="modal-header">
      <h2>Map Snipe-IT Location</h2>
      <button class="close-button" type="button" id="snipeMapClose" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="/admin/regions.php" id="snipeMapForm">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="set_snipe_location">
      <input type="hidden" name="region_id" id="snipeMapRegionId">
      <div class="modal-body">
        <p class="page-subtitle" id="snipeMapRegionLabel" style="margin-bottom:1rem;"></p>
        <div class="form-group">
          <label for="snipeLocationSelect">Snipe-IT Location</label>
          <?php if (empty($snipe_locations)) : ?>
            <p class="muted-text">No Snipe-IT locations found. Make sure <code>SNIPE_IT_ENABLED=1</code> is set and Snipe-IT is reachable.</p>
            <input type="hidden" name="snipe_location_id" value="0">
            <input type="hidden" name="snipe_location_name" value="">
          <?php else : ?>
            <select id="snipeLocationSelect" name="snipe_location_id">
              <option value="0">— No mapping (remove) —</option>
              <?php foreach ($snipe_locations as $loc) : ?>
                <option value="<?= (int)$loc['id'] ?>" data-name="<?= h($loc['name']) ?>">
                    <?= h($loc['name']) ?>
                    <?= !empty($loc['city']) ? '(' . h($loc['city']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="snipe_location_name" id="snipeLocationName">
          <?php endif; ?>
        </div>
        <p class="muted-text" style="font-size:0.85rem;">
          When a kit is shipped to a store in this region, assets will be checked out to this Snipe-IT location.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="snipeMapCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Mapping</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Region Modal -->
<div id="editRegionModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Region</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/regions.php">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="edit_region">
        <input type="hidden" name="region_id" id="editRegionId">

        <div class="form-group">
          <label>Region Name<input type="text" name="name" id="editRegionName" required></label>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('editRegionModal'))">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.querySelector('#editRegionModal form').submit()">Save Changes</button>
    </div>
  </div>
</div>

<script>
  const editModal = document.getElementById('editRegionModal');
  const editCloseButton = editModal.querySelector('.close-button');

  function openEditModal(id, name) {
    document.getElementById('editRegionId').value = id;
    document.getElementById('editRegionName').value = name;

    // Use ModalManager if available, fallback to direct display
    if (window.ModalManager) {
      ModalManager.show(editModal);
    } else {
      editModal.style.display = 'block';
    }
  }

  // The ModalManager will handle close events, but keep fallback for compatibility
  editCloseButton.addEventListener('click', () => {
    if (window.ModalManager) {
      ModalManager.hide(editModal);
    } else {
      editModal.style.display = 'none';
    }
  });

  window.addEventListener('click', (event) => {
    if (event.target == editModal) {
      if (window.ModalManager) {
        ModalManager.hide(editModal);
      } else {
        editModal.style.display = 'none';
      }
    }
  });

  // Handle region delete confirmations
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-region-edit').forEach(button => {
      button.addEventListener('click', () => {
        openEditModal(
          button.dataset.regionId,
          button.dataset.regionName || ''
        );
      });
    });

    // Snipe-IT location mapping modal
    const snipeMapModal   = document.getElementById('snipeMapModal');
    const snipeMapRegionId = document.getElementById('snipeMapRegionId');
    const snipeMapRegionLabel = document.getElementById('snipeMapRegionLabel');
    const snipeLocationSelect = document.getElementById('snipeLocationSelect');
    const snipeLocationName   = document.getElementById('snipeLocationName');

    document.querySelectorAll('.js-snipe-map').forEach(btn => {
      btn.addEventListener('click', function () {
        snipeMapRegionId.value = this.dataset.regionId;
        snipeMapRegionLabel.textContent = 'Region: ' + this.dataset.regionName;
        if (snipeLocationSelect) {
          snipeLocationSelect.value = this.dataset.snipeLocationId || '0';
        }
        if (window.ModalManager) {
          ModalManager.show(snipeMapModal);
        } else {
          snipeMapModal.style.display = 'flex';
          snipeMapModal.setAttribute('aria-hidden', 'false');
        }
      });
    });

    document.getElementById('snipeMapClose')?.addEventListener('click', () => {
      if (window.ModalManager) ModalManager.hide(snipeMapModal);
      else { snipeMapModal.style.display = 'none'; snipeMapModal.setAttribute('aria-hidden', 'true'); }
    });
    document.getElementById('snipeMapCancel')?.addEventListener('click', () => {
      if (window.ModalManager) ModalManager.hide(snipeMapModal);
      else { snipeMapModal.style.display = 'none'; snipeMapModal.setAttribute('aria-hidden', 'true'); }
    });

    // Keep the hidden name field in sync with the select
    snipeLocationSelect?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      if (snipeLocationName) snipeLocationName.value = opt.dataset.name || '';
    });

    document.querySelectorAll('.delete-region-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();

        QuackNotify.confirm('Are you sure you want to delete this region? This will also unassign users from this region.', {
          title: 'Delete Region',
          confirmText: 'Delete',
          cancelText: 'Cancel',
          confirmStyle: 'danger'
        }).then(confirmed => {
          if (confirmed) {
            this.submit();
          }
        });
      });
    });
  });
</script>

<?php require __DIR__ . '/../footer.php'; ?>
