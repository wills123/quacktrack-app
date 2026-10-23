<?php
$title      = 'Region Storage';
$main_class = 'main-content';
$regions = $regions ?? [];
$products = $products ?? [];
$stores = $stores ?? [];
$selected_display_region_name = $selected_display_region_name ?? '';
$selected_display_region_id = $selected_display_region_id ?? 0;
require __DIR__ . '/header.php';

if (!function_exists('escape_data_attr')) {
    function escape_data_attr(mixed $value): string
    {
        $value = preg_replace('/[<>]/', '', (string)$value);
        return h($value);
    }
}
?>
<script>
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
}());
</script>
<?php
$region_items = $current_region_storage ?? [];
$region_item_count = count($region_items);
$region_unit_count = 0;
$region_locations = [];
$region_low_count = 0;
foreach ($region_items as $item) {
    $qty = (int)($item['quantity'] ?? 0);
    $region_unit_count += $qty;
    if ($qty > 0 && $qty <= 5) {
        $region_low_count++;
    }
    if (!empty($item['location'])) {
        $region_locations[] = strtolower((string)$item['location']);
    }
}
$region_location_count = count(array_unique($region_locations));
$__flashes = flashes();
?>

<div class="stock-page">

  <?php if (!empty($__flashes)) : ?>
    <div style="padding: 0 40px;">
        <?php foreach ($__flashes as [$type, $msg]) : ?>
        <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
        <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Region Storage</h1>
        <div class="stock-masthead-sub">
          <?php if ($selected_display_region_name !== '') : ?>
                <?= h($selected_display_region_name) ?> · <?= number_format($region_item_count) ?> SKUs
          <?php else : ?>
            Select a region to view stock
          <?php endif; ?>
        </div>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <?php if ($selected_display_region_name !== '') : ?>
          <button id="addNewStockBtn" class="masthead-tab" type="button">+ Add Stock</button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="stock-stats">
    <div class="stock-stat">
      <div class="stock-stat-value"><?= number_format($region_item_count) ?></div>
      <div class="stock-stat-label">Total SKUs</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value"><?= number_format($region_unit_count) ?></div>
      <div class="stock-stat-label">Total Units</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value<?= $region_low_count > 0 ? ' warn' : '' ?>"><?= number_format($region_low_count) ?></div>
      <div class="stock-stat-label">Low Stock</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value"><?= number_format($region_location_count) ?></div>
      <div class="stock-stat-label">Locations</div>
    </div>
  </div>

  <!-- Two-panel body -->
  <div class="stock-layout" id="regionStorageLayout" data-filters-open="true">

    <!-- Filter sidebar -->
    <aside class="filters-sidebar" id="filtersSidebar">
      <div class="filters-header">
        <h2>Filters</h2>
        <button class="btn-link" type="button" id="clearFiltersBtn">Clear</button>
      </div>

      <div class="filters-group">
        <label for="regionSelector">Region</label>
        <select id="regionSelector">
          <?php foreach ($regions as $r) : ?>
            <option value="<?= (int)$r['id'] ?>" <?= $selected_display_region_id === (int)$r['id'] ? 'selected' : '' ?>>
                <?= h($r['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filters-group">
        <label for="locationFilter">Location</label>
        <input type="text" id="locationFilter" placeholder="Filter by location…">
      </div>
    </aside>

    <div class="filters-backdrop" id="filtersBackdrop"></div>

    <!-- Main content -->
    <section class="stock-main">
      <div class="stock-toolbar">
        <button type="button" class="od-btn od-btn--secondary" id="filtersToggle">Filters</button>
        <div class="stock-search">
          <label for="searchInput" class="sr-only">Search region storage</label>
          <input type="search" id="searchInput" placeholder="Search by name or SKU…">
        </div>
        <div class="stock-sort" aria-hidden="true"></div>
      </div>

      <?php if ($selected_display_region_name === '') : ?>
        <div class="info-banner">Select a region from the Filters sidebar to view stock.</div>
      <?php elseif (empty($current_region_storage)) : ?>
        <div class="stock-empty">
          <div class="stock-empty-icon">🏪</div>
          <div class="stock-empty-title">No items in this region</div>
          <div class="stock-empty-body">Add stock to start tracking inventory here.</div>
          <button class="od-btn od-btn--primary" type="button" id="emptyStateAddStock">Add Stock</button>
        </div>
      <?php else : ?>
        <p class="stock-results" id="filterResults" aria-live="polite"></p>

        <!-- Stock cards -->
        <div class="stock-cards">
          <?php foreach ($current_region_storage as $item) :
                $qty = (int)$item['quantity'];
                if ($qty <= 0) {
                    $badge_label = 'Out';
                    $badge_class = 'stock-badge--out';
                    $data_status = 'out';
                } elseif ($qty <= 5) {
                    $badge_label = 'Low';
                    $badge_class = 'stock-badge--low';
                    $data_status = 'low';
                } else {
                    $badge_label = 'In stock';
                    $badge_class = 'stock-badge--in';
                    $data_status = 'in';
                }
                ?>
          <div class="stock-card"
               data-status="<?= $data_status ?>"
               data-product-name="<?= escape_data_attr(strtolower($item['name'] ?? '')) ?>"
               data-product-sku="<?= escape_data_attr(strtolower($item['sku'] ?? '')) ?>"
               data-location="<?= escape_data_attr(strtolower($item['location'] ?? '')) ?>">
            <div class="stock-card-main">

              <!-- Thumbnail -->
                <?php if (!empty($item['image_path'])) : ?>
                <img src="<?= h(image_url($item['image_path'])) ?>" alt="" class="stock-thumb" loading="lazy">
                <?php else : ?>
                <div class="stock-thumb-placeholder" aria-hidden="true">
                    <?= strtoupper(substr($item['name'] ?? 'NA', 0, 2)) ?>
                </div>
                <?php endif; ?>

              <!-- Info column -->
              <div class="stock-card-info">
                <div class="stock-card-name-row">
                  <span class="stock-card-name"><?= h($item['name']) ?></span>
                  <span class="stock-badge <?= $badge_class ?>"><?= $badge_label ?></span>
                </div>
                <?php if (!empty($item['sku'])) : ?>
                  <div class="stock-sku"><?= h($item['sku']) ?></div>
                <?php elseif (!empty($item['product_id'])) : ?>
                  <div class="stock-sku"><?= h($item['product_id']) ?></div>
                <?php endif; ?>
                <div class="stock-meta">
                  <span>📍 <?= h($item['location'] ?: 'No location') ?></span>
                </div>
              </div>

              <!-- Qty display -->
              <div class="stock-qty">
                <div class="stock-qty-label">Qty</div>
                <div class="stock-qty-value"><?= number_format($qty) ?></div>
              </div>

            </div><!-- /stock-card-main -->

            <!-- Actions -->
            <div class="stock-card-actions">
              <button class="transfer-btn"
                      data-prod-id="<?= (int)$item['product_id'] ?>"
                      data-prod-name="<?= escape_data_attr($item['name'] ?? '') ?>"
                      data-current-region="<?= escape_data_attr($selected_display_region_name) ?>"
                      data-current-region-id="<?= (int)$selected_display_region_id ?>"
                      data-available-qty="<?= $qty ?>"
                      data-current-location="<?= escape_data_attr($item['location'] ?? '') ?>"
                      title="Transfer to another region or location"
                      type="button">
                Transfer
              </button>
              <button class="add-btn quick-action-btn"
                      data-prod-id="<?= (int)$item['product_id'] ?>"
                      data-prod-name="<?= escape_data_attr($item['name'] ?? '') ?>"
                      data-current-location="<?= escape_data_attr($item['location'] ?? '') ?>"
                      data-action="add"
                      title="Add stock"
                      type="button">
                + Add
              </button>
              <button class="use-btn quick-action-btn"
                      data-prod-id="<?= (int)$item['product_id'] ?>"
                      data-prod-name="<?= escape_data_attr($item['name'] ?? '') ?>"
                      data-current-location="<?= escape_data_attr($item['location'] ?? '') ?>"
                      data-action="remove"
                      data-max-qty="<?= $qty ?>"
                      title="Remove stock"
                      type="button">
                − Use
              </button>
              <button class="details-btn edit-location-btn"
                      data-prod-id="<?= (int)$item['product_id'] ?>"
                      data-prod-name="<?= escape_data_attr($item['name'] ?? '') ?>"
                      data-current-location="<?= escape_data_attr($item['location'] ?? '') ?>"
                      title="Edit location"
                      type="button">
                Location
              </button>
            </div>

          </div><!-- /stock-card -->
          <?php endforeach; ?>
        </div><!-- /stock-cards -->

        <div class="stock-results" id="filterResultsBottom">
          Showing <?= number_format($region_item_count) ?> item<?= $region_item_count !== 1 ? 's' : '' ?>
        </div>
      <?php endif; ?>
    </section>

  </div><!-- /stock-layout -->

</div><!-- /stock-page -->

<!-- Adjustment Modal -->
<div id="adjustmentModal" class="modal" role="dialog" aria-labelledby="modal-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close modal" type="button">&times;</button>
    <h2 id="modal-title">Adjust Region Storage for <span id="modalProductName"></span></h2>
    <form method="post" action="/region-storage" id="adjustmentForm" novalidate>
      <?=csrf_field()?>
      <input type="hidden" name="action" value="adjust_region_storage">
      <input type="hidden" name="prod_id" id="modalProdId">
      <input type="hidden" name="region_id" value="<?= (int)$selected_display_region_id ?>">

      <div class="form-group">
        <label for="modalChangeQty" class="required">Quantity Change</label>
        <input type="number" name="change_qty" id="modalChangeQty" required
               aria-describedby="changeQtyHelp" step="1">
        <div id="changeQtyHelp" class="form-help">
          Enter a positive number to add stock, negative to remove stock
        </div>
      </div>

      <div class="form-group">
        <label for="modalLocation">Storage Location</label>
        <input type="text" name="location" id="modalLocation"
               placeholder="e.g., Aisle 3, Shelf B"
               aria-describedby="locationHelp">
        <div id="locationHelp" class="form-help">
          Optional: Specify where this item is stored
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Submit Adjustment</button>
        <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add New Stock Modal -->
<div id="addStockModal" class="modal" role="dialog" aria-labelledby="add-modal-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close modal" type="button">&times;</button>
    <h2 id="add-modal-title">Add Stock to <?= h($selected_display_region_name) ?></h2>
    <form method="post" action="/region-storage" id="addStockForm" novalidate>
      <?=csrf_field()?>
      <input type="hidden" name="action" value="adjust_region_storage">
      <input type="hidden" name="region_id" value="<?= (int)$selected_display_region_id ?>">

      <div class="form-group">
        <label for="addProdDisplay" class="required">Product</label>
        <div class="add-stock-product-row">
          <input type="text" id="addProdDisplay" class="add-stock-product-display" placeholder="Choose a product" readonly>
          <button type="button" class="btn btn-secondary" id="openProductPickerBtn">Choose</button>
          <button type="button" class="btn btn-info" id="openRegionScannerBtnPrimary">Scan Barcode</button>
        </div>
        <select name="prod_id" id="addProdId" required hidden>
          <option value="">Select a product</option>
          <?php foreach ($products as $p) : ?>
            <option value="<?= (int)$p['id'] ?>" data-name="<?= escape_data_attr($p['name']) ?>" data-sku="<?= escape_data_attr($p['sku'] ?? $p['product_id']) ?>" data-barcode="<?= escape_data_attr($p['barcode'] ?? '') ?>"><?= h($p['name']) ?> (<?= h($p['sku'] ?? $p['product_id']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="addChangeQty" class="required">Quantity</label>
        <input type="number" name="change_qty" id="addChangeQty" min="1" value="1" required class="positive-number" aria-describedby="addQuantityHelp">
        <div id="addQuantityHelp" class="form-help">Enter the number of units to add to this region</div>
      </div>

      <div class="form-group">
        <label for="addLocation">Storage Location</label>
        <input type="text" name="location" id="addLocation" placeholder="e.g., Aisle 3, Shelf B">
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary" id="addStockSubmitBtn">Add Stock</button>
        <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Transfer Stock Modal -->
<div id="transferModal" class="modal" role="dialog" aria-labelledby="transfer-modal-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close modal" type="button">&times;</button>
    <h2 id="transfer-modal-title">Transfer <span id="transferProductName"></span></h2>
    <p>From: <strong id="transferFromRegion"></strong> (<span id="transferSourceLocationDisplay">No location</span>) &rarr; <strong id="transferToRegion"></strong></p>
    <p>Available: <strong id="transferAvailableQty"></strong></p>

    <form method="post" action="/region-storage" id="transferForm" novalidate>
      <?=csrf_field()?>
      <input type="hidden" name="action" value="transfer_stock">
      <input type="hidden" name="prod_id" id="transferProdId">
      <input type="hidden" name="from_region_id" id="transferFromRegionId">
      <input type="hidden" name="source_location" id="transferSourceLocation">

      <div class="form-group">
        <label for="transferDestinationType" class="required">Transfer Destination</label>
        <select name="destination_type" id="transferDestinationType" required>
          <option value="region">Another Region</option>
          <option value="location">Different Location (Same Region)</option>
          <option value="van">Technician Van Stock</option>
        </select>
        <div class="form-help">Move stock to another region, different location within this region, or pull it into your van.</div>
      </div>

      <div class="form-group" data-destination-region>
        <label for="transferToRegionId" class="required">Transfer To Region</label>
        <select name="to_region_id" id="transferToRegionId" required>
          <option value="">Select destination region</option>
          <?php foreach ($regions as $r) : ?>
                <?php if ($r['id'] != $selected_display_region_id) : ?>
              <option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option>
                <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" data-destination-van style="display:none;">
        <label for="transferVanStoreId" class="required">Van Store Assignment</label>
        <select name="van_store_id" id="transferVanStoreId">
          <?php foreach ($stores as $store) : ?>
            <option value="<?= (int)$store['id'] ?>"><?= h($store['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-help">Choose which store bucket in your van should receive this stock.</div>
      </div>

      <div class="form-group">
        <label for="transferQty" class="required">Quantity to Transfer</label>
        <input type="number" name="transfer_qty" id="transferQty" min="1" required>
      </div>

      <div class="form-group" data-destination-location-field>
        <label for="transferNewLocation">Location at Destination</label>
        <input type="text" name="new_location" id="transferNewLocation" placeholder="Enter destination location">
        <div class="form-help" id="transferLocationHelp">Optional: specify location at destination region</div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Transfer Stock</button>
        <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php
// Include Product Picker Modal Component
$modal_id = 'productPickerModal';
$search_input_id = 'pickerSearch';
$list_container_id = 'pickerList';
$close_btn_id = 'pickerCloseBtn';
require __DIR__ . '/components/modals/product-picker-modal.php';
?>

<?php
// Include Scanner Modal Component
$modal_id = 'regionScannerModal';
$video_id = 'regionScannerVideo';
$result_container_id = 'regionScannerResult';
$zoom_level_id = 'regionZoomLevel';
$zoom_in_btn_id = 'regionZoomInBtn';
$zoom_out_btn_id = 'regionZoomOutBtn';
$close_btn_id = 'regionCloseScannerButton';
$stop_btn_id = 'regionStopScannerButton';
$close_footer_btn_id = 'regionScannerCloseFooter';
require __DIR__ . '/components/modals/scanner-modal.php';
?>

<!-- Edit Location Modal -->
<div id="locationModal" class="modal" role="dialog" aria-labelledby="location-modal-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close modal" type="button">&times;</button>
    <h2 id="location-modal-title">Move Stock for <span id="locationProductName"></span></h2>
    <p class="form-help">Moving all units from current location to new location. If stock exists at the new location, it will be combined.</p>

    <form method="post" action="/region-storage" id="locationForm" novalidate>
      <?=csrf_field()?>
      <input type="hidden" name="action" value="update_location">
      <input type="hidden" name="prod_id" id="locationProdId">
      <input type="hidden" name="region_id" value="<?= (int)$selected_display_region_id ?>">
      <input type="hidden" name="current_location" id="currentLocationHidden">

      <div class="form-group">
        <label for="newLocation" class="required">New Storage Location</label>
        <input type="text" name="location" id="newLocation" required
               placeholder="e.g., Aisle 3, Shelf B">
        <div class="form-help">Current location: <span id="currentLocationDisplay"></span></div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Move Stock</button>
        <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Modal management
  const adjustmentModal = document.getElementById('adjustmentModal');
  const addStockModal = document.getElementById('addStockModal');
  const transferModal = document.getElementById('transferModal');
  const locationModal = document.getElementById('locationModal');
  const transferDestinationType = document.getElementById('transferDestinationType');
  const transferRegionFields = document.querySelector('[data-destination-region]');
  const transferVanFields = document.querySelector('[data-destination-van]');
  const transferVanStoreSelect = document.getElementById('transferVanStoreId');
  const transferToRegionLabel = document.getElementById('transferToRegion');
  const transferToRegionSelect = document.getElementById('transferToRegionId');

  // Region selector — navigate on change
  const regionSelector = document.getElementById('regionSelector');
  if (regionSelector) {
    regionSelector.addEventListener('change', function() {
      window.location.href = `/region-storage?view_region_id=${this.value}`;
    });
  }

  // Add new stock button
  const addNewStockBtn = document.getElementById('addNewStockBtn');
  if (addNewStockBtn) {
    addNewStockBtn.addEventListener('click', function() {
      const addStockForm = document.getElementById('addStockForm');
      const regionIdInput = addStockForm ? addStockForm.querySelector('input[name="region_id"]') : null;
      const addLocationInput = document.getElementById('addLocation');
      if (regionIdInput && addLocationInput) {
        const key = `region_location_${regionIdInput.value}`;
        const lastLocation = localStorage.getItem(key);
        if (lastLocation && !addLocationInput.value) {
          addLocationInput.value = lastLocation;
        }
      }
      const prodSelect = document.getElementById('addProdId');
      const openPickerBtn = document.getElementById('openProductPickerBtn');
      const addProdDisplay = document.getElementById('addProdDisplay');
      if (window.ModalManager) {
        ModalManager.show(addStockModal);
      } else {
        addStockModal.style.display = 'block';
      }
      setTimeout(() => {
        if (prodSelect && addProdDisplay) {
          const opt = prodSelect.options[prodSelect.selectedIndex];
          if (opt && opt.value) {
            const name = opt.getAttribute('data-name') || opt.textContent;
            const sku = opt.getAttribute('data-sku') || '';
            addProdDisplay.value = sku ? `${name} (${sku})` : name;
          } else {
            addProdDisplay.value = '';
          }
        }
        if (openPickerBtn) openPickerBtn.focus();
        else if (prodSelect) prodSelect.focus();
      }, 0);
    });
  }

  const emptyStateAddStock = document.getElementById('emptyStateAddStock');
  if (emptyStateAddStock && addNewStockBtn) {
    emptyStateAddStock.addEventListener('click', () => addNewStockBtn.click());
  }

  // Quick action buttons (add/remove)
  document.querySelectorAll('.quick-action-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const action = this.dataset.action;
      const prodId = this.dataset.prodId;
      const prodName = this.dataset.prodName;
      const currentLocation = this.dataset.currentLocation;

      document.getElementById('modalProdId').value = prodId;
      document.getElementById('modalProductName').textContent = prodName;
      document.getElementById('modalLocation').value = currentLocation || '';

      if (action === 'add') {
        document.getElementById('modalChangeQty').value = '1';
        document.getElementById('modal-title').textContent = `Add Stock for ${prodName}`;
      } else if (action === 'remove') {
        const maxQty = this.dataset.maxQty || '1';
        document.getElementById('modalChangeQty').value = '-1';
        document.getElementById('modalChangeQty').max = maxQty;
        document.getElementById('modal-title').textContent = `Remove Stock for ${prodName}`;
      }

      if (window.ModalManager) {
        ModalManager.show(adjustmentModal);
      } else {
        adjustmentModal.style.display = 'block';
      }
    });
  });

  // Transfer destination UI
  const transferLocationField = document.querySelector('[data-destination-location-field]');
  const transferLocationHelp = document.getElementById('transferLocationHelp');
  const transferNewLocationInput = document.getElementById('transferNewLocation');
  const transferSourceLocationDisplay = document.getElementById('transferSourceLocationDisplay');

  function updateTransferDestinationUI() {
    if (!transferDestinationType || !transferToRegionLabel) return;
    const destination = transferDestinationType.value;
    if (destination === 'region') {
      if (transferRegionFields) transferRegionFields.style.display = '';
      if (transferVanFields) transferVanFields.style.display = 'none';
      if (transferLocationField) transferLocationField.style.display = '';
      if (transferToRegionSelect) {
        transferToRegionSelect.disabled = false;
        transferToRegionSelect.required = true;
        const selectedRegion = transferToRegionSelect.selectedOptions[0];
        transferToRegionLabel.textContent = selectedRegion ? selectedRegion.text : 'Select region';
      }
      if (transferVanStoreSelect) {
        transferVanStoreSelect.disabled = true;
        transferVanStoreSelect.required = false;
      }
      if (transferNewLocationInput) {
        transferNewLocationInput.required = false;
        transferNewLocationInput.placeholder = 'Optional: specify location at destination region';
      }
      if (transferLocationHelp) transferLocationHelp.textContent = 'Optional: specify location at destination region';
    } else if (destination === 'location') {
      if (transferRegionFields) transferRegionFields.style.display = 'none';
      if (transferVanFields) transferVanFields.style.display = 'none';
      if (transferLocationField) transferLocationField.style.display = '';
      if (transferToRegionSelect) {
        transferToRegionSelect.disabled = true;
        transferToRegionSelect.required = false;
      }
      if (transferVanStoreSelect) {
        transferVanStoreSelect.disabled = true;
        transferVanStoreSelect.required = false;
      }
      transferToRegionLabel.textContent = 'New location';
      if (transferNewLocationInput) {
        transferNewLocationInput.required = true;
        transferNewLocationInput.placeholder = 'Enter new location within this region';
      }
      if (transferLocationHelp) transferLocationHelp.textContent = 'Required: enter the new location for this stock within the same region';
    } else {
      if (transferRegionFields) transferRegionFields.style.display = 'none';
      if (transferLocationField) transferLocationField.style.display = 'none';
      if (transferToRegionSelect) {
        transferToRegionSelect.disabled = true;
        transferToRegionSelect.required = false;
      }
      if (transferVanFields) transferVanFields.style.display = '';
      if (transferVanStoreSelect) {
        transferVanStoreSelect.disabled = false;
        transferVanStoreSelect.required = true;
        const selectedStore = transferVanStoreSelect.selectedOptions[0];
        transferToRegionLabel.textContent = selectedStore ? selectedStore.text : 'Van stock';
      } else {
        transferToRegionLabel.textContent = 'Van stock';
      }
      if (transferNewLocationInput) transferNewLocationInput.required = false;
    }
  }

  if (transferDestinationType) transferDestinationType.addEventListener('change', updateTransferDestinationUI);
  if (transferVanStoreSelect) {
    transferVanStoreSelect.addEventListener('change', () => {
      if (transferDestinationType && transferDestinationType.value === 'van') {
        const selectedStore = transferVanStoreSelect.selectedOptions[0];
        transferToRegionLabel.textContent = selectedStore ? selectedStore.text : 'Van stock';
      }
    });
  }
  if (transferToRegionSelect) {
    transferToRegionSelect.addEventListener('change', () => {
      if (transferDestinationType && transferDestinationType.value === 'region') {
        const selectedRegion = transferToRegionSelect.selectedOptions[0];
        transferToRegionLabel.textContent = selectedRegion ? selectedRegion.text : 'Select region';
      }
    });
  }
  updateTransferDestinationUI();

  // Transfer buttons
  document.querySelectorAll('.transfer-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const prodId = this.dataset.prodId;
      const prodName = this.dataset.prodName;
      const currentRegion = this.dataset.currentRegion;
      const currentRegionId = this.dataset.currentRegionId;
      const availableQty = this.dataset.availableQty;
      const currentLocation = this.dataset.currentLocation || '';

      document.getElementById('transferProdId').value = prodId;
      document.getElementById('transferProductName').textContent = prodName;
      document.getElementById('transferFromRegion').textContent = currentRegion;
      document.getElementById('transferFromRegionId').value = currentRegionId;
      document.getElementById('transferAvailableQty').textContent = availableQty;
      document.getElementById('transferQty').max = availableQty;
      document.getElementById('transferQty').value = '1';
      document.getElementById('transferSourceLocation').value = currentLocation;
      if (transferSourceLocationDisplay) transferSourceLocationDisplay.textContent = currentLocation || 'No location';
      if (transferNewLocationInput) transferNewLocationInput.value = '';
      if (transferDestinationType) transferDestinationType.value = 'region';
      if (transferVanStoreSelect) transferVanStoreSelect.selectedIndex = 0;
      if (transferToRegionSelect) {
        transferToRegionSelect.selectedIndex = 0;
        const selectedRegion = transferToRegionSelect.selectedOptions[0];
        transferToRegionLabel.textContent = selectedRegion ? selectedRegion.text : 'Select region';
      }
      updateTransferDestinationUI();

      if (window.ModalManager) {
        ModalManager.show(transferModal);
      } else {
        transferModal.style.display = 'block';
      }
    });
  });

  // Edit location buttons
  document.querySelectorAll('.edit-location-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const prodId = this.dataset.prodId;
      const prodName = this.dataset.prodName;
      const currentLocation = this.dataset.currentLocation || '';

      document.getElementById('locationProdId').value = prodId;
      document.getElementById('locationProductName').textContent = prodName;
      document.getElementById('currentLocationDisplay').textContent = currentLocation || 'Not specified';
      document.getElementById('currentLocationHidden').value = currentLocation;
      document.getElementById('newLocation').value = '';

      if (window.ModalManager) {
        ModalManager.show(locationModal);
      } else {
        locationModal.style.display = 'block';
      }
    });
  });

  // Close buttons for all modals
  document.querySelectorAll('.close-button, .modal-cancel-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      const modal = this.closest('.modal');
      if (window.ModalManager) {
        ModalManager.hide(modal);
      } else {
        modal.style.display = 'none';
      }
    });
  });

  // Filters toggle
  const filtersToggle = document.getElementById('filtersToggle');
  const filtersBackdrop = document.getElementById('filtersBackdrop');
  const regionStorageLayout = document.getElementById('regionStorageLayout');
  const filtersStorageKey = 'region_storage_filters_open';

  function setFiltersOpen(isOpen) {
    if (!regionStorageLayout) return;
    regionStorageLayout.dataset.filtersOpen = isOpen ? 'true' : 'false';
    if (filtersToggle) filtersToggle.textContent = isOpen ? 'Hide filters' : 'Filters';
    try { localStorage.setItem(filtersStorageKey, isOpen ? '1' : '0'); } catch (e) {}
  }

  if (filtersToggle && regionStorageLayout) {
    let initialOpen = true;
    try {
      const stored = localStorage.getItem(filtersStorageKey);
      if (stored === '0') initialOpen = false;
    } catch (e) {}
    setFiltersOpen(initialOpen);
    filtersToggle.addEventListener('click', () => {
      setFiltersOpen(regionStorageLayout.dataset.filtersOpen !== 'false' ? false : true);
    });
  }

  if (filtersBackdrop && regionStorageLayout) {
    filtersBackdrop.addEventListener('click', () => setFiltersOpen(false));
  }

  // Search and filter
  const searchInput = document.getElementById('searchInput');
  const locationFilter = document.getElementById('locationFilter');
  const clearFiltersBtn = document.getElementById('clearFiltersBtn');
  const filterResults = document.getElementById('filterResults');

  function filterCards() {
    if (!searchInput || !locationFilter) return;
    const searchTerm = searchInput.value.toLowerCase().trim();
    const locationTerm = locationFilter.value.toLowerCase().trim();
    const cards = document.querySelectorAll('.stock-card');
    let visibleCount = 0;
    cards.forEach(card => {
      const matchesSearch = !searchTerm ||
        (card.dataset.productName || '').includes(searchTerm) ||
        (card.dataset.productSku || '').includes(searchTerm);
      const matchesLocation = !locationTerm ||
        (card.dataset.location || '').includes(locationTerm);
      const show = matchesSearch && matchesLocation;
      card.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });
    if (filterResults) {
      filterResults.textContent = (searchTerm || locationTerm)
        ? `Showing ${visibleCount} of ${cards.length} items`
        : '';
    }
  }

  if (searchInput) searchInput.addEventListener('input', filterCards);
  if (locationFilter) locationFilter.addEventListener('input', filterCards);
  if (clearFiltersBtn) {
    clearFiltersBtn.addEventListener('click', function() {
      if (searchInput) searchInput.value = '';
      if (locationFilter) locationFilter.value = '';
      filterCards();
      if (searchInput) searchInput.focus();
    });
  }
  filterCards();

  // Add Stock modal enhancements
  const addStockForm = document.getElementById('addStockForm');
  const addProdSelect = document.getElementById('addProdId');
  const addProdDisplay = document.getElementById('addProdDisplay');
  const addQtyInput = document.getElementById('addChangeQty');
  const addLocationInput = document.getElementById('addLocation');
  const addSubmitBtn = document.getElementById('addStockSubmitBtn');

  if (addProdDisplay) {
    addProdDisplay.classList.add('add-stock-product-display');
    addProdDisplay.placeholder = 'Choose a product';
  }
  if (addProdSelect) {
    addProdSelect.hidden = true;
    addProdSelect.addEventListener('change', function() {
      if (addQtyInput) addQtyInput.focus();
      const opt = addProdSelect.options[addProdSelect.selectedIndex];
      if (opt && addProdDisplay) {
        if (opt.value) {
          const name = opt.getAttribute('data-name') || opt.textContent;
          const sku = opt.getAttribute('data-sku') || '';
          addProdDisplay.value = sku ? `${name} (${sku})` : name;
        } else {
          addProdDisplay.value = '';
        }
      }
    });
  }
  if (addQtyInput && addStockForm) {
    addQtyInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (addStockForm.checkValidity()) {
          addStockForm.requestSubmit ? addStockForm.requestSubmit() : addStockForm.submit();
        }
      }
    });
  }

  // Product picker modal
  const productPickerModal = document.getElementById('productPickerModal');
  const openProductPickerBtn = document.getElementById('openProductPickerBtn');
  const pickerSearch = document.getElementById('pickerSearch');
  const pickerList = document.getElementById('pickerList');

  const regionProducts = [];
  if (addProdSelect) {
    for (let i = 0; i < addProdSelect.options.length; i++) {
      const opt = addProdSelect.options[i];
      if (!opt.value) continue;
      regionProducts.push({
        id: opt.value,
        name: opt.getAttribute('data-name') || opt.textContent,
        sku: opt.getAttribute('data-sku') || '',
        barcode: opt.getAttribute('data-barcode') || ''
      });
    }
  }

  function renderPicker(term = '') {
    if (!pickerList) return;
    pickerList.innerHTML = '';
    const t = term.toLowerCase().trim();
    const matches = regionProducts.filter(p => {
      if (!t) return true;
      return (p.name && p.name.toLowerCase().includes(t)) ||
             (p.sku && p.sku.toLowerCase().includes(t)) ||
             (p.barcode && p.barcode.toLowerCase().includes(t));
    });
    if (matches.length === 0) {
      const empty = document.createElement('div');
      empty.style.padding = '12px';
      empty.textContent = 'No results';
      pickerList.appendChild(empty);
      return;
    }
    matches.forEach(p => {
      const item = document.createElement('div');
      item.className = 'picker-item';
      item.style.cssText = 'display:block;width:100%;padding:12px 14px;border-bottom:1px solid var(--border);background:var(--surface);cursor:pointer;';
      item.addEventListener('mouseenter', () => { item.style.background = 'var(--surface-2)'; });
      item.addEventListener('mouseleave', () => { item.style.background = 'var(--surface)'; });

      const nameEl = document.createElement('div');
      nameEl.textContent = p.name;
      nameEl.style.cssText = 'font-weight:600;white-space:normal;overflow-wrap:anywhere;word-break:break-word;color:var(--text-primary)';
      const skuEl = document.createElement('div');
      skuEl.textContent = p.sku || '';
      skuEl.style.cssText = 'color:var(--text-muted);font-size:12px;margin-top:4px;';
      item.appendChild(nameEl);
      item.appendChild(skuEl);

      let touchMoved = false, touchStartX = 0, touchStartY = 0;
      item.addEventListener('touchstart', (e) => {
        touchMoved = false;
        if (e.touches && e.touches[0]) {
          touchStartX = e.touches[0].clientX;
          touchStartY = e.touches[0].clientY;
        }
      }, { passive: true });
      item.addEventListener('touchmove', (e) => {
        if (e.touches && e.touches[0]) {
          const dx = Math.abs(e.touches[0].clientX - touchStartX);
          const dy = Math.abs(e.touches[0].clientY - touchStartY);
          if (dx > 8 || dy > 8) touchMoved = true;
        }
      }, { passive: true });
      const selectAndClose = () => {
        for (let i = 0; i < addProdSelect.options.length; i++) {
          if (addProdSelect.options[i].value === String(p.id)) {
            addProdSelect.selectedIndex = i;
            break;
          }
        }
        if (addProdDisplay) addProdDisplay.value = p.sku ? `${p.name} (${p.sku})` : p.name;
        if (window.ModalManager) ModalManager.hide(productPickerModal);
        else productPickerModal.style.display = 'none';
        if (addQtyInput) addQtyInput.focus();
      };
      item.addEventListener('touchend', (e) => { if (!touchMoved) { e.preventDefault(); selectAndClose(); } });
      item.addEventListener('click', (e) => { if (!touchMoved) selectAndClose(); });
      pickerList.appendChild(item);
    });
  }

  if (openProductPickerBtn) {
    openProductPickerBtn.addEventListener('click', function() {
      renderPicker('');
      if (pickerSearch) pickerSearch.value = '';
      if (window.ModalManager) ModalManager.show(productPickerModal);
      else productPickerModal.style.display = 'block';
      setTimeout(() => pickerSearch && pickerSearch.focus(), 0);
    });
  }
  if (pickerSearch) {
    pickerSearch.addEventListener('input', function() { renderPicker(this.value); });
    pickerSearch.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        const firstBtn = pickerList && pickerList.querySelector('.picker-item');
        if (firstBtn) firstBtn.click();
      }
    });
  }
  document.querySelectorAll('#productPickerModal .close-button, #pickerCloseBtn').forEach(btn => {
    btn.addEventListener('click', function() {
      if (window.ModalManager) ModalManager.hide(productPickerModal);
      else productPickerModal.style.display = 'none';
    });
  });

  // Scanner
  const regionScannerModal = document.getElementById('regionScannerModal');
  const openRegionScannerBtns = Array.from(document.querySelectorAll('#openRegionScannerBtn, #openRegionScannerBtnPrimary'));
  const regionCloseScannerButton = document.getElementById('regionCloseScannerButton');
  const regionScannerVideo = document.getElementById('regionScannerVideo');
  const regionScannerResult = document.getElementById('regionScannerResult');
  const regionStopScannerButton = document.getElementById('regionStopScannerButton');
  const regionZoomInBtn = document.getElementById('regionZoomInBtn');
  const regionZoomOutBtn = document.getElementById('regionZoomOutBtn');
  const regionZoomLevelDisplay = document.getElementById('regionZoomLevel');

  let regionCodeReader, regionCurrentStream, regionCurrentZoom = 1;

  function regionApplyZoom() {
    if (!regionScannerVideo) return;
    regionScannerVideo.style.transform = `scale(${regionCurrentZoom})`;
    regionScannerVideo.style.transformOrigin = 'center center';
    if (regionZoomLevelDisplay) regionZoomLevelDisplay.textContent = regionCurrentZoom.toFixed(1) + 'x';
    if (regionZoomInBtn) regionZoomInBtn.disabled = regionCurrentZoom >= 4;
    if (regionZoomOutBtn) regionZoomOutBtn.disabled = regionCurrentZoom <= 1;
  }

  if (regionZoomInBtn) regionZoomInBtn.addEventListener('click', (e) => { e.preventDefault(); if (regionCurrentZoom < 4) { regionCurrentZoom += 0.5; regionApplyZoom(); } });
  if (regionZoomOutBtn) regionZoomOutBtn.addEventListener('click', (e) => { e.preventDefault(); if (regionCurrentZoom > 1) { regionCurrentZoom -= 0.5; regionApplyZoom(); } });

  function regionStopScanner() {
    if (regionCodeReader) { regionCodeReader.reset(); regionCodeReader = null; }
    if (regionCurrentStream) { regionCurrentStream.getTracks().forEach(t => t.stop()); regionCurrentStream = null; }
    if (regionScannerVideo) regionScannerVideo.srcObject = null;
    if (regionScannerResult) regionScannerResult.textContent = '';
    if (regionStopScannerButton) regionStopScannerButton.style.display = 'none';
    regionCurrentZoom = 1;
    regionApplyZoom();
  }

  async function regionStartScanner() {
    try {
      if (regionScannerResult) regionScannerResult.textContent = 'Loading scanner...';
      if (regionStopScannerButton) regionStopScannerButton.style.display = 'none';
      if (typeof window.loadScannerLibrary !== 'function') {
        if (regionScannerResult) regionScannerResult.textContent = 'Scanner library loader not available.';
        return;
      }
      let ZXingLib;
      try {
        if (regionScannerResult) regionScannerResult.textContent = 'Loading scanner library...';
        ZXingLib = await window.loadScannerLibrary();
      } catch (loadError) {
        if (regionScannerResult) regionScannerResult.textContent = 'Failed to load scanner library. Please try again.';
        return;
      }
      if (regionScannerResult) regionScannerResult.textContent = 'Initializing scanner...';
      if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
        if (regionScannerResult) regionScannerResult.textContent = 'Camera access requires HTTPS.';
        return;
      }
      const hints = new Map();
      hints.set(ZXingLib.DecodeHintType.TRY_HARDER, true);
      hints.set(ZXingLib.DecodeHintType.POSSIBLE_FORMATS, [ZXingLib.BarcodeFormat.QR_CODE]);
      try {
        regionCodeReader = new ZXingLib.BrowserMultiFormatReader(hints);
      } catch (e) {
        regionCodeReader = new ZXingLib.BrowserMultiFormatReader(new Map().set(ZXingLib.DecodeHintType.TRY_HARDER, true));
      }
      regionCodeReader.decodeFromVideoDevice(undefined, regionScannerVideo, (result, err) => {
        if (result) {
          if (regionScannerResult) regionScannerResult.textContent = 'Scanned: ' + result.text;
          regionStopScanner();
          if (window.ModalManager) ModalManager.hide(regionScannerModal);
          else regionScannerModal.style.display = 'none';
          const val = (result.text || '').trim().toLowerCase();
          const found = regionProducts.find(p =>
            (p.barcode && p.barcode.toLowerCase() === val) || (p.sku && p.sku.toLowerCase() === val)
          );
          if (found) {
            for (let i = 0; i < addProdSelect.options.length; i++) {
              if (addProdSelect.options[i].value === String(found.id)) { addProdSelect.selectedIndex = i; break; }
            }
            if (addProdDisplay) addProdDisplay.value = found.sku ? `${found.name} (${found.sku})` : found.name;
            if (window.ModalManager) ModalManager.hide(productPickerModal);
            else productPickerModal.style.display = 'none';
            if (addQtyInput) addQtyInput.focus();
          } else if (window.QuackNotify) {
            QuackNotify.warning('Scanned code not found in product list');
          }
        }
      });
      if (regionStopScannerButton) regionStopScannerButton.style.display = 'inline-block';
    } catch (e) {
      if (regionScannerResult) regionScannerResult.textContent = 'Camera error occurred';
      regionStopScanner();
    }
  }

  openRegionScannerBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      if (window.ModalManager) ModalManager.show(regionScannerModal);
      else regionScannerModal.style.display = 'block';
      setTimeout(() => regionStartScanner(), 300);
    });
  });

  const closeScanner = () => {
    regionStopScanner();
    if (window.ModalManager) ModalManager.hide(regionScannerModal);
    else regionScannerModal.style.display = 'none';
  };
  if (regionCloseScannerButton) regionCloseScannerButton.addEventListener('click', closeScanner);
  const regionScannerCloseFooter = document.getElementById('regionScannerCloseFooter');
  if (regionScannerCloseFooter) regionScannerCloseFooter.addEventListener('click', closeScanner);

  // Add Stock form submit guard
  if (addStockForm && addSubmitBtn) {
    addStockForm.addEventListener('submit', function() {
      const regionIdInput = addStockForm.querySelector('input[name="region_id"]');
      const addLocationInput = document.getElementById('addLocation');
      if (regionIdInput && addLocationInput && addLocationInput.value) {
        const key = `region_location_${regionIdInput.value}`;
        try { localStorage.setItem(key, addLocationInput.value); } catch (e) {}
      }
      addSubmitBtn.disabled = true;
      addSubmitBtn.textContent = 'Adding...';
    });
  }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
