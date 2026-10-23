<?php
// Anti-flash: read theme before header outputs anything
$__kit_theme = $_COOKIE['kit_theme'] ?? 'light';
$title        = 'Products';
$main_class   = 'main-content';
$extra_styles = ['/assets/opdash.css'];
$max_note_length = $max_note_length ?? 1000;
require __DIR__ . '/header.php';
?>
<script>
(function(){
  var t=localStorage.getItem('kit_theme')||'light';
  document.documentElement.setAttribute('data-kit-theme',t);
})();
</script>

<style>
/* ── inline only: image/video overflow containment (browser bug workaround) ── */
img, video, canvas {
  overflow: hidden !important;
  clip-path: inset(0) !important;
}
video, canvas {
  contain: layout style paint size !important;
}
</style>

<?php
$can_create_products = has_permission('products.create');
$can_import_products = has_permission('products.bulk_import');
$can_delete_products = has_permission('products.delete');
$can_edit_products = has_permission('products.edit');
?>

<?php $__flashes = flashes(); ?>

<!-- Hidden CSRF token for JavaScript -->
<?=csrf_field()?>

<?php foreach ($__flashes as [$t,$m]) : ?>
  <div class="flash <?=h($t)?>"><?=h($m)?></div>
<?php endforeach; ?>

<!-- Masthead -->
<div class="page-masthead products-masthead">
  <div class="products-masthead-body">
    <div class="products-masthead-left">
      <h1 class="products-masthead-title">Products</h1>
      <p class="products-masthead-sub">Browse, search, and manage your product catalogue</p>
    </div>
    <div class="masthead-tabs products-masthead-actions">
      <a href="/cart" class="masthead-tab">
        🛒 Cart <span id="cartCount">0</span>
      </a>
      <button id="openScannerModalBtn" type="button" class="masthead-tab">⊡ Scan</button>
      <?php if ($can_import_products) : ?>
      <button id="openImportProductModal" type="button" class="masthead-tab">↑ Import</button>
      <?php endif; ?>
      <?php if ($can_create_products) : ?>
      <button id="openAddProductModal" type="button" class="masthead-tab">+ Add Product</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Mobile Filter Toggle (mobile only) -->
<button id="mobileFilterToggle" class="mobile-filter-toggle" aria-label="Toggle filters">
  <span>⚙</span>
  <span>Filters</span>
  <span id="activeFilterCount" class="filter-count" style="display: none;"></span>
</button>

<!-- Page body: sidebar + main -->
<div class="products-page-body">
  <!-- Sidebar Filters -->
  <aside id="filterSidebar" class="filter-sidebar" aria-label="Product filters">
    <!-- Toggle Button to Collapse/Expand Sidebar -->
    <button id="filterSidebarToggle" class="filter-sidebar-toggle" aria-label="Toggle filters" title="Hide/Show Filters">
      <span class="filter-sidebar-toggle-icon">«</span>
    </button>

    <div class="sidebar-header">
      <h2>Filters</h2>
      <button id="closeSidebarBtn" class="close-sidebar" aria-label="Close filters">&times;</button>
    </div>

    <div class="sidebar-content">
      <!-- Search Bar -->
      <div class="filter-section">
        <label for="searchInput" class="filter-label">Search</label>
        <div class="search-input-wrapper">
          <input type="text" id="searchInput" class="sidebar-search" placeholder="Search products..." aria-label="Search products">
          <span class="search-icon">🔍</span>
        </div>
      </div>

      <!-- Favorites Filter -->
      <div class="filter-section">
        <label class="filter-checkbox">
          <input type="checkbox" id="showFavoritesCheckbox">
          <span class="checkbox-label">
            <span class="star-icon">★</span>
            Show Favorites Only
          </span>
        </label>
      </div>

      <!-- Category Filter - Collapsible -->
      <div class="filter-section-collapsible" id="categorySection">
        <div class="filter-section-header" onclick="toggleFilterSection('categorySection')">
          <label class="filter-label" style="margin-bottom: 0;">Category</label>
          <span class="filter-collapse-icon">▼</span>
        </div>
        <div class="filter-options" id="categoryFilterOptions">
          <label class="filter-radio">
            <input type="radio" name="category" value="" checked>
            <span>All Categories</span>
          </label>
          <!-- Categories loaded dynamically -->
        </div>
      </div>

      <!-- Manufacturer Filter - Collapsible -->
      <div class="filter-section-collapsible" id="manufacturerSection">
        <div class="filter-section-header" onclick="toggleFilterSection('manufacturerSection')">
          <label class="filter-label" style="margin-bottom: 0;">Manufacturer</label>
          <span class="filter-collapse-icon">▼</span>
        </div>
        <div class="filter-options scrollable" id="manufacturerFilterOptions">
          <label class="filter-checkbox">
            <input type="checkbox" value="__all__" id="allManufacturers" checked>
            <span>All Manufacturers</span>
          </label>
          <!-- Manufacturers loaded dynamically -->
        </div>
      </div>

      <!-- Status Filter - Collapsible -->
      <div class="filter-section-collapsible" id="statusSection">
        <div class="filter-section-header" onclick="toggleFilterSection('statusSection')">
          <label class="filter-label" style="margin-bottom: 0;">Status</label>
          <span class="filter-collapse-icon">▼</span>
        </div>
        <div class="filter-options" id="statusFilterOptions">
          <label class="filter-radio">
            <input type="radio" name="status" value="" checked>
            <span>All Status</span>
          </label>
          <label class="filter-radio">
            <input type="radio" name="status" value="active">
            <span>Active</span>
          </label>
          <label class="filter-radio">
            <input type="radio" name="status" value="inactive">
            <span>Inactive</span>
          </label>
          <label class="filter-radio">
            <input type="radio" name="status" value="discontinued">
            <span>Discontinued</span>
          </label>
        </div>
      </div>

      <!-- Clear Filters Button -->
      <div class="filter-section">
        <button id="clearFiltersBtn" class="btn-block">Clear All Filters</button>
      </div>
    </div>
  </aside>

  <!-- Main Content Area -->
  <main class="products-main-content">
    <div id="resultsCount" class="results-count" aria-live="polite"></div>

<?php
// Include Scanner Modal Component (with custom IDs for products page)
$modal_id = 'scannerModal';
$video_id = 'scannerVideo';
$result_container_id = 'scannerResult';
$zoom_level_id = 'zoomLevel';
$zoom_in_btn_id = 'zoomInBtn';
$zoom_out_btn_id = 'zoomOutBtn';
$close_btn_id = 'closeScannerButton';
$stop_btn_id = 'stopScannerButton';
$close_footer_btn_id = 'scannerCloseFooter';
require __DIR__ . '/components/modals/scanner-modal.php';
?>

<div id="importProductModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Import Products</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/products" enctype="multipart/form-data">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="import">
        <div class="form-group">
          <label>Excel File<input type="file" name="products_file" accept=".xls,.xlsx" required></label>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('importProductModal'))">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="document.querySelector('#importProductModal form').submit()">Import</button>
    </div>
  </div>
</div>

<div id="addProductModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Add New Product</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/products" enctype="multipart/form-data">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="add">

        <div class="form-group">
          <label>Product ID<input type="text" name="product_id" required></label>
        </div>

        <div class="form-group">
          <label>Barcode<input type="text" name="barcode" placeholder="Optional (what the scanner reads)"></label>
        </div>

        <div class="form-group">
          <label>Name<input type="text" name="name" required></label>
        </div>

        <div class="form-group">
          <label>Asset Type<input type="text" name="asset_type" placeholder="Optional"></label>
        </div>

        <div class="form-group">
          <label>Manufacturer<input type="text" name="manufacturer" placeholder="Optional"></label>
        </div>

        <div class="form-group">
          <label>Status
            <select name="status">
              <option value="active" selected>Active</option>
              <option value="inactive">Inactive</option>
              <option value="discontinued">Discontinued</option>
            </select>
          </label>
        </div>

        <div class="form-group">
          <label>Color<input type="text" name="color" placeholder="Optional"></label>
        </div>

        <div class="form-group">
          <label>Order Number<input type="text" name="order_number" placeholder="Optional"></label>
        </div>

        <div class="form-group">
          <label>Purchase URL<input type="url" name="purchase_url" placeholder="https://example.com/buy"></label>
        </div>

        <div class="form-group">
          <label class="toggle-label">
            <input type="checkbox" name="is_tracked_asset" value="1" id="addIsTrackedAsset">
            <span class="toggle-text">
              <strong>Track as individual asset</strong>
              <small>Requires serial number on receipt — syncs with Snipe-IT for chain of custody</small>
            </span>
          </label>
        </div>

        <div class="form-group">
          <label>Image Upload</label>
          <input type="file" name="image" id="imageUpload" accept="image/*" capture="environment">
        </div>


      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="ModalManager.hide(document.getElementById('addProductModal'))">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="document.querySelector('#addProductModal form').submit()">Add Product</button>
    </div>
  </div>
</div>

<div class="products-listing">
  <div id="productsSkeleton" class="products-skeleton" aria-hidden="true">
    <?php for ($i = 0; $i < 6; $i++) : ?>
      <div class="skeleton-card">
        <div class="skeleton-thumbnail"></div>
        <div class="skeleton-lines">
          <span class="skeleton-line skeleton-line--short"></span>
          <span class="skeleton-line skeleton-line--long"></span>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <div class="cards" id="productsGrid" aria-live="polite"></div>

  <div id="productsEmpty" class="products-empty" hidden>
    <h3>No products found</h3>
    <p>Try adjusting your search or clearing filters.</p>
    <button type="button" class="btn btn-secondary" onclick="clearAllFilters()">Clear All Filters</button>
  </div>

  <div id="productsLoader" class="products-loader" role="status" aria-live="assertive" hidden>
    <span class="loader-spinner" aria-hidden="true"></span>
    <span>Loading products…</span>
  </div>

  <div id="productsError" class="products-error" role="alert" hidden></div>

  <div id="productsSentinel" class="products-sentinel" aria-hidden="true"></div>
</div><!-- .products-listing -->

  </main><!-- .products-main-content -->
</div><!-- .products-page-body -->

<div id="editProductModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Product</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>

    <div class="modal-body">
      <form method="post" action="/products" enctype="multipart/form-data" id="editProductForm">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editProductId">

        <!-- Product Image Section -->
        <div class="form-section">
          <div class="current-image-container">
            <img id="editCurrentImage" src="" alt="Current product image" class="current-image">
            <div class="image-placeholder" id="editImagePlaceholder">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21,15 16,10 5,21"/>
              </svg>
              <span>No Image</span>
            </div>
          </div>

          <div class="image-upload-section">
            <input type="file" name="image" id="editImageInput" accept="image/*" style="display: none;">
            <label for="editImageInput" class="upload-button">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Change Image
            </label>
          </div>
        </div>

        <!-- Product Information -->
        <div class="form-section">
          <div class="form-grid">
            <div class="form-group">
              <label for="editProduct_id">Product ID *</label>
              <input type="text" name="product_id" id="editProduct_id" required>
            </div>

            <div class="form-group">
              <label for="editBarcode">Barcode</label>
              <input type="text" name="barcode" id="editBarcode" placeholder="Optional (what the scanner reads)">
            </div>

            <div class="form-group">
              <label for="editName">Product Name *</label>
              <input type="text" name="name" id="editName" required>
            </div>

            <div class="form-group">
              <label for="editAssetType">Asset Type</label>
              <input type="text" name="asset_type" id="editAssetType" placeholder="e.g., Camera, Cable, Tool">
            </div>

            <div class="form-group">
              <label for="editManufacturer">Manufacturer</label>
              <input type="text" name="manufacturer" id="editManufacturer" placeholder="e.g., SpotAI, Trendnet">
            </div>

            <div class="form-group">
              <label for="editStatus">Status</label>
              <select name="status" id="editStatus">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="discontinued">Discontinued</option>
              </select>
            </div>

            <div class="form-group">
              <label for="editColor">Color</label>
              <input type="text" name="color" id="editColor" placeholder="e.g., Black, White, Silver">
            </div>

            <div class="form-group">
              <label for="editOrderNumber">Order Number</label>
              <input type="text" name="order_number" id="editOrderNumber" placeholder="Internal reference">
            </div>

            <div class="form-group">
              <label for="editPurchaseUrl">Purchase URL</label>
              <input type="url" name="purchase_url" id="editPurchaseUrl" placeholder="https://example.com/buy">
            </div>

            <div class="form-group">
              <label for="editAcumaticaInventoryId">Acumatica Inventory ID</label>
              <input type="text" name="acumatica_inventory_id" id="editAcumaticaInventoryId" placeholder="e.g. AALEGO500" maxlength="30">
              <small>Maps this product to a Stock Item in Acumatica for live quantity and PO lookups.</small>
            </div>

            <div class="form-group">
              <label for="editAcumaticaDefaultVendorId">Acumatica Default Vendor ID</label>
              <input type="text" name="acumatica_default_vendor_id" id="editAcumaticaDefaultVendorId" placeholder="e.g. VENDOR01" maxlength="15">
              <small>Pre-fills the vendor when creating a purchase order for this item.</small>
            </div>
          </div>

          <div class="form-group">
            <label class="toggle-label">
              <input type="checkbox" name="is_tracked_asset" value="1" id="editIsTrackedAsset">
              <span class="toggle-text">
                <strong>Track as individual asset</strong>
                <small>Requires serial number on receipt — syncs with Snipe-IT for chain of custody</small>
              </span>
            </label>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="form-actions">
          <button type="button" class="btn btn-secondary" id="editCancelBtn">Cancel</button>
          <button type="submit" class="btn btn-primary" id="editSaveBtn">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Product Detail Modal -->
<div id="productDetailModal" class="modal">
  <div class="modal-content product-detail-modal">
    <div class="modal-header product-detail-header">
      <div class="product-title-group">
        <h2 id="detailProductName" class="product-title"></h2>
      </div>
      <button class="close-button product-detail-close" id="productDetailCloseButton" type="button" aria-label="Close modal">&times;</button>
    </div>

    <div class="modal-body product-detail-body">
      <div class="product-detail-layout">
        <!-- Product Image Section -->
        <div class="product-image-section product-media-panel">
          <div class="product-image-container">
            <img id="detailProductImage" src="" alt="Product Image" class="product-image">
            <div class="image-placeholder product-image-placeholder" id="imagePlaceholder" style="display: none;">
              <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21,15 16,10 5,21"/>
              </svg>
              <span>No Image</span>
            </div>
          </div>
        </div>

        <!-- Product Information Section -->
        <div class="product-info-section product-info-panel">
          <div class="product-details-grid product-facts-grid">
            <div class="detail-item">
              <label class="detail-label">SKU</label>
              <span id="detailProductId" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <label class="detail-label">Asset Type</label>
              <span id="detailAssetType" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <label class="detail-label">Manufacturer</label>
              <span id="detailManufacturer" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <label class="detail-label">Status</label>
              <span id="detailStatus" class="detail-value status-badge"></span>
            </div>
            <div class="detail-item">
              <label class="detail-label">Color</label>
              <span id="detailColor" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <label class="detail-label">Order Number</label>
              <span id="detailOrderNumber" class="detail-value"></span>
            </div>
            <div class="detail-item">
              <label class="detail-label">Purchase URL</label>
              <span id="detailPurchaseUrl" class="detail-value"></span>
            </div>
          </div>

          <!-- Stock Information -->
          <div class="stock-info-card product-stock-summary">
            <h3 class="section-title">Stock Information</h3>
            <div class="stock-grid">
              <div class="stock-item">
                <label class="stock-label">Van Stock</label>
                <span id="detailVanStock" class="stock-value">-</span>
              </div>
              <div class="stock-item">
                <label class="stock-label">Region Stock</label>
                <span id="detailRegionStock" class="stock-value">-</span>
              </div>
            </div>
          </div>

          <!-- Add to Cart Section -->
          <div class="add-to-cart-card" id="productAddToCartSection" aria-hidden="false">
            <h3 class="section-title">Add to Order</h3>
            <div id="trackedOrderNotice" class="tracked-order-notice" hidden>
              This is a tracked asset. After it arrives, scan the serial number or asset tag so it can be matched or created in Snipe-IT.
            </div>
            <form id="addToCartForm" class="product-cart-form">
              <?=csrf_field()?>
              <input type="hidden" id="cartProductId" name="product_id">
              <div class="product-cart-fields">
                <div class="product-quantity-field">
                  <label for="cartQuantity" class="form-label">Quantity</label>
                  <div class="product-quantity-stepper" role="group" aria-label="Adjust quantity">
                    <button type="button" class="quantity-btn quantity-decrease product-quantity-btn" aria-label="Decrease quantity">
                      <span aria-hidden="true">-</span>
                    </button>
                    <input
                      type="number"
                      id="cartQuantity"
                      name="quantity"
                      value="1"
                      min="1"
                      max="999"
                      class="quantity-input product-quantity-input"
                      required
                      inputmode="numeric">
                    <button type="button" class="quantity-btn quantity-increase product-quantity-btn" aria-label="Increase quantity">
                      <span aria-hidden="true">+</span>
                    </button>
                  </div>
                </div>
                <div class="product-notes-field">
                  <label for="cartNotes" class="form-label">Notes</label>
                  <input type="text" id="cartNotes" name="notes" maxlength="<?= (int)$max_note_length ?>" placeholder="Optional notes about this item" class="notes-input product-notes-input" autocomplete="off">
                  <small class="muted-text">Max <?= (int)$max_note_length ?> characters</small>
                </div>
              </div>
              <button type="submit" class="btn btn-primary add-to-cart-btn" id="productAddToCartButton">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="9" cy="21" r="1"></circle>
                  <circle cx="20" cy="21" r="1"></circle>
                  <path d="m1,1h4l2.68,13.39a2,2 0 0,0 2,1.61h9.72a2,2 0 0,0 2,-1.61L23,6H6"></path>
                </svg>
                Add to Cart
              </button>
            </form>
          </div>

          <!-- Product Meta Information -->
          <div class="product-meta">
            <div class="meta-item">
              <span class="meta-label">Created:</span>
              <span id="detailCreatedAt" class="meta-value"></span>
            </div>
            <div class="meta-item">
              <span class="meta-label">Modified:</span>
              <span id="detailModifiedAt" class="meta-value"></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Improved Modal Footer -->
    <div class="modal-footer product-modal-footer">
      <div class="footer-actions-left">
        <button type="button" id="addToFavoritesBtn" class="btn btn-outline favorite-btn">
          <span id="favoriteIcon" class="favorite-icon">☆</span>
          <span id="favoriteText" class="favorite-text">Add to Favorites</span>
        </button>
        <?php if ($can_delete_products) : ?>
        <form method="post" action="/products" class="delete-product-form">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteProductId">
          <button type="button" class="btn btn-danger delete-btn" id="deleteProductBtn" aria-label="Delete product">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3,6 5,6 21,6"></polyline>
              <path d="m19,6v14a2,2 0 0,1 -2,2H7a2,2 0 0,1 -2,-2V6m3,0V4a2,2 0 0,1 2,-2h4a2,2 0 0,1 2,2v2"></path>
              <line x1="10" y1="11" x2="10" y2="17"></line>
              <line x1="14" y1="11" x2="14" y2="17"></line>
            </svg>
            Delete
          </button>
        </form>
        <?php endif; ?>
      </div>

      <div class="footer-actions-right">
        <button type="button" class="btn btn-secondary product-detail-close" aria-label="Close modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
  const userCanEditProducts = <?= $can_edit_products ? 'true' : 'false' ?>;
  window.userCanEditProducts = userCanEditProducts;

  const ORDERABLE_STATUSES = ['active'];

  const productAddToCartSection = document.getElementById('productAddToCartSection');
  const productAddToCartButton = document.getElementById('productAddToCartButton');
  const trackedOrderNotice = document.getElementById('trackedOrderNotice');
  const addToCartForm = document.getElementById('addToCartForm');
  const cartQuantityInput = document.getElementById('cartQuantity');
  const cartNotesInput = document.getElementById('cartNotes');
  const cartProductIdInput = document.getElementById('cartProductId');

  function isProductOrderable(product) {
    if (!product) return false;
    const status = (product.status || 'active').toString().toLowerCase();
    return ORDERABLE_STATUSES.includes(status);
  }

  function updateAddToCartAvailability(isOrderable) {
    const shouldShow = Boolean(isOrderable);

    if (productAddToCartSection) {
      productAddToCartSection.style.display = shouldShow ? '' : 'none';
      productAddToCartSection.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    }

    if (productAddToCartButton) {
      productAddToCartButton.style.display = shouldShow ? '' : 'none';
      productAddToCartButton.disabled = !shouldShow;
      productAddToCartButton.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
      if (!shouldShow && document.activeElement === productAddToCartButton) {
        productAddToCartButton.blur();
      }
    }

    if (addToCartForm) {
      addToCartForm.dataset.orderable = shouldShow ? 'true' : 'false';
      addToCartForm.setAttribute('aria-disabled', shouldShow ? 'false' : 'true');
    }

    [cartQuantityInput, cartNotesInput, cartProductIdInput].forEach(element => {
      if (!element) return;
      element.disabled = !shouldShow;

      if (!shouldShow && document.activeElement === element) {
        element.blur();
      }
    });

    const quantityButtons = document.querySelectorAll('.quantity-decrease, .quantity-increase');
    quantityButtons.forEach(button => {
      if (button) {
        button.disabled = !shouldShow;
      }
    });
  }

  const addProductModal = document.getElementById('addProductModal');
  const openAddProductModalBtn = document.getElementById('openAddProductModal');
  const closeAddProductButton = addProductModal.querySelector('.close-button');

  const importProductModal = document.getElementById('importProductModal');
  const openImportProductModalBtn = document.getElementById('openImportProductModal');
  const closeImportProductButton = importProductModal ? importProductModal.querySelector('.close-button') : null;

  const imageUpload = document.getElementById('imageUpload');


  if (openAddProductModalBtn && addProductModal) {
    openAddProductModalBtn.addEventListener('click', () => {
      if (window.ModalManager) {
        ModalManager.show(addProductModal);
      } else {
        // Fallback modal display
        addProductModal.style.display = 'flex';
        addProductModal.style.alignItems = 'center';
        addProductModal.style.justifyContent = 'center';
        addProductModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.body.classList.add('modal-open');
      }
    });
  }

  if (closeAddProductButton && addProductModal) {
    closeAddProductButton.addEventListener('click', () => {
      if (window.ModalManager) {
        ModalManager.hide(addProductModal);
      } else {
        // Fallback modal hide
        addProductModal.style.display = 'none';
        addProductModal.classList.remove('show');
        document.body.style.overflow = '';
        document.body.classList.remove('modal-open');
        setTimeout(() => {
          if (!addProductModal.contains(document.activeElement)) {
            addProductModal.setAttribute('aria-hidden', 'true');
          }
        }, 100);
      }
    });
  }

  if (openImportProductModalBtn && importProductModal) {
    openImportProductModalBtn.addEventListener('click', () => {
      if (window.ModalManager) {
        ModalManager.show(importProductModal);
      } else {
        importProductModal.style.display = 'block';
      }
    });
  }

  if (closeImportProductButton && importProductModal) {
    closeImportProductButton.addEventListener('click', () => {
      if (window.ModalManager) {
        ModalManager.hide(importProductModal);
      } else {
        // Safe fallback that respects accessibility
        importProductModal.style.display = 'none';
        importProductModal.classList.remove('show');
        setTimeout(() => {
          if (!importProductModal.contains(document.activeElement)) {
            importProductModal.setAttribute('aria-hidden', 'true');
          }
        }, 100);
      }
    });
  }

  if (addProductModal || importProductModal) {
    window.addEventListener('click', (event) => {
      if (addProductModal && event.target === addProductModal) {
        if (window.ModalManager) {
          ModalManager.hide(addProductModal);
        } else {
          addProductModal.style.display = 'none';
          addProductModal.classList.remove('show');
          document.body.style.overflow = '';
          document.body.classList.remove('modal-open');
        }
      }
      if (importProductModal && event.target === importProductModal) {
        if (window.ModalManager) {
          ModalManager.hide(importProductModal);
        } else {
          importProductModal.style.display = 'none';
          importProductModal.classList.remove('show');
          document.body.style.overflow = '';
          document.body.classList.remove('modal-open');
        }
      }
    });
  }

  // No in-page camera capture; rely on file input which opens phone camera on mobile.

  /**
   * Secure image upload function using API endpoint
   * @param {number} productId - The product ID to upload image for
   * @param {File|string} imageData - File object or base64 data URL
   * @returns {Promise<string>} Promise resolving to the new image path
   */
  async function uploadProductImage(productId, imageData) {
    if (!productId || productId <= 0) {
      throw new Error('Invalid product ID');
    }

    const formData = new FormData();
    formData.append('action', 'upload_image');
    formData.append('product_id', productId);

    // Handle different types of image data
    if (imageData instanceof File) {
      // Direct file upload
      formData.append('image', imageData);
    } else if (typeof imageData === 'string' && imageData.startsWith('data:image/')) {
      // Base64 data URL from camera capture
      const response = await fetch(imageData);
      const blob = await response.blob();
      const file = new File([blob], 'captured_image.png', { type: 'image/png' });
      formData.append('image', file);
    } else {
      throw new Error('Invalid image data format');
    }

    try {
      const data = await makeAPICall('/api/products.php', {
        method: 'POST',
        body: formData
      }, {
        timeout: 30000, // Longer timeout for file uploads
        retries: 1,
        context: 'Uploading product image',
        showErrors: true
      });

      return data.data.image_path;
    } catch (error) {
      console.error('Image upload failed:', error);
      throw error;
    }
  }

  /**
   * Enhanced form submission with image upload API integration
   */
  function enhanceProductForms() {
    // Rely on standard form submission for add product; PHP handles file upload.

    // Enhance edit product form - simplified to let PHP handle image upload
    const editForm = document.querySelector('#editProductModal form');
    if (editForm) {
      editForm.addEventListener('submit', function(e) {
        const submitButton = editForm.querySelector('button[type="submit"], .btn-primary');

        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Updating Product...';

          // Re-enable after a delay in case of errors
          setTimeout(() => {
            submitButton.disabled = false;
            submitButton.textContent = 'Save Changes';
          }, 10000);
        }

        // Let the form submit normally - PHP will handle the image upload
        return true;
      });
    }
  }

  // Initialize enhanced forms when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceProductForms);
  } else {
    enhanceProductForms();
  }

  const productDetailModal = document.getElementById('productDetailModal');
  const productDetailCloseButtons = document.querySelectorAll('.product-detail-close');

  const editProductModal = document.getElementById('editProductModal');
  const closeEditProductButton = editProductModal.querySelector('.close-button');

  // Ensure the edit modal never starts visible (avoids background flashes)
  if (editProductModal) {
    editProductModal.classList.remove('show');
    editProductModal.style.display = 'none';
    editProductModal.setAttribute('aria-hidden', 'true');
  }

  // Simplified product card initialization
  function initializeProductCards(root = document) {
    // Guard against receiving DOM events or unsupported objects
    const scope = (root && typeof root.querySelectorAll === 'function') ? root : document;
    const selector = scope === document ? '.cards .card' : '.card';
    const allCards = scope.querySelectorAll(selector);

    allCards.forEach((card) => {
      const clickable = card.querySelector('.card-clickable');
      const editBtn = card.querySelector('.edit-product-btn');

      if (clickable && !clickable.hasAttribute('data-initialized')) {
        const productData = clickable.getAttribute('data-product');

        if (productData) {
          try {
            const product = JSON.parse(productData);

            // Mark as initialized
            clickable.setAttribute('data-initialized', 'true');
            clickable.style.cursor = 'pointer';

            // Single click handler for the clickable area
            clickable.onclick = function(e) {
              // Don't trigger if clicking on buttons or if not actually clicking on card
              if (e.target.closest('.card-actions') || !e.target.closest('.card-clickable')) {
                return;
              }

              // Ensure we have valid product data
              if (!product || !product.id) {
                // Invalid product data - skipping
                return;
              }

              e.preventDefault();
              if (window.openProductDetailModal) {
                window.openProductDetailModal(product);
              }
            };

            // Add better touch support for mobile (ignore scroll gestures)
            let touchHandled = false;
            let touchMoved = false;
            let touchStartX = 0;
            let touchStartY = 0;
            let touchStartTime = 0;

            clickable.addEventListener('touchstart', function(e) {
              touchHandled = false;
              touchMoved = false;
              const touch = e.touches && e.touches[0];
              if (touch) {
                touchStartX = touch.clientX;
                touchStartY = touch.clientY;
                touchStartTime = Date.now();
              }
            }, {passive: true});

            clickable.addEventListener('touchmove', function(e) {
              if (touchHandled || touchMoved) return;
              const touch = e.touches && e.touches[0];
              if (!touch) return;

              const deltaX = Math.abs(touch.clientX - touchStartX);
              const deltaY = Math.abs(touch.clientY - touchStartY);

              // Treat as scroll gesture if the finger moved more than a few pixels
              if (deltaX > 10 || deltaY > 10) {
                touchMoved = true;
              }
            }, {passive: true});

            clickable.addEventListener('touchend', function(e) {
              if (touchHandled || touchMoved) {
                touchHandled = true;
                return;
              }

              // Ignore long presses (likely context menus) to avoid accidental opens
              const tapDuration = Date.now() - touchStartTime;
              if (tapDuration > 600) {
                touchHandled = true;
                return;
              }

              if (!e.target.closest('.card-actions') && e.target.closest('.card-clickable')) {
                if (!product || !product.id) {
                  return;
                }

                touchHandled = true;
                e.preventDefault();
                if (window.openProductDetailModal) {
                  window.openProductDetailModal(product);
                }
              }
            }, {passive: false});

            // Keyboard accessibility
            clickable.onkeydown = function(e) {
              if (e.key === 'Enter' || e.key === ' ') {
                if (!e.target.closest('.card-actions')) {
                  e.preventDefault();
                  if (window.openProductDetailModal) {
                    window.openProductDetailModal(product);
                  }
                }
              }
            };

            // Visual feedback
            clickable.onmouseenter = function() {
              this.style.backgroundColor = 'rgba(0, 106, 78, 0.05)';
            };

            clickable.onmouseleave = function() {
              this.style.backgroundColor = '';
            };

          } catch (err) {
            if (isDev) console.error('Error setting up card:', err);
          }
        }
      }

      // Handle edit button
      if (editBtn && !editBtn.hasAttribute('data-initialized')) {
        const btnProductData = editBtn.getAttribute('data-product');
        if (btnProductData) {
          try {
            const btnProduct = JSON.parse(btnProductData);
            editBtn.setAttribute('data-initialized', 'true');
            editBtn.onclick = function(e) {
              e.stopPropagation();
              window.openEditProductModal(e, btnProduct);
            };
          } catch (err) {
            if (isDev) console.error('Error setting up edit button:', err);
          }
        }
      }

      // Handle add to cart button
      const addToCartBtn = card.querySelector('.add-to-cart-btn');
      if (addToCartBtn && !addToCartBtn.hasAttribute('data-initialized')) {
        addToCartBtn.setAttribute('data-initialized', 'true');
        addToCartBtn.onclick = async function(e) {
          e.stopPropagation();

          const productId = this.getAttribute('data-product-id');
          const productName = this.getAttribute('data-product-name');
          const originalText = this.textContent;

          if (!productId || productId <= 0) {
            QuackNotify.error('Invalid product ID');
            return;
          }

          try {
            this.disabled = true;
            this.textContent = 'Adding...';

            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('product_id', productId);
            formData.append('quantity', '1');
            formData.append('csrf', getCsrfToken());

            const data = await makeAPICall('/api/cart.php', {
              method: 'POST',
              body: formData
            }, {
              timeout: 15000,
              retries: 1,
              context: 'Adding item to cart',
              showErrors: true
            });

            QuackNotify.success(`${productName} added to cart!`);

            // Update cart count
            if (typeof updateHeaderCartCount === 'function') {
              updateHeaderCartCount();
            }

          } catch (error) {
            console.error('Add to cart failed:', error);
          } finally {
            this.disabled = false;
            this.textContent = originalText;
          }
        };
      }
    });

    // Handle delete confirmation for all delete forms
    document.querySelectorAll('.delete-product-form').forEach(form => {
      if (!form.hasAttribute('data-initialized')) {
        form.setAttribute('data-initialized', 'true');
        form.addEventListener('submit', function(e) {
          e.preventDefault();

          QuackNotify.confirm('Are you sure you want to delete this product?', {
            title: 'Delete Product',
            confirmText: 'Delete',
            cancelText: 'Cancel',
            confirmStyle: 'danger'
          }).then(confirmed => {
            if (confirmed) {
              // Submit the form
              this.submit();
            }
          });
        });
      }
    });
  }

  // Initialize cards when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeProductCards);
  } else {
    initializeProductCards();
  }

  window.openEditProductModal = function(event, product) {
    if (event) event.stopPropagation();

    // Populate form fields
    document.getElementById('editProductId').value = product.id || '';
    document.getElementById('editProduct_id').value = product.product_id || '';
    document.getElementById('editBarcode').value = product.barcode || '';
    document.getElementById('editName').value = product.name || '';
    document.getElementById('editAssetType').value = product.asset_type || '';
    document.getElementById('editManufacturer').value = product.manufacturer || '';
    document.getElementById('editColor').value = product.color || '';
    document.getElementById('editOrderNumber').value = product.order_number || '';
    document.getElementById('editPurchaseUrl').value = product.purchase_url || '';
    document.getElementById('editAcumaticaInventoryId').value = product.acumatica_inventory_id || '';
    document.getElementById('editAcumaticaDefaultVendorId').value = product.acumatica_default_vendor_id || '';

    // Set status dropdown - default to 'active' if not set or invalid
    const statusValue = product.status || 'active';
    const validStatuses = ['active', 'inactive', 'discontinued'];
    document.getElementById('editStatus').value = validStatuses.includes(statusValue) ? statusValue : 'active';

    // Handle current product image
    const currentImage = document.getElementById('editCurrentImage');
    const imagePlaceholder = document.getElementById('editImagePlaceholder');

    if (currentImage && imagePlaceholder) {
      if (product.image_path && product.image_path.trim() !== '') {
        currentImage.src = imageUrl(product.image_path);
        currentImage.style.display = 'block';
        imagePlaceholder.style.display = 'none';

        // Ensure image loads properly
        currentImage.onload = function() {
          this.style.display = 'block';
          imagePlaceholder.style.display = 'none';
        };

        currentImage.onerror = function() {
          this.style.display = 'none';
          imagePlaceholder.style.display = 'flex';
        };
      } else {
        currentImage.style.display = 'none';
        imagePlaceholder.style.display = 'flex';
      }
    }

    // Reset image upload
    const imageInput = document.getElementById('editImageInput');
    if (imageInput) {
      imageInput.value = '';
    }

    // Initialize edit modal functionality
    initializeEditModal();

    // Remove any validation styling that might be applied
    setTimeout(removeValidationStyling, 100);

    if (window.ModalManager) {
      ModalManager.show(editProductModal);
    } else {
      editProductModal.style.display = 'block';
    }

    // Set checkbox after modal is shown — ensures focus management doesn't interfere
    setTimeout(() => {
      document.getElementById('editIsTrackedAsset').checked = parseInt(product.is_tracked_asset) === 1;
    }, 0);
  }

  function initializeEditModal() {
    const imageInput = document.getElementById('editImageInput');
    const cancelBtn = document.getElementById('editCancelBtn');
    const saveBtn = document.getElementById('editSaveBtn');
    const editProductModal = document.getElementById('editProductModal');

    // Image upload handling
    if (imageInput) {
      imageInput.addEventListener('change', function(e) {
        if (e.target.files[0]) {
          handleImageUpload(e.target.files[0]);
        }
      });
    }

    // Cancel button
    if (cancelBtn && editProductModal) {
      cancelBtn.addEventListener('click', function() {
        if (window.ModalManager) {
          ModalManager.hide(editProductModal);
        } else {
          editProductModal.style.display = 'none';
        }
      });
    }

    // Form submission with loading state
    const editForm = document.getElementById('editProductForm');
    if (editForm && saveBtn) {
      editForm.addEventListener('submit', function(e) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = `
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="animate-spin">
            <path d="M21 12a9 9 0 11-6.219-8.56"/>
          </svg>
          Saving...
        `;
      });
    }

    function handleImageUpload(file) {
      if (!file) return;

      // Validate file type
      if (!file.type.startsWith('image/')) {
        if (window.QuackNotify) {
          QuackNotify.error('Please select a valid image file');
        } else {
          alert('Please select a valid image file');
        }
        return;
      }

      // Validate file size (5MB)
      if (file.size > 5 * 1024 * 1024) {
        if (window.QuackNotify) {
          QuackNotify.error('Image file size must be less than 5MB');
        } else {
          alert('Image file size must be less than 5MB');
        }
        return;
      }

      // Update the current image preview with the new file
      const currentImage = document.getElementById('editCurrentImage');
      const imagePlaceholder = document.getElementById('editImagePlaceholder');

      if (currentImage && imagePlaceholder) {
        const reader = new FileReader();
        reader.onload = function(e) {
          currentImage.src = e.target.result;
          currentImage.style.display = 'block';
          imagePlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
      }
    }
  }

  function removeValidationStyling() {
    const modal = document.getElementById('editProductModal');
    if (!modal) return;

    // Simple cleanup of validation classes
    const formElements = modal.querySelectorAll('input, select, textarea, .form-group');
    formElements.forEach(element => {
      const classesToRemove = [
        'valid', 'invalid', 'is-valid', 'is-invalid',
        'success', 'error', 'has-success', 'has-error'
      ];

      classesToRemove.forEach(className => {
        element.classList.remove(className);
      });
    });
  }

  closeEditProductButton.addEventListener('click', () => {
    ModalManager.hide(editProductModal);
  });

  // Close edit modal when clicking outside
  editProductModal.addEventListener('click', (event) => {
    if (event.target === editProductModal) {
      ModalManager.hide(editProductModal);
    }
  });

  // Handle cancel button in edit modal
  const editCancelBtn = document.getElementById('editCancelBtn');
  if (editCancelBtn) {
    editCancelBtn.addEventListener('click', () => {
      ModalManager.hide(editProductModal);
    });
  }

  // Simple Modal Manager if it doesn't exist
  if (!window.ModalManager) {
    window.ModalManager = {
      show: function(modal) {
        if (modal) {
          modal.style.display = 'flex';
          modal.classList.add('show');
          modal.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }
      },
      hide: function(modal) {
        if (modal) {
          modal.style.display = 'none';
          modal.classList.remove('show');
          modal.setAttribute('aria-hidden', 'true');
          document.body.style.overflow = ''; // Restore scrolling
        }
      }
    };
  }

  // Define the missing openEditProductModal function
  window.openEditProductModal = function(event, product) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    // Validate product object
    if (!product || typeof product !== 'object') {
      console.error('Invalid product data provided to openEditProductModal');
      return;
    }

    // Populate form fields
    document.getElementById('editProductId').value = product.id || '';
    document.getElementById('editProduct_id').value = product.product_id || '';
    document.getElementById('editBarcode').value = product.barcode || '';
    document.getElementById('editName').value = product.name || '';
    document.getElementById('editAssetType').value = product.asset_type || '';
    document.getElementById('editManufacturer').value = product.manufacturer || '';
    document.getElementById('editStatus').value = product.status || 'active';
    document.getElementById('editColor').value = product.color || '';
    document.getElementById('editOrderNumber').value = product.order_number || '';
    document.getElementById('editPurchaseUrl').value = product.purchase_url || '';
    document.getElementById('editAcumaticaInventoryId').value = product.acumatica_inventory_id || '';
    document.getElementById('editAcumaticaDefaultVendorId').value = product.acumatica_default_vendor_id || '';

    // Handle product image
    const currentImage = document.getElementById('editCurrentImage');
    const imagePlaceholder = document.getElementById('editImagePlaceholder');

    if (product.image_path && product.image_path.trim() !== '') {
      currentImage.src = imageUrl(product.image_path);
      currentImage.style.display = 'block';
      imagePlaceholder.style.display = 'none';
    } else {
      currentImage.style.display = 'none';
      imagePlaceholder.style.display = 'flex';
    }

    // Clear any previous file selection
    const imageInput = document.getElementById('editImageInput');
    if (imageInput) {
      imageInput.value = '';
    }

    // Show the modal
    ModalManager.show(editProductModal);

    // Set checkbox after modal is shown — ensures focus management doesn't interfere
    setTimeout(() => {
      document.getElementById('editIsTrackedAsset').checked = parseInt(product.is_tracked_asset) === 1;
    }, 0);

    // Set up image upload preview and label click handler
    setTimeout(() => {
      const imageInput = document.getElementById('editImageInput');
      const uploadLabel = document.querySelector('label[for="editImageInput"]');

      if (imageInput && !imageInput.hasAttribute('data-preview-initialized')) {
        imageInput.setAttribute('data-preview-initialized', 'true');

        // Handle file selection
        imageInput.addEventListener('change', function(e) {
          if (e.target.files && e.target.files[0]) {
            const file = e.target.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
              const currentImage = document.getElementById('editCurrentImage');
              const imagePlaceholder = document.getElementById('editImagePlaceholder');

              if (currentImage && imagePlaceholder) {
                currentImage.src = e.target.result;
                currentImage.style.display = 'block';
                imagePlaceholder.style.display = 'none';
              }
            };

            reader.readAsDataURL(file);
          }
        });

        // Handle label click to trigger file input
        if (uploadLabel) {
          uploadLabel.addEventListener('click', function(e) {
            e.preventDefault();
            imageInput.click();
          });
        }
      }
    }, 100);
  };

  window.openProductDetailModal = function(product) {
    // Validate product object
    if (!product || typeof product !== 'object') {
      if (isDev) console.error('Invalid product data provided to openProductDetailModal');
      return;
    }

    // Set product name
    document.getElementById('detailProductName').textContent = product.name || '';

    // Handle product image
    const productImage = document.getElementById('detailProductImage');
    const imagePlaceholder = document.getElementById('imagePlaceholder');

    if (product.image_path) {
      productImage.src = imageUrl(product.image_path);
      productImage.style.display = 'block';
      imagePlaceholder.style.display = 'none';

      productImage.onload = function() {
        this.style.display = 'block';
        imagePlaceholder.style.display = 'none';
      };

      productImage.onerror = function() {
        this.style.display = 'none';
        imagePlaceholder.style.display = 'flex';
      };
    } else {
      productImage.style.display = 'none';
      imagePlaceholder.style.display = 'flex';
    }

    // Set product details
    document.getElementById('detailProductId').textContent = product.product_id || '';
    document.getElementById('detailAssetType').textContent = product.asset_type || '';
    document.getElementById('detailManufacturer').textContent = product.manufacturer || '';
    document.getElementById('detailColor').textContent = product.color || '';
    document.getElementById('detailOrderNumber').textContent = product.order_number || '';

    // Set purchase URL with clickable link
    const purchaseUrlContainer = document.getElementById('detailPurchaseUrl');
    if (product.purchase_url) {
      try {
        const urlObj = new URL(product.purchase_url);
        purchaseUrlContainer.innerHTML = '<a href="' + escapeHtml(product.purchase_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(urlObj.hostname) + ' ↗</a>';
      } catch (e) {
        purchaseUrlContainer.textContent = product.purchase_url;
      }
    } else {
      purchaseUrlContainer.textContent = '';
    }

    // Set status with styling
    const statusElement = document.getElementById('detailStatus');
    const status = product.status || 'active';
    statusElement.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    statusElement.setAttribute('data-status', status);

    // Set timestamps
    document.getElementById('detailCreatedAt').textContent = product.created_at || '';
    document.getElementById('detailModifiedAt').textContent = product.modified_at || 'N/A';

    // Set up cart form - ensure we have a valid product ID
    const productId = product.id || 0;
    const canOrderProduct = isProductOrderable(product) && productId > 0;
    updateAddToCartAvailability(canOrderProduct);
    const isTrackedAsset = Number.parseInt(product.is_tracked_asset, 10) === 1;
    if (trackedOrderNotice) trackedOrderNotice.hidden = !isTrackedAsset;
    if (productAddToCartButton) {
      productAddToCartButton.setAttribute('aria-label', isTrackedAsset ? 'Add tracked asset to order' : 'Add product to order');
    }
    if (cartProductIdInput) {
      cartProductIdInput.value = productId;
    }
    if (cartQuantityInput) {
      cartQuantityInput.value = 1;
    }
    if (cartNotesInput) {
      cartNotesInput.value = '';
    }

    // Set up delete form for admin (if delete button exists)
    const deleteProductIdInput = document.getElementById('deleteProductId');
    if (deleteProductIdInput) {
      deleteProductIdInput.value = productId;
    }

    // Load stock information (you can enhance this with AJAX)
    document.getElementById('detailVanStock').textContent = '-';
    document.getElementById('detailRegionStock').textContent = '-';

    // Check favorite status only if we have a valid product ID
    if (productId && productId > 0) {
      // Small delay to ensure the favorites button is initialized first
      setTimeout(() => checkFavoriteStatus(productId), 150);
    } else {
      setTimeout(() => updateFavoriteButton(false), 150); // Default to not favorite if no valid ID
    }

    if (window.ModalManager) {
      ModalManager.show(productDetailModal);
    } else {
      productDetailModal.style.display = 'block';
    }
  }

  // Add to cart functionality with enhanced feedback
  if (addToCartForm) {
    addToCartForm.addEventListener('submit', async function(e) {
      if (this.dataset.orderable === 'false') {
        e.preventDefault();
        return;
      }

      e.preventDefault();

      const formData = new FormData(this);
      formData.append('action', 'add');

      const submitButton = this.querySelector('button[type="submit"]');
      const originalText = submitButton ? submitButton.textContent : '';

      try {
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Adding to Cart...';
        }

        const data = await makeAPICall('/api/cart.php', {
          method: 'POST',
          body: formData
        }, {
          timeout: 15000,
          retries: 1,
          context: 'Adding item to cart',
          showErrors: true
        });

        // Show success message
        if (window.showFormMessage) {
          window.showFormMessage('Item added to cart successfully!', 'success');
        } else {
          QuackNotify.success('Item added to cart!');
        }

        // Use global cart count updater
        if (typeof updateHeaderCartCount === 'function') {
          updateHeaderCartCount();
        }

        // Close modal
        if (window.ModalManager) {
          ModalManager.hide(productDetailModal);
        } else {
          // Safe fallback that respects accessibility
          productDetailModal.style.display = 'none';
          productDetailModal.classList.remove('show');
          setTimeout(() => {
            if (!productDetailModal.contains(document.activeElement)) {
              productDetailModal.setAttribute('aria-hidden', 'true');
            }
          }, 100);
        }

      } catch (error) {
        // Error is already displayed by makeAPICall, but we can add specific context
        console.error('Cart operation failed:', error);
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalText;
        }

        // Ensure no loading overlays remain after cart operation
        this.classList.remove('form-loading');
      }
    });
  }

  // Use the global updateHeaderCartCount function from cart-count.js
  // The function is already defined globally and will update both headerCartCount and cartCount elements

  // Delete product functionality (admin only)
  const deleteProductBtn = document.getElementById('deleteProductBtn');
  if (deleteProductBtn) {
    deleteProductBtn.addEventListener('click', async function() {
      const productName = document.getElementById('detailProductName').textContent;
      const confirmMessage = `Are you sure you want to delete "${productName}"? This action cannot be undone and will remove all associated data including stock records, transactions, and cart items.`;

      const confirmed = await QuackNotify.confirm(confirmMessage);
      if (confirmed) {
        this.closest('form').submit();
      }
    });
  }

  // Quantity button functionality
  function initializeQuantityButtons() {
    const quantityInput = document.getElementById('cartQuantity');
    const decreaseBtn = document.querySelector('.quantity-decrease');
    const increaseBtn = document.querySelector('.quantity-increase');

    if (decreaseBtn && increaseBtn && quantityInput) {
      // Remove any existing event listeners to prevent duplicates
      const newDecreaseBtn = decreaseBtn.cloneNode(true);
      const newIncreaseBtn = increaseBtn.cloneNode(true);
      decreaseBtn.parentNode.replaceChild(newDecreaseBtn, decreaseBtn);
      increaseBtn.parentNode.replaceChild(newIncreaseBtn, increaseBtn);

      // Add fresh event listeners
      newDecreaseBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const currentValue = parseInt(quantityInput.value) || 1;
        if (currentValue > 1) {
          quantityInput.value = currentValue - 1;
        }
      });

      newIncreaseBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const currentValue = parseInt(quantityInput.value) || 1;
        if (currentValue < 999) {
          quantityInput.value = currentValue + 1;
        }
      });

      // Ensure valid input (only add if not already added)
      if (!quantityInput.hasAttribute('data-initialized')) {
        quantityInput.setAttribute('data-initialized', 'true');
        quantityInput.addEventListener('input', function() {
          let value = parseInt(this.value);
          if (isNaN(value) || value < 1) {
            this.value = 1;
          } else if (value > 999) {
            this.value = 999;
          }
        });
      }
    }
  }

  // Initialize quantity buttons and favorites button when modal is shown
  const originalOpenModal = window.openProductDetailModal;
  window.openProductDetailModal = function(product) {
    originalOpenModal(product);
    // Small delay to ensure DOM is ready
    setTimeout(() => {
      initializeQuantityButtons();
      initializeFavoritesButton();
    }, 100);
  };

  // Favorites functionality with enhanced feedback
  function initializeFavoritesButton() {
    const favBtn = document.getElementById('addToFavoritesBtn');
    if (!favBtn) return;

    // Remove any existing event listeners by cloning the button
    const newFavBtn = favBtn.cloneNode(true);
    favBtn.parentNode.replaceChild(newFavBtn, favBtn);

    // Add fresh event listener
    newFavBtn.addEventListener('click', async function() {
      const productId = document.getElementById('cartProductId').value;

      // Validate product ID before making request
      if (!productId || productId <= 0) {
        if (window.showFormMessage) {
          window.showFormMessage('Cannot update favorites: Invalid product ID', 'error');
        } else {
          QuackNotify.error('Cannot update favorites: Invalid product ID');
        }
        return;
      }

      const isCurrentlyFavorite = this.dataset.isFavorite === 'true';
      const originalIcon = document.getElementById('favoriteIcon').textContent;
      const originalText = document.getElementById('favoriteText').textContent;

      try {
        this.disabled = true;
        document.getElementById('favoriteText').textContent = isCurrentlyFavorite ? 'Removing...' : 'Adding...';

        const action = isCurrentlyFavorite ? 'remove' : 'add';
        const data = await makeAPICall('/api/favorites.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `action=${action}&product_id=${productId}&csrf=${getCsrfToken()}`
        }, {
          timeout: 10000,
          retries: 1,
          context: 'Updating favorites',
          showErrors: true
        });

        // Update button state immediately
        updateFavoriteButton(!isCurrentlyFavorite);

        // Show success message
        const successMessage = isCurrentlyFavorite ? 'Removed from favorites' : 'Added to favorites';
        if (window.showFormMessage) {
          window.showFormMessage(successMessage, 'success');
        } else {
          QuackNotify.success(successMessage);
        }

        // Update the userFavorites array to reflect the change
        const productIdInt = parseInt(productId);
        if (!isCurrentlyFavorite) {
          // Added to favorites - add to array if not already there
          if (!userFavorites.includes(productIdInt)) {
            userFavorites.push(productIdInt);
          }
        } else {
          // Removed from favorites - remove from array
          const index = userFavorites.indexOf(productIdInt);
          if (index > -1) {
            userFavorites.splice(index, 1);
          }
        }

        // If currently showing favorites only, refresh the list
        if (productState.mode === 'favorites') {
          loadProducts({ reset: true });
        }

      } catch (error) {
        // Error is already displayed by makeAPICall
        console.error('Favorites operation failed:', error);
        // Restore original state on error
        document.getElementById('favoriteIcon').textContent = originalIcon;
        document.getElementById('favoriteText').textContent = originalText;
      } finally {
        this.disabled = false;
      }
    });
  }

  function updateFavoriteButton(isFavorite) {
    const btn = document.getElementById('addToFavoritesBtn');
    const icon = document.getElementById('favoriteIcon');
    const text = document.getElementById('favoriteText');

    // Check if elements exist before trying to modify them
    if (!btn || !icon || !text) {
      // Favorite button elements not available
      return;
    }

    btn.dataset.isFavorite = isFavorite;

    if (isFavorite) {
      icon.textContent = '★';
      text.textContent = 'Remove from Favorites';
      btn.classList.add('is-favorite');
    } else {
      icon.textContent = '☆';
      text.textContent = 'Add to Favorites';
      btn.classList.remove('is-favorite');
    }
  }

  async function checkFavoriteStatus(productId) {
    // Validate product ID before making API call
    if (!productId || productId <= 0) {
      // Invalid product ID for favorite check
      updateFavoriteButton(false); // Default to not favorite
      return;
    }

    // First check if we already know the status from userFavorites array
    const productIdInt = parseInt(productId);
    if (userFavorites && Array.isArray(userFavorites)) {
      const isFavoriteLocal = userFavorites.includes(productIdInt);
      updateFavoriteButton(isFavoriteLocal);
      return; // Use local data instead of making API call
    }

    try {
      const data = await makeAPICall(`/api/favorites.php?action=check&product_id=${productId}`, {}, {
        timeout: 10000,
        retries: 1,
        context: 'Checking favorite status',
        showErrors: false // Don't show errors for status checks
      });

      if (data.success) {
        const isFavorite = data.data?.is_favorite ?? false;
        updateFavoriteButton(isFavorite);

        // Update local userFavorites array
        if (isFavorite && !userFavorites.includes(productIdInt)) {
          userFavorites.push(productIdInt);
        } else if (!isFavorite && userFavorites.includes(productIdInt)) {
          const index = userFavorites.indexOf(productIdInt);
          if (index > -1) {
            userFavorites.splice(index, 1);
          }
        }
      }
    } catch (error) {
      if (isDev) console.error('Error checking favorite status:', error);
      updateFavoriteButton(false); // Default to not favorite on error
    }
  }

  function getCsrfToken() {
    return document.querySelector('input[name="csrf"]')?.value || '';
  }

  // Cart count is automatically loaded by the global cart-count.js script

  // Search and Filter Functionality
  const searchInput = document.getElementById('searchInput');
  const showFavoritesCheckbox = document.getElementById('showFavoritesCheckbox');
  const clearFiltersBtn = document.getElementById('clearFiltersBtn');
  const mobileFilterToggle = document.getElementById('mobileFilterToggle');
  const filterSidebar = document.getElementById('filterSidebar');
  const closeSidebarBtn = document.getElementById('closeSidebarBtn');
  const activeFilterCount = document.getElementById('activeFilterCount');

  const productsGrid = document.getElementById('productsGrid');
  const productsSkeleton = document.getElementById('productsSkeleton');
  const productsLoader = document.getElementById('productsLoader');
  const productsEmpty = document.getElementById('productsEmpty');
  const productsError = document.getElementById('productsError');
  const productsSentinel = document.getElementById('productsSentinel');
  const resultsCountEl = document.getElementById('resultsCount');

  const productState = {
    items: [],
    total: 0,
    offset: 0,
    pageSize: 24,
    hasMore: true,
    isLoading: false,
    mode: 'browse', // browse | search | favorites
    searchTerm: '',
    category: '',
    manufacturers: [],  // Array for multi-select
    status: ''
  };

  let userFavorites = [];

  function toggleSkeleton(show) {
    if (!productsSkeleton) return;
    productsSkeleton.hidden = !show;
  }

  function toggleLoader(show) {
    if (!productsLoader) return;
    productsLoader.hidden = !show;
  }

  function setProductsError(message) {
    if (!productsError) return;
    if (message) {
      productsError.textContent = message;
      productsError.hidden = false;
    } else {
      productsError.textContent = '';
      productsError.hidden = true;
    }
  }

  function updateResultsCount(displayed = null) {
    if (!resultsCountEl) return;
    const shown = displayed !== null ? displayed : productState.items.length;
    const total = productState.total || shown;
    resultsCountEl.textContent = (shown || total)
      ? `Showing ${shown} of ${total} products`
      : 'No products to display yet.';
  }

  function updateEmptyState() {
    if (!productsEmpty) return;
    productsEmpty.hidden = productState.items.length > 0;
  }

  function sentinelInView() {
    if (!productsSentinel) return false;
    const rect = productsSentinel.getBoundingClientRect();
    return rect.top <= window.innerHeight + 300;
  }

  function maybeAutoLoadMore() {
    if (productState.hasMore && !productState.isLoading && sentinelInView()) {
      loadProducts();
    }
  }

  function buildProductCard(product) {
    const card = document.createElement('div');
    card.className = 'card';
    if (product.id) {
      card.dataset.productId = product.id;
      card.setAttribute('data-product-id', product.id);
    }

    const clickable = document.createElement('div');
    clickable.className = 'card-clickable';
    clickable.setAttribute('role', 'button');
    clickable.setAttribute('tabindex', '0');
    const accessibleName = product.name || 'product';
    clickable.setAttribute('aria-label', `View details for ${accessibleName}`);
    clickable.dataset.product = JSON.stringify(product);

    if (product.image_path) {
      const img = document.createElement('img');
      img.src = imageUrl(product.image_path);
      img.alt = product.name || 'Product image';
      clickable.appendChild(img);
    }

    const cardBody = document.createElement('div');
    cardBody.className = 'card-body';

    const nameLine = document.createElement('div');
    const strong = document.createElement('strong');
    strong.textContent = product.name || 'Unnamed product';
    nameLine.appendChild(strong);
    cardBody.appendChild(nameLine);

    const idLine = document.createElement('div');
    idLine.textContent = `ID: ${product.product_id || 'N/A'}`;
    cardBody.appendChild(idLine);

    clickable.appendChild(cardBody);
    card.appendChild(clickable);

    const actions = document.createElement('div');
    actions.className = 'card-actions';

    if (userCanEditProducts) {
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-secondary btn-sm edit-product-btn';
      editBtn.dataset.product = JSON.stringify(product);
      editBtn.setAttribute('aria-label', `Edit ${accessibleName}`);
      editBtn.textContent = 'Edit';
      actions.appendChild(editBtn);
    }

    const canAddToCart = isProductOrderable(product) && Boolean(product.id);
    if (canAddToCart) {
      const cartBtn = document.createElement('button');
      cartBtn.type = 'button';
      cartBtn.className = 'btn btn-primary btn-sm add-to-cart-btn';
      cartBtn.dataset.productId = product.id || '';
      cartBtn.dataset.productName = product.name || '';
      cartBtn.setAttribute('aria-label', `Add ${accessibleName} to cart`);
      cartBtn.textContent = 'Add to Cart';
      actions.appendChild(cartBtn);
    }

    card.appendChild(actions);
    return card;
  }

  function renderProducts(products, { reset = false } = {}) {
    if (!productsGrid || !Array.isArray(products)) return;

    const fragment = document.createDocumentFragment();
    products.forEach(product => fragment.appendChild(buildProductCard(product)));

    if (reset) {
      productsGrid.innerHTML = '';
    }

    productsGrid.appendChild(fragment);
    initializeProductCards(productsGrid);
  }

  async function loadProducts({ reset = false } = {}) {
    if (productState.isLoading) return;

    productState.isLoading = true;
    setProductsError('');

    if (reset) {
      productState.offset = 0;
      productState.total = 0;
      productState.items = [];
      productState.hasMore = true;
      if (productsGrid) {
        productsGrid.innerHTML = '';
      }
      toggleLoader(false);
      toggleSkeleton(true);
      updateResultsCount(0);
    } else {
      toggleLoader(true);
    }

    try {
      if (productState.mode === 'favorites') {
        const favoritesResponse = await makeAPICall(`/api/favorites.php?action=list&limit=${productState.pageSize}&page=1`, {}, {
          timeout: 15000,
          retries: 1,
          context: 'Loading favorites',
          showErrors: false
        });

        const favoriteItems = (favoritesResponse.data?.favorites ?? []).map(item => ({
          id: item.id ? parseInt(item.id, 10) : null,
          product_id: item.product_id || item.sku || '',
          name: item.name || '',
          image_path: item.image_path || '',
          asset_type: item.asset_type || '',
          manufacturer: item.manufacturer || '',
          status: item.status || '',
          color: item.color || '',
          order_number: item.order_number || '',
          purchase_url: item.purchase_url || '',
          created_at: item.created_at || '',
          modified_at: item.modified_at || '',
          van_stock: item.van_stock ?? 0,
          region_stock: item.region_stock ?? 0
        }));

        productState.items = favoriteItems;
        const pagination = favoritesResponse.data?.pagination;
        productState.total = pagination?.total ?? favoriteItems.length;
        productState.hasMore = pagination?.has_more ?? false;
        renderProducts(favoriteItems, { reset: true });
      } else {
        const params = new URLSearchParams();
        params.set('limit', String(productState.pageSize));
        params.set('offset', String(productState.offset));
        if (productState.searchTerm) params.set('q', productState.searchTerm);
        if (productState.category) params.set('category', productState.category);
        if (productState.status) params.set('status', productState.status);
        // Send manufacturers as multiple params or comma-separated
        if (productState.manufacturers && productState.manufacturers.length > 0) {
          productState.manufacturers.forEach(mfr => {
            params.append('manufacturer[]', mfr);
          });
        }

        const response = await makeAPICall(`/api/products.php?action=search&${params.toString()}`, {}, {
          timeout: 15000,
          retries: 1,
          context: 'Loading products',
          showErrors: reset
        });

        const products = response.data?.products ?? [];
        if (reset) {
          productState.items = [];
        }
        productState.items = productState.items.concat(products);
        productState.total = response.data?.total ?? productState.items.length;
        const apiHasMore = response.data?.has_more;
        productState.hasMore = typeof apiHasMore === 'boolean' ? apiHasMore : products.length === productState.pageSize;
        productState.offset += products.length;

        renderProducts(reset ? productState.items : products, { reset });
      }

      updateResultsCount();
      updateEmptyState();
    } catch (error) {
      if (isDev) {
        console.error('Product load failed:', error);
      }

      if (productState.items.length === 0) {
        setProductsError('We could not load products right now. Please try again.');
      } else {
        setProductsError('Some products failed to load. Scroll to try again.');
      }
    } finally {
      productState.isLoading = false;
      toggleSkeleton(false);
      toggleLoader(false);
      maybeAutoLoadMore();
    }
  }

  const infiniteObserver = productsSentinel
    ? new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting && productState.hasMore && !productState.isLoading) {
            loadProducts();
          }
        });
      }, { rootMargin: '300px 0px' })
    : null;

  async function initializeProductsData() {
    // Load categories and manufacturers from API
    await Promise.all([
      loadCategories(),
      loadManufacturers(),
      loadUserFavorites()
    ]);

    // Setup event listeners
    setupStatusFilterListeners();
    setupMobileToggle();
    setupFilterSidebarToggle();

    // Initial load
    loadProducts({ reset: true });

    // Setup infinite scroll observer
    if (infiniteObserver && productsSentinel) {
      infiniteObserver.observe(productsSentinel);
    }
  }

  // Load user favorites
  async function loadUserFavorites() {
    try {
      const data = await makeAPICall('/api/favorites.php?action=list&limit=500&page=1', {}, {
        timeout: 10000,
        retries: 1,
        context: 'Loading favorites list',
        showErrors: false // Don't show errors for favorites loading
      });

      if (data.success && data.data?.favorites) {
        userFavorites = data.data.favorites.map(f => parseInt(f.id));
      } else if (data.success) {
        userFavorites = [];
      }
    } catch (error) {
      if (isDev) console.error('Error loading favorites:', error);
      userFavorites = [];
    }
  }

  // Helper functions to get selected filter values
  function getSelectedCategory() {
    const checked = document.querySelector('input[name="category"]:checked');
    return checked ? checked.value : '';
  }

  function getSelectedManufacturers() {
    const checkboxes = document.querySelectorAll('input[name="manufacturer"]:checked');
    return Array.from(checkboxes)
      .map(cb => cb.value)
      .filter(v => v !== '__all__');  // Exclude "All Manufacturers" checkbox
  }

  function getSelectedStatus() {
    const checked = document.querySelector('input[name="status"]:checked');
    return checked ? checked.value : '';
  }

  function isShowingFavoritesOnly() {
    return showFavoritesCheckbox?.checked || false;
  }

  function applyFiltersFromUI() {
    productState.searchTerm = searchInput?.value.trim() || '';
    productState.category = getSelectedCategory();
    productState.manufacturers = getSelectedManufacturers();
    productState.status = getSelectedStatus();

    const favoritesActive = isShowingFavoritesOnly();
    if (favoritesActive) {
      productState.mode = 'favorites';
    } else if (productState.searchTerm || productState.category || productState.manufacturers.length > 0 || productState.status) {
      productState.mode = 'search';
    } else {
      productState.mode = 'browse';
    }

    productState.offset = 0;
    productState.hasMore = true;
    updateActiveFilterCount();
    loadProducts({ reset: true });
  }

  // Debounce utility for real-time search
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func.apply(this, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Debounced search - triggers 300ms after user stops typing
  let searchDebounceTimer;
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      clearTimeout(searchDebounceTimer);
      searchDebounceTimer = setTimeout(() => {
        applyFiltersFromUI();
      }, 300);
    });
  }

  // Favorites checkbox
  if (showFavoritesCheckbox) {
    showFavoritesCheckbox.addEventListener('change', () => {
      applyFiltersFromUI();
    });
  }

  // Clear filters button
  if (clearFiltersBtn) {
    clearFiltersBtn.addEventListener('click', () => {
      clearAllFilters();
    });
  }

  /**
   * HTML escape utility for security
   */
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Load categories dynamically from API
   */
  async function loadCategories() {
    try {
      const data = await makeAPICall('/api/products.php?action=get_categories', {}, {
        timeout: 10000,
        retries: 1,
        context: 'Loading categories',
        showErrors: false
      });

      const container = document.getElementById('categoryFilterOptions');
      if (container && data.data && data.data.categories) {
        // Clear and rebuild with "All Categories" first
        container.innerHTML = `
          <label class="filter-radio">
            <input type="radio" name="category" value="" checked>
            <span>All Categories</span>
          </label>
        `;

        // Add categories from API
        data.data.categories.forEach(cat => {
          const label = document.createElement('label');
          label.className = 'filter-radio';
          label.innerHTML = `
            <input type="radio" name="category" value="${escapeHtml(cat)}">
            <span>${escapeHtml(cat)}</span>
          `;
          container.appendChild(label);
        });

        // Attach event listeners to new radio buttons
        container.querySelectorAll('input[name="category"]').forEach(input => {
          input.addEventListener('change', () => {
            applyFiltersFromUI();
          });
        });
      }
    } catch (error) {
      if (isDev) console.error('Error loading categories:', error);
    }
  }

  /**
   * Load manufacturers dynamically from API
   */
  async function loadManufacturers() {
    try {
      const data = await makeAPICall('/api/products.php?action=get_manufacturers', {}, {
        timeout: 10000,
        retries: 1,
        context: 'Loading manufacturers',
        showErrors: false
      });

      const container = document.getElementById('manufacturerFilterOptions');
      if (container && data.data && data.data.manufacturers) {
        // Keep "All Manufacturers" checkbox, add others
        container.innerHTML = `
          <label class="filter-checkbox">
            <input type="checkbox" value="__all__" id="allManufacturers" checked>
            <span>All Manufacturers</span>
          </label>
        `;

        // Add manufacturers from API
        data.data.manufacturers.forEach(mfr => {
          const label = document.createElement('label');
          label.className = 'filter-checkbox';
          label.innerHTML = `
            <input type="checkbox" name="manufacturer" value="${escapeHtml(mfr)}">
            <span>${escapeHtml(mfr)}</span>
          `;
          container.appendChild(label);
        });

        // "All Manufacturers" logic: uncheck others when checked
        const allCheckbox = document.getElementById('allManufacturers');
        if (allCheckbox) {
          allCheckbox.addEventListener('change', (e) => {
            if (e.target.checked) {
              container.querySelectorAll('input[name="manufacturer"]').forEach(cb => {
                cb.checked = false;
              });
            }
            applyFiltersFromUI();
          });
        }

        // Individual manufacturer checkboxes: uncheck "All" when any is checked
        container.querySelectorAll('input[name="manufacturer"]').forEach(input => {
          input.addEventListener('change', (e) => {
            if (e.target.checked && allCheckbox) {
              allCheckbox.checked = false;
            }
            applyFiltersFromUI();
          });
        });
      }
    } catch (error) {
      if (isDev) console.error('Error loading manufacturers:', error);
    }
  }

  /**
   * Setup event listeners for status filter (static radio buttons)
   */
  function setupStatusFilterListeners() {
    document.querySelectorAll('input[name="status"]').forEach(input => {
      input.addEventListener('change', () => {
        applyFiltersFromUI();
      });
    });
  }

  /**
   * Mobile sidebar toggle handlers
   */
  function setupMobileToggle() {
    if (mobileFilterToggle) {
      mobileFilterToggle.addEventListener('click', () => {
        if (filterSidebar) {
          filterSidebar.classList.add('active');
          document.body.style.overflow = 'hidden'; // Prevent background scroll
        }
      });
    }

    if (closeSidebarBtn) {
      closeSidebarBtn.addEventListener('click', () => {
        if (filterSidebar) {
          filterSidebar.classList.remove('active');
          document.body.style.overflow = '';
        }
      });
    }

    // Close on overlay click (clicking the sidebar backdrop)
    if (filterSidebar) {
      filterSidebar.addEventListener('click', (e) => {
        if (e.target === filterSidebar) {
          filterSidebar.classList.remove('active');
          document.body.style.overflow = '';
        }
      });
    }
  }

  /**
   * Toggle collapsible filter section
   */
  function toggleFilterSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
      section.classList.toggle('collapsed');
    }
  }

  // Make toggleFilterSection globally accessible for onclick
  window.toggleFilterSection = toggleFilterSection;

  /**
   * Toggle filter sidebar collapse/expand
   */
  function setupFilterSidebarToggle() {
    const toggleBtn = document.getElementById('filterSidebarToggle');
    const sidebar = document.getElementById('filterSidebar');
    const productsLayout = document.querySelector('.products-page-body');
    const toggleIcon = toggleBtn?.querySelector('.filter-sidebar-toggle-icon');

    if (toggleBtn && sidebar) {
      toggleBtn.addEventListener('click', () => {
        const isCollapsed = sidebar.classList.toggle('sidebar-collapsed');

        // Toggle layout class for grid adjustment
        if (productsLayout) {
          productsLayout.classList.toggle('sidebar-collapsed-layout', isCollapsed);
        }

        // Update icon direction
        if (toggleIcon) {
          toggleIcon.textContent = isCollapsed ? '»' : '«';
        }

        // Update aria label
        toggleBtn.setAttribute('aria-label', isCollapsed ? 'Show filters' : 'Hide filters');
        toggleBtn.setAttribute('title', isCollapsed ? 'Show Filters' : 'Hide Filters');
      });
    }
  }

  /**
   * Update active filter count badge
   */
  function updateActiveFilterCount() {
    if (!activeFilterCount) return;

    let count = 0;
    if (productState.searchTerm) count++;
    if (productState.category) count++;
    if (productState.manufacturers && productState.manufacturers.length > 0) count++;
    if (productState.status) count++;
    if (productState.mode === 'favorites') count++;

    if (count > 0) {
      activeFilterCount.textContent = count;
      activeFilterCount.style.display = 'inline-block';
    } else {
      activeFilterCount.style.display = 'none';
    }
  }

  /**
   * Clear all filters function
   */
  function clearAllFilters() {
    // Clear search
    if (searchInput) searchInput.value = '';

    // Reset category to "All"
    const allCategoryRadio = document.querySelector('input[name="category"][value=""]');
    if (allCategoryRadio) allCategoryRadio.checked = true;

    // Uncheck all manufacturers except "All"
    document.querySelectorAll('input[name="manufacturer"]').forEach(cb => {
      cb.checked = false;
    });
    const allManufacturersCheckbox = document.getElementById('allManufacturers');
    if (allManufacturersCheckbox) allManufacturersCheckbox.checked = true;

    // Reset status to "All"
    const allStatusRadio = document.querySelector('input[name="status"][value=""]');
    if (allStatusRadio) allStatusRadio.checked = true;

    // Uncheck favorites
    if (showFavoritesCheckbox) showFavoritesCheckbox.checked = false;

    // Reset state and reload
    productState.searchTerm = '';
    productState.category = '';
    productState.manufacturers = [];
    productState.status = '';
    productState.mode = 'browse';
    productState.offset = 0;
    productState.hasMore = true;
    updateActiveFilterCount();
    loadProducts({ reset: true });
  }

  // Make clearAllFilters available globally
  window.clearAllFilters = clearAllFilters;

  /**
   * Test function to verify API security (development only)
   */
  function testAPISecurity() {
    if (window.location.hostname !== 'localhost' && !window.location.hostname.includes('dev')) {
      return; // Only run in development
    }

    // API Security validation (silent in production)
    const csrfToken = getCsrfToken();
    if (!csrfToken || typeof makeAPICall !== 'function' || typeof secureFetch !== 'function') {
      console.error('Critical API security components missing');
    }
  }

  // Development mode detection
  const isDev = window.location.hostname === 'localhost' ||
                window.location.hostname.startsWith('dev.') ||
                window.location.hostname.includes('.dev.') ||
                window.location.hostname.startsWith('beta.') ||
                window.location.hostname.includes('.beta.') ||
                window.location.hostname.includes('localhost');

  // Run security test in development
  if (isDev) {
    setTimeout(testAPISecurity, 1000);
  }

  // Initialize products data when page loads
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initializeProductsData();
    });
  } else {
    initializeProductsData();
  }

  productDetailCloseButtons.forEach(button => {
    button.addEventListener('click', () => {
      if (window.ModalManager) {
        ModalManager.hide(productDetailModal);
      } else {
        // Safe fallback that respects accessibility
        productDetailModal.style.display = 'none';
        productDetailModal.classList.remove('show');
        // Only set aria-hidden if no focused elements within modal
        setTimeout(() => {
          if (!productDetailModal.contains(document.activeElement)) {
            productDetailModal.setAttribute('aria-hidden', 'true');
          }
        }, 100);
      }
    });
  });

  window.addEventListener('click', (event) => {
    // Only close modal if clicking on the modal backdrop itself, not the content
    if (event.target === productDetailModal && productDetailModal.style.display !== 'none') {
      if (window.ModalManager) {
        ModalManager.hide(productDetailModal);
      } else {
        // Safe fallback that respects accessibility
        productDetailModal.style.display = 'none';
        productDetailModal.classList.remove('show');
        // Only set aria-hidden if no focused elements within modal
        setTimeout(() => {
          if (!productDetailModal.contains(document.activeElement)) {
            productDetailModal.setAttribute('aria-hidden', 'true');
          }
        }, 100);
      }
    }
  });

  // Barcode Scanner Logic
  const scannerModal = document.getElementById('scannerModal');
  const openScannerModalBtn = document.getElementById('openScannerModalBtn');
  const closeScannerButton = document.getElementById('closeScannerButton');
  const scannerVideo = document.getElementById('scannerVideo');
  const scannerResult = document.getElementById('scannerResult');
  const stopScannerButton = document.getElementById('stopScannerButton');

  let codeReader;
  let currentStream;

  const BARCODE_PATTERN = /^[A-Za-z0-9:_-]+$/;
  function sanitizeBarcodeInput(rawValue) {
    if (typeof rawValue !== 'string') {
      return '';
    }
    const trimmed = rawValue.trim();
    if (trimmed === '' || trimmed.length > 100) {
      return '';
    }
    if (!BARCODE_PATTERN.test(trimmed)) {
      return '';
    }
    return trimmed;
  }

  openScannerModalBtn.addEventListener('click', () => {
    try {
      if (window.ModalManager) {
        ModalManager.show(scannerModal);
      } else {
        scannerModal.style.display = 'block';
      }

      // Add a small delay to ensure modal is visible before starting scanner
      setTimeout(() => {
        startScanner();
      }, 300);
    } catch (error) {
      console.error('Scanner modal error:', error);
      QuackNotify.error('Failed to open scanner: ' + error.message);
    }
  });

  closeScannerButton.addEventListener('click', () => {
    if (window.ModalManager) {
      ModalManager.hide(scannerModal);
    } else {
      scannerModal.style.display = 'none';
    }
    stopScanner();
  });

  stopScannerButton.addEventListener('click', () => {
    stopScanner();
    if (window.ModalManager) {
      ModalManager.hide(scannerModal);
    } else {
      scannerModal.style.display = 'none';
    }
  });

  // Zoom control variables
  let currentZoom = 1;
  const minZoom = 1;
  const maxZoom = 4;
  const zoomStep = 0.5;
  const zoomInBtn = document.getElementById('zoomInBtn');
  const zoomOutBtn = document.getElementById('zoomOutBtn');
  const zoomLevelDisplay = document.getElementById('zoomLevel');

  // Zoom in handler
  zoomInBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (currentZoom < maxZoom) {
      currentZoom += zoomStep;
      applyZoom();
    }
  });

  // Zoom out handler
  zoomOutBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (currentZoom > minZoom) {
      currentZoom -= zoomStep;
      applyZoom();
    }
  });

  // Apply zoom to video element
  function applyZoom() {
    scannerVideo.style.transform = `scale(${currentZoom})`;
    scannerVideo.style.transformOrigin = 'center center';
    zoomLevelDisplay.textContent = currentZoom.toFixed(1) + '×';
    zoomInBtn.disabled = currentZoom >= maxZoom;
    zoomOutBtn.disabled = currentZoom <= minZoom;

    // Try to apply zoom to camera hardware if supported
    if (scannerVideo.srcObject && scannerVideo.srcObject.getVideoTracks().length > 0) {
      const videoTrack = scannerVideo.srcObject.getVideoTracks()[0];
      const capabilities = videoTrack.getCapabilities ? videoTrack.getCapabilities() : {};

      // Hardware zoom (if supported - useful on mobile)
      if (capabilities.zoom && videoTrack.applyConstraints) {
        try {
          const hardwareZoom = Math.min(currentZoom, capabilities.zoom.max || 4);
          videoTrack.applyConstraints({
            advanced: [{ zoom: hardwareZoom }]
          }).catch(() => {
            // Hardware zoom not supported, CSS zoom is fine
          });
        } catch (e) {
          AppLogger.log('Hardware zoom not supported');
        }
      }
    }
  }

  window.addEventListener('click', (event) => {
    // Only close modal if clicking on the modal backdrop itself
    if (event.target === scannerModal && scannerModal.style.display !== 'none') {
      if (window.ModalManager) {
        ModalManager.hide(scannerModal);
      } else {
        scannerModal.style.display = 'none';
      }
      stopScanner();
    }
  });

  async function startScanner() {
    try {
      scannerResult.textContent = 'Loading scanner...';
      stopScannerButton.style.display = 'none'; // Hide stop button initially

      // Check if loadScannerLibrary is available
      if (typeof window.loadScannerLibrary !== 'function') {
        scannerResult.textContent = 'Scanner library loader not available.';
        AppLogger.error('loadScannerLibrary function not found');
        QuackNotify.error('Barcode scanner is not available. Please refresh the page and try again.');
        return;
      }

      // Lazy-load the ZXing library
      let ZXingLib;
      try {
        scannerResult.textContent = 'Loading scanner library...';
        ZXingLib = await window.loadScannerLibrary();
      } catch (loadError) {
        scannerResult.textContent = 'Failed to load scanner library. Please check your internet connection and try again.';
        AppLogger.error('Scanner library load failed:', loadError);
        QuackNotify.error('Barcode scanner is not available. Please check your internet connection and try again.');
        return;
      }

      scannerResult.textContent = 'Initializing scanner...';

      // Check if we're on HTTPS (required for camera access)
      if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
        scannerResult.textContent = 'Camera access requires HTTPS. Please access the site using https:// instead of http://';
        QuackNotify.error('Barcode scanner requires HTTPS. Please use https:// to access this page.');
        return;
      }

      scannerResult.textContent = 'Requesting camera access...';

      // Try different reader types for better compatibility
      // Use optimized hints for QR code detection (especially small codes)
      const hints = new Map();
      // TRY_HARDER improves detection accuracy for small/difficult codes
      hints.set(ZXingLib.DecodeHintType.TRY_HARDER, true);
      // POSSIBLE_FORMATS limits to QR only for better performance
      hints.set(ZXingLib.DecodeHintType.POSSIBLE_FORMATS, [ZXingLib.BarcodeFormat.QR_CODE]);

      try {
        // Use multi-format reader with QR-optimized hints
        codeReader = new ZXingLib.BrowserMultiFormatReader(hints);
      } catch (qrError) {
        // Fallback to basic multi-format reader if optimization fails
        AppLogger.warn('Could not create optimized reader, using basic multi-format reader:', qrError);
        codeReader = new ZXingLib.BrowserMultiFormatReader(new Map().set(ZXingLib.DecodeHintType.TRY_HARDER, true));
      }
      AppLogger.log('QR code reader created with optimization hints for small codes');

      scannerResult.textContent = 'Detecting cameras...';

      // iOS Safari requires special handling - it may not enumerate cameras until after permission granted
      let videoInputDevices = [];
      let listRetries = 0;
      const maxRetries = 3;

      // Try to list devices with retries for iOS Safari compatibility
      while (listRetries < maxRetries && videoInputDevices.length === 0) {
        try {
          videoInputDevices = await codeReader.listVideoInputDevices();
          AppLogger.log('Available cameras (attempt ' + (listRetries + 1) + '):', videoInputDevices);

          if (videoInputDevices.length === 0 && listRetries < maxRetries - 1) {
            // If no cameras found on first attempt, wait a moment and retry
            // (iOS Safari sometimes needs time to enumerate after permission prompt)
            await new Promise(resolve => setTimeout(resolve, 500));
            listRetries++;
          } else {
            break;
          }
        } catch (listError) {
          AppLogger.warn('Camera enumeration error (attempt ' + (listRetries + 1) + '):', listError);
          if (listRetries < maxRetries - 1) {
            // Wait and retry on enumeration errors
            await new Promise(resolve => setTimeout(resolve, 500));
            listRetries++;
          } else {
            // After retries, fall back to default device (works on most devices even without enumeration)
            AppLogger.log('Camera enumeration failed, attempting fallback method...');
            break;
          }
        }
      }

      let deviceId = undefined;

      // Try to use enumerated device, or fall back to undefined (auto-detect)
      if (videoInputDevices && videoInputDevices.length > 0) {
        scannerResult.textContent = `Found ${videoInputDevices.length} camera(s). Starting scanner...`;
        // Prefer environment camera if available - with safe property access
        const environmentCamera = videoInputDevices.find(device => {
          if (!device) return false;
          const hasBackLabel = device.label && typeof device.label === 'string' && device.label.toLowerCase().includes('back');
          const hasEnvironmentId = device.kind === 'videoinput' && device.deviceId && typeof device.deviceId === 'string' && device.deviceId.toLowerCase().includes('environment');
          return hasBackLabel || hasEnvironmentId;
        });

        deviceId = (environmentCamera && environmentCamera.deviceId) ? environmentCamera.deviceId :
                   (videoInputDevices[0] && videoInputDevices[0].deviceId) ? videoInputDevices[0].deviceId : undefined;
      } else {
        // iOS Safari fallback: attempt with undefined deviceId (uses default camera)
        // This works on most devices even when enumeration fails
        AppLogger.log('No cameras enumerated, attempting fallback with default device...');
        scannerResult.textContent = 'Starting scanner with default camera...';
        deviceId = undefined;
      }

      // Add minimal video event listeners
      scannerVideo.addEventListener('playing', async () => {
        scannerResult.textContent = 'Ready to scan - point camera at barcode';

        // Try to enable camera focus and zoom for small code scanning
        if (scannerVideo.srcObject && scannerVideo.srcObject.getVideoTracks().length > 0) {
          const videoTrack = scannerVideo.srcObject.getVideoTracks()[0];
          const settings = videoTrack.getSettings ? videoTrack.getSettings() : {};

          // Log camera capabilities for debugging
          AppLogger.log('Camera settings:', settings);

          // Try to optimize focus for small codes
          if (videoTrack.applyConstraints) {
            try {
              // Request auto focus (continuous) and expose manual focus capability
              await videoTrack.applyConstraints({
                advanced: [
                  {
                    // Focus mode: continuous (auto-adjusts) is best for moving codes
                    focusMode: 'continuous',
                    focusDistance: 0.1  // Prioritize close-up focus (10cm)
                  }
                ]
              }).catch(() => {
                // If advanced constraints fail, that's okay - use defaults
                AppLogger.log('Advanced focus constraints not supported, using camera defaults');
              });
            } catch (e) {
              AppLogger.log('Could not apply camera constraints:', e);
            }
          }
        }
      });

      scannerVideo.addEventListener('error', (e) => {
        scannerResult.textContent = 'Camera error occurred';
      });

      // Start continuous scanning with either enumerated or default device
      codeReader.decodeFromVideoDevice(deviceId, scannerVideo, async (result, err) => {
        if (result) {
          const sanitizedScan = sanitizeBarcodeInput(result.text ?? '');
          if (!sanitizedScan) {
            showScanResult('Scanned barcode contains unsupported characters.', 'error');
            stopScanner();
            if (window.ModalManager) {
              ModalManager.hide(scannerModal);
            } else {
              scannerModal.style.display = 'none';
            }
            return;
          }

          // Barcode scanned successfully
          scannerResult.textContent = '';
          const successLine = document.createElement('div');
          successLine.style.color = '#28a745';
          successLine.style.fontWeight = 'bold';
          successLine.textContent = `✅ Scanned: ${sanitizedScan}`;
          const detailLine = document.createElement('div');
          detailLine.textContent = 'Looking up product...';
          scannerResult.appendChild(successLine);
          scannerResult.appendChild(detailLine);

          // Stop the scanner
          stopScanner();

          // Close scanner modal
          if (window.ModalManager) {
            ModalManager.hide(scannerModal);
          } else {
            scannerModal.style.display = 'none';
          }

          // Look up the product by barcode/SKU
          await findProductByBarcode(sanitizedScan);
        }
        // Silently ignore all errors - they're normal decode attempts
      });
      stopScannerButton.style.display = 'inline-block'; // Show stop button once scanning starts
    } catch (err) {
      AppLogger.error('Camera access error:', err);
      let errorMessage = 'Camera access error: ';

      if (err.name === 'NotAllowedError') {
        errorMessage += 'Camera permission denied. Please allow camera access and try again.';
      } else if (err.name === 'NotFoundError') {
        errorMessage += 'No camera found on this device.';
      } else if (err.name === 'NotSupportedError') {
        errorMessage += 'Camera not supported on this device/browser.';
      } else if (err.name === 'NotReadableError') {
        errorMessage += 'Camera is already in use by another application.';
      } else if (err.message && err.message.includes('No valid camera device found')) {
        errorMessage += 'Could not detect camera. This is often a permissions issue on iOS.';
      } else {
        errorMessage += err.message || 'Unknown error occurred.';
      }

      if (scannerResult) {
        scannerResult.textContent = errorMessage + '\n\nTroubleshooting:\n' +
          '• Ensure you\'re using HTTPS (https://, not http://)\n' +
          '• Grant camera permissions: Settings > [App] > Camera\n' +
          '• Close other apps using camera\n' +
          '• Try refreshing the page\n' +
          '• On iOS: Try a different browser (Chrome, Firefox)';
      }

      if (typeof QuackNotify !== 'undefined') {
        QuackNotify.error(errorMessage);
      }
      stopScanner();
    }
  }

  function stopScanner() {
    if (codeReader) {
      codeReader.reset();
      codeReader = null;
    }
    if (currentStream) {
      currentStream.getTracks().forEach(track => track.stop());
      currentStream = null;
    }
    scannerVideo.srcObject = null;
    scannerResult.textContent = '';
    stopScannerButton.style.display = 'none';

    // Reset zoom to default
    currentZoom = 1;
    scannerVideo.style.transform = 'scale(1)';
    zoomLevelDisplay.textContent = '1×';
    zoomInBtn.disabled = false;
    zoomOutBtn.disabled = true;
  }

  async function findProductByBarcode(barcode) {
    const normalizedBarcode = sanitizeBarcodeInput(barcode);
    if (!normalizedBarcode) {
      showScanResult('Barcode must be alphanumeric (dashes, underscores, and colons allowed) and up to 50 characters.', 'error');
      return;
    }

    try {
      // Show loading state
      const loadingMessage = document.createElement('div');
      loadingMessage.id = 'scanLoadingMessage';
      loadingMessage.className = 'flash info';
      loadingMessage.textContent = `Searching for product: ${normalizedBarcode}...`;
      loadingMessage.style.cssText = 'position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 10000; padding: 15px 30px; border-radius: 5px;';
      document.body.appendChild(loadingMessage);

      const response = await fetch('/api/scan_product.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'barcode=' + encodeURIComponent(normalizedBarcode) + '&csrf=' + getCsrfToken()
      });

      const data = await response.json();

      // Remove loading message
      loadingMessage.remove();

      if (data.success && data.data) {
        // Product found - show success message briefly then open modal
        showScanResult(`✓ Product Found: ${data.data.name}`, 'success', 1500);

        // Prepare product data for modal (ensure all required fields)
        const productData = {
          ...data.data,
          created_at: data.data.created_at || '',
          modified_at: data.data.modified_at || 'N/A'
        };

        // Small delay to show success message before opening modal
        setTimeout(() => {
          window.openProductDetailModal(productData);
        }, 500);
      } else {
        // This shouldn't happen with proper API responses, but handle it
        showScanResult(`Product not found: "${normalizedBarcode}"`, 'error');
      }
    } catch (error) {
      console.error('Error fetching product by barcode:', error);
      showScanResult('Error connecting to server. Please try again.', 'error');
    }
  }

  // Helper function to show scan results
  function showScanResult(message, type = 'info', duration = 5000) {
    // Remove any existing scan result messages
    const existingResults = document.querySelectorAll('.scan-result-message');
    existingResults.forEach(el => el.remove());

    const resultDiv = document.createElement('div');
    resultDiv.className = `scan-result-message flash ${type}`;
    let displayMessage = message;

    // Friendly, non-intrusive styling
    const baseStyles = `
      position: fixed;
      top: 80px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 10000;
      padding: 15px 25px;
      border-radius: 8px;
      white-space: pre-line;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      font-size: 14px;
      line-height: 1.5;
      font-weight: normal;
      animation: slideDown 0.3s ease-out;
    `;

    resultDiv.style.cssText = baseStyles;

    // Add appropriate styling based on type
    if (type === 'success') {
      resultDiv.style.backgroundColor = '#4CAF50';
      resultDiv.style.color = 'white';
      resultDiv.style.border = '2px solid #2E7D32';
    } else if (type === 'error') {
      resultDiv.style.backgroundColor = '#FFF3E0';
      resultDiv.style.color = '#E65100';
      resultDiv.style.border = '1px solid #FFB74D';
      // Add a friendly info icon instead of warning
      displayMessage = `ℹ️ ${displayMessage}`;
      // Reasonable duration for reading
      duration = Math.max(duration, 5000);
    } else {
      resultDiv.style.backgroundColor = '#2196F3';
      resultDiv.style.color = 'white';
      resultDiv.style.border = '2px solid #1565C0';
    }

    // Add animation keyframes if not already present
    if (!document.getElementById('scanResultAnimations')) {
      const style = document.createElement('style');
      style.id = 'scanResultAnimations';
      style.innerHTML = `
        @keyframes slideDown {
          from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
          }
          to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
          }
        }
      `;
      document.head.appendChild(style);
    }

    // No overlay - keep it friendly and non-intrusive

    resultDiv.textContent = displayMessage;

    document.body.appendChild(resultDiv);

    // Auto-remove after duration
    setTimeout(() => {
      resultDiv.style.transition = 'all 0.5s ease-out';
      resultDiv.style.opacity = '0';
      resultDiv.style.transform = 'translateX(-50%) translateY(-20px)';

      setTimeout(() => {
        resultDiv.remove();
      }, 500);
    }, duration);

    // Allow clicking to dismiss any message type for convenience
    resultDiv.style.cursor = 'pointer';
    resultDiv.addEventListener('click', () => {
      resultDiv.style.transition = 'all 0.3s ease-out';
      resultDiv.style.opacity = '0';
      resultDiv.style.transform = 'translateX(-50%) translateY(-20px)';
      setTimeout(() => resultDiv.remove(), 300);
    });
  }
</script>

<?php require __DIR__ . '/footer.php'; ?>
