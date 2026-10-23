<?php
$title = 'Store Management';
$regions = $regions ?? [];
$stores = $stores ?? [];
$total_stores = $total_stores ?? 0;
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

<h1>Store Management</h1>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
  <!-- Add Single Store -->
  <div>
    <h2>Add New Store</h2>
    <form method="post" action="/admin/stores.php">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="add_store">
      <label>Store Name<input type="text" name="name" required></label>
      <label>Store Number<input type="text" name="store_number"></label>
      <label>Project Number<input type="text" name="project_number"></label>
      <label>Region
        <select name="region_id">
          <option value="">-- No Region --</option>
          <?php foreach ($regions as $region) : ?>
            <option value="<?= (int)$region['id'] ?>"><?= h($region['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Address<input type="text" name="address"></label>
      <label>Contact Info<input type="text" name="contact_info"></label>
      <button type="submit" class="btn btn-primary">Add Store</button>
    </form>
  </div>

  <!-- Bulk Import -->
  <div>
    <h2>Bulk Import Stores</h2>
    <form method="post" action="/admin/stores.php" enctype="multipart/form-data">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="bulk_import">
      <label>Upload CSV File
        <input type="file" name="stores_file" accept=".csv" required>
      </label>
      <p style="font-size: 12px; color: var(--text-secondary); margin: 8px 0;">
        CSV/TSV uses <code>Store #</code> first, then <code>name</code> or <code>Store Name</code>, to find and update existing stores. <code>Project Number</code> updates the local deployment project code. The first two digits of Store # assign the numeric region; blank cells leave existing values unchanged.
      </p>
      <button type="submit" class="btn btn-info">Import Stores</button>
    </form>
    <form method="post" action="/admin/stores.php" style="margin-top: 10px;">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="backfill_store_regions">
      <button type="submit" class="btn btn-secondary">Backfill Regions from Store #</button>
    </form>
  </div>
</div>

<h2>Search & Filter Stores (Showing <?= count($stores) ?> of <?= $total_stores ?>)</h2>

<!-- Search Form -->
<form method="get" action="/admin/stores.php" style="margin-bottom: 20px;">
  <label style="display: flex; gap: 10px; align-items: center;">
    Search:
    <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by store name, number, project, address, or contact...">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if (!empty($search)) : ?>
      <a href="/admin/stores.php" class="btn btn-secondary">Clear</a>
    <?php endif; ?>
  </label>
</form>

<h2>Existing Stores</h2>
<?php if (empty($stores)) : ?>
  <p>No stores found.</p>
<?php else : ?>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Store #</th>
        <th>Project #</th>
        <th>Region</th>
        <th>Address</th>
        <th>Contact Info</th>
        <th>Snipe-IT Location</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stores as $store) : ?>
        <tr>
          <td data-label="ID"><?= (int)$store['id'] ?></td>
          <td data-label="Name"><?= h($store['name']) ?></td>
          <td data-label="Store #"><?= h($store['store_number'] ?? '—') ?></td>
          <td data-label="Project #"><?= h($store['project_number'] ?? '—') ?></td>
          <td data-label="Region"><?= h($store['region_name'] ?? '—') ?></td>
          <td data-label="Address"><?= h($store['address'] ?? '') ?></td>
          <td data-label="Contact Info"><?= h($store['contact_info'] ?? '') ?></td>
          <td data-label="Snipe-IT Location">
            <?php if ($store['snipe_location_id']) : ?>
              <span class="badge badge--green"><?= h($store['snipe_location_name'] ?? 'Location #' . $store['snipe_location_id']) ?></span>
            <?php else : ?>
              <span class="muted-text">Not mapped</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <button
              class="btn btn-secondary btn-sm js-store-edit"
              data-store-id="<?= (int)$store['id'] ?>"
              data-store-name="<?= h($store['name']) ?>"
              data-store-number="<?= h($store['store_number'] ?? '') ?>"
              data-store-project-number="<?= h($store['project_number'] ?? '') ?>"
              data-store-region="<?= (int)($store['region_id'] ?? 0) ?>"
              data-store-address="<?= h($store['address'] ?? '') ?>"
              data-store-contact="<?= h($store['contact_info'] ?? '') ?>"
            >Edit</button>
            <button
              class="btn btn-secondary btn-sm js-snipe-map"
              data-store-id="<?= (int)$store['id'] ?>"
              data-store-name="<?= h($store['name']) ?>"
              data-snipe-location-id="<?= (int)($store['snipe_location_id'] ?? 0) ?>"
            >Snipe-IT Location</button>
            <form method="post" action="/admin/stores.php" style="display:inline-block;" class="delete-store-form">
              <?=csrf_field()?>
              <input type="hidden" name="action" value="delete_store">
              <input type="hidden" name="store_id" value="<?= (int)$store['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" <?= ((int)$store['id'] === 1) ? 'disabled' : '' ?>>Delete</button>
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
        <a href="<?= "/admin/stores.php?" . http_build_query(['page' => 1, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">First</a>
        <a href="<?= "/admin/stores.php?" . http_build_query(['page' => $page - 1, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">Previous</a>
        <?php endif; ?>

      <!-- Page number selector -->
      <form method="get" action="/admin/stores.php" style="display: flex; gap: 5px; align-items: center;">
        <input type="hidden" name="search" value="<?= h($search) ?>">
        <label style="display: flex; gap: 5px; align-items: center;">
          Go to page:
          <input type="number" name="page" min="1" max="<?= $total_pages ?>" value="<?= $page ?>" style="width: 60px;">
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">Go</button>
      </form>

        <?php if ($page < $total_pages) : ?>
        <a href="<?= "/admin/stores.php?" . http_build_query(['page' => $page + 1, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">Next</a>
        <a href="<?= "/admin/stores.php?" . http_build_query(['page' => $total_pages, 'search' => $search]) ?>" class="btn btn-secondary btn-sm">Last</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php $pending_region_prefixes = $_SESSION['pending_region_review'] ?? []; ?>
<?php if (!empty($pending_region_prefixes)) : ?>
<div id="regionReviewModal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="regionReviewTitle">
  <div class="modal-content" style="max-width:620px;">
    <div class="modal-header">
      <h2 id="regionReviewTitle">Region Review Needed</h2>
    </div>
    <form method="post" action="/admin/stores.php">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="resolve_region_prefixes">
      <div class="modal-body">
        <p>These Store # prefixes do not have a numeric region yet. Choose how to handle each one.</p>
        <?php foreach ($pending_region_prefixes as $prefix) : ?>
          <label style="display:block; margin: 14px 0;">
            Store # prefix <strong><?= h($prefix) ?></strong>
            <select name="region_action[<?= h($prefix) ?>]">
              <option value="create">Create region <?= h($prefix) ?></option>
              <option value="unassigned">Leave stores unassigned</option>
              <?php foreach ($regions as $region) : ?>
                <option value="region:<?= (int)$region['id'] ?>">Assign to <?= h($region['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Apply Region Choices</button>
        <button type="submit" class="btn btn-secondary" name="action" value="dismiss_region_review">Leave Unassigned</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Snipe-IT Location Mapping Modal -->
<div id="snipeMapModal" class="modal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content" style="max-width:480px;">
    <div class="modal-header">
      <h2>Map Snipe-IT Location</h2>
      <button class="close-button" type="button" id="snipeMapClose" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="/admin/stores.php" id="snipeMapForm">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="set_snipe_location">
      <input type="hidden" name="store_id" id="snipeMapStoreId">
      <div class="modal-body">
        <p class="page-subtitle" id="snipeMapStoreLabel" style="margin-bottom:1rem;"></p>
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
          When a kit is shipped to this store, assets will be checked out to this Snipe-IT location.
          Store-level mapping takes priority over the region-level mapping.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="snipeMapCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Mapping</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Store Modal -->
<div id="editStoreModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Store</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/admin/stores.php">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="edit_store">
        <input type="hidden" name="store_id" id="editStoreId">

        <div class="form-group">
          <label>Store Name<input type="text" name="name" id="editStoreName" required></label>
          <label>Store Number<input type="text" name="store_number" id="editStoreNumber"></label>
          <label>Project Number<input type="text" name="project_number" id="editStoreProjectNumber"></label>
        </div>

        <div class="form-group">
          <label>Region
            <select name="region_id" id="editStoreRegion">
              <option value="">-- No Region --</option>
              <?php foreach ($regions as $region) : ?>
                <option value="<?= (int)$region['id'] ?>"><?= h($region['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="form-group">
          <label>Address<input type="text" name="address" id="editStoreAddress"></label>
        </div>

        <div class="form-group">
          <label>Contact Info<input type="text" name="contact_info" id="editStoreContactInfo"></label>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('editStoreModal'))">Cancel</button>
      <button type="submit" class="btn btn-primary" onclick="document.querySelector('#editStoreModal form').submit()">Save Changes</button>
    </div>
  </div>
</div>

<script>
  const regionReviewModal = document.getElementById('regionReviewModal');
  document.addEventListener('DOMContentLoaded', () => {
    if (regionReviewModal && window.ModalManager) {
      ModalManager.show(regionReviewModal);
    }
  });

  const editModal = document.getElementById('editStoreModal');
  const editCloseButton = editModal.querySelector('.close-button');

  function openEditModal(id, name, storeNumber, projectNumber, regionId, address, contactInfo) {
    document.getElementById('editStoreId').value = id;
    document.getElementById('editStoreName').value = name;
    document.getElementById('editStoreNumber').value = storeNumber;
    document.getElementById('editStoreProjectNumber').value = projectNumber;
    document.getElementById('editStoreRegion').value = regionId || '';
    document.getElementById('editStoreAddress').value = address;
    document.getElementById('editStoreContactInfo').value = contactInfo;

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

  // Handle store delete confirmations
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-store-edit').forEach(button => {
      button.addEventListener('click', () => {
        openEditModal(
          button.dataset.storeId,
          button.dataset.storeName || '',
          button.dataset.storeNumber || '',
          button.dataset.storeProjectNumber || '',
          button.dataset.storeRegion || '',
          button.dataset.storeAddress || '',
          button.dataset.storeContact || ''
        );
      });
    });

    // Snipe-IT location mapping modal
    const snipeMapModal    = document.getElementById('snipeMapModal');
    const snipeMapStoreId  = document.getElementById('snipeMapStoreId');
    const snipeMapStoreLabel = document.getElementById('snipeMapStoreLabel');
    const snipeLocationSelect = document.getElementById('snipeLocationSelect');
    const snipeLocationName   = document.getElementById('snipeLocationName');

    function openSnipeModal(storeId, storeName, currentLocationId) {
      snipeMapStoreId.value = storeId;
      snipeMapStoreLabel.textContent = 'Store: ' + storeName;
      if (snipeLocationSelect) snipeLocationSelect.value = currentLocationId || '0';
      if (snipeLocationName && snipeLocationSelect) {
        const opt = snipeLocationSelect.options[snipeLocationSelect.selectedIndex];
        snipeLocationName.value = opt?.dataset.name || '';
      }
      if (window.ModalManager) ModalManager.show(snipeMapModal);
      else { snipeMapModal.style.display = 'flex'; snipeMapModal.setAttribute('aria-hidden', 'false'); }
    }

    function closeSnipeModal() {
      if (window.ModalManager) ModalManager.hide(snipeMapModal);
      else { snipeMapModal.style.display = 'none'; snipeMapModal.setAttribute('aria-hidden', 'true'); }
    }

    document.querySelectorAll('.js-snipe-map').forEach(btn => {
      btn.addEventListener('click', function () {
        openSnipeModal(this.dataset.storeId, this.dataset.storeName, this.dataset.snipeLocationId);
      });
    });

    document.getElementById('snipeMapClose')?.addEventListener('click', closeSnipeModal);
    document.getElementById('snipeMapCancel')?.addEventListener('click', closeSnipeModal);

    snipeLocationSelect?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      if (snipeLocationName) snipeLocationName.value = opt?.dataset.name || '';
    });

    document.querySelectorAll('.delete-store-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();

        QuackNotify.confirm('Are you sure you want to delete this store? This will also unassign van stock items from this store.', {
          title: 'Delete Store',
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
