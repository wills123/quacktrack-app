<?php
$title       = 'Van Stock';
$main_class  = 'main-content';
$extra_styles = ['/assets/opdash.css', '/assets/add-item-modal.css'];
$summary = $summary ?? ['total_skus' => 0];
$category_id = $category_id ?? 0;
$location_id = $location_id ?? 0;
$search = $search ?? '';
$status = $status ?? '';
$viewing_other_user = $viewing_other_user ?? false;
$view_user = $view_user ?? [];
$display_products = $display_products ?? [];
$tracked_assets = $tracked_assets ?? [];
$location_options = $location_options ?? [];
$sort = $sort ?? 'name';
$query_params = $query_params ?? [];
$low_stock_threshold = $low_stock_threshold ?? 0;
$tracked_assets_error = $tracked_assets_error ?? null;
$snipe_base_url = $snipe_base_url ?? '';
$all_stores = $all_stores ?? [];
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
<?php

function build_stock_query(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    $filtered = [];
    foreach ($merged as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $filtered[$key] = $value;
    }
    return http_build_query($filtered);
}

$subtitle_parts = [];
if (!empty($view_user['display_name'])) {
    $subtitle_parts[] = 'Van for ' . $view_user['display_name'];
}
$subtitle_parts[] = number_format($summary['total_skus']) . ' SKUs';
if (!empty($last_updated_at)) {
    $subtitle_parts[] = 'Updated ' . date('M j, Y g:i A', strtotime($last_updated_at));
}

$has_active_filters = $search !== '' || $status !== 'all' || $location_id > 0 || $category_id > 0;
$empty_title = $has_active_filters ? 'No items found' : 'No items in van stock';
$empty_body  = $has_active_filters
    ? 'Try clearing filters or adjusting your search.'
    : 'Add items to start tracking inventory in this van.';

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
        <h1 class="stock-masthead-title">Van Stock</h1>
        <div class="stock-masthead-sub"><?= h(implode(' · ', array_filter($subtitle_parts))) ?></div>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <?php if (!$viewing_other_user) : ?>
          <button class="masthead-tab" id="openVanScannerHeader" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Scan
          </button>
          <button class="masthead-tab"
                  id="addNewItemBtn"
                  data-modal-target="addItemModal"
                  aria-label="Add new item to van stock"
                  type="button">
            + Add Item
          </button>
        <?php else : ?>
          <span class="masthead-note">Admin view — actions disabled</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($viewing_other_user) : ?>
    <div class="info-banner" style="margin: 12px 40px 0;">
      Viewing van stock for <?= h($view_user['display_name']) ?> (<?= h($view_user['email']) ?>).
    </div>
  <?php endif; ?>

  <!-- Stat strip -->
  <div class="page-stat-rail stock-stats">
    <div class="stock-stat">
      <div class="stock-stat-value"><?= number_format($summary['total_skus']) ?></div>
      <div class="stock-stat-label">Total SKUs</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value"><?= number_format($summary['total_units']) ?></div>
      <div class="stock-stat-label">Total Units</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value<?= $summary['low_stock'] > 0 ? ' warn' : '' ?>"><?= number_format($summary['low_stock']) ?></div>
      <div class="stock-stat-label">Low Stock</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value"><?= count($display_products) ?></div>
      <div class="stock-stat-label">Showing</div>
    </div>
    <div class="stock-stat stock-stat--asset">
      <div class="stock-stat-value"><?= number_format(count($tracked_assets)) ?></div>
      <div class="stock-stat-label">Tracked Assets</div>
    </div>
  </div>

  <!-- Two-panel body -->
  <form class="stock-layout" id="stockLayout" data-filters-open="true" method="get" action="/stock">
    <?php if ($viewing_other_user) : ?>
      <input type="hidden" name="van_user_id" value="<?= (int)$view_user['id'] ?>">
    <?php endif; ?>

    <!-- Filter sidebar -->
    <aside class="filters-sidebar" id="filtersSidebar">
      <div class="filters-header">
        <h2>Filters</h2>
        <a class="btn-link" href="/stock<?= $viewing_other_user ? '?' . build_stock_query(['van_user_id' => $view_user['id']]) : '' ?>">
          Clear
        </a>
      </div>

      <div class="filters-group">
        <label for="filterLocation">Location</label>
        <select id="filterLocation" name="location_id">
          <option value="">All locations</option>
          <?php foreach ($location_options as $location) : ?>
            <option value="<?= (int)$location['id'] ?>" <?= $location_id === (int)$location['id'] ? 'selected' : '' ?>>
                <?= h($location['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if (!empty($category_options)) : ?>
        <div class="filters-group">
          <label for="filterCategory">Category</label>
          <select id="filterCategory" name="category_id">
            <option value="">All categories</option>
            <?php foreach ($category_options as $category) : ?>
              <option value="<?= (int)$category['id'] ?>" <?= $category_id === (int)$category['id'] ? 'selected' : '' ?>>
                <?= h($category['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="filters-group">
        <label for="filterStatus">Status</label>
        <select id="filterStatus" name="status">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
          <option value="in" <?= $status === 'in' ? 'selected' : '' ?>>In stock</option>
          <option value="low" <?= $status === 'low' ? 'selected' : '' ?>>Low</option>
        </select>
      </div>

      <div class="filters-actions">
        <button class="od-btn od-btn--primary" type="submit" style="width:100%;">Apply</button>
        <a class="od-btn od-btn--secondary" href="/stock<?= $viewing_other_user ? '?' . build_stock_query(['van_user_id' => $view_user['id']]) : '' ?>" style="width:100%;text-align:center;">Clear all</a>
      </div>
    </aside>

    <div class="filters-backdrop" id="filtersBackdrop"></div>

    <!-- Main content -->
    <section class="stock-main">
      <div class="stock-toolbar">
        <button type="button" class="od-btn od-btn--secondary" id="filtersToggle">Filters</button>
        <div class="stock-search">
          <label for="stockSearch" class="sr-only">Search van stock</label>
          <input type="search" id="stockSearch" name="search" placeholder="Search van stock…" value="<?= h($search) ?>">
        </div>
        <div class="stock-sort">
          <label for="stockSort" class="sr-only">Sort</label>
          <select id="stockSort" name="sort">
            <option value="name"    <?= $sort === 'name'    ? 'selected' : '' ?>>Name</option>
            <option value="qty"     <?= $sort === 'qty'     ? 'selected' : '' ?>>Quantity</option>
            <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Recently updated</option>
          </select>
        </div>
      </div>

      <div class="stock-chips">
        <a class="chip <?= $status === 'all' ? 'is-active' : '' ?>" href="/stock?<?= build_stock_query($query_params, ['status' => 'all']) ?>">All</a>
        <a class="chip <?= $status === 'in'  ? 'is-active' : '' ?>" href="/stock?<?= build_stock_query($query_params, ['status' => 'in'])  ?>">In stock</a>
        <a class="chip <?= $status === 'low' ? 'is-active' : '' ?>" href="/stock?<?= build_stock_query($query_params, ['status' => 'low']) ?>">Low stock</a>
      </div>

      <!-- Stock cards -->
      <div class="stock-cards">
        <?php if (empty($display_products)) : ?>
          <div class="stock-empty">
            <div class="stock-empty-icon">📦</div>
            <div class="stock-empty-title"><?= h($empty_title) ?></div>
            <div class="stock-empty-body"><?= h($empty_body) ?></div>
            <?php if (!$viewing_other_user && !$has_active_filters) : ?>
              <button class="od-btn od-btn--primary" type="button" id="emptyStateAddItem" data-modal-target="addItemModal">
                Add New Item
              </button>
            <?php endif; ?>
          </div>
        <?php else : ?>
            <?php foreach ($display_products as $data) :
                $prod_id   = (int)$data['product_details']['id'];
                $total_qty = (int)$data['total_qty'];

                if ($total_qty <= 0) {
                    $badge_label  = 'Out';
                    $badge_class  = 'stock-badge--out';
                    $data_status  = 'out';
                } elseif ($total_qty <= $low_stock_threshold) {
                    $badge_label  = 'Low';
                    $badge_class  = 'stock-badge--low';
                    $data_status  = 'low';
                } else {
                    $badge_label  = 'In stock';
                    $badge_class  = 'stock-badge--in';
                    $data_status  = 'in';
                }

                $loc_count  = count($data['stores']);
                $loc_label  = $loc_count . ' Location' . ($loc_count !== 1 ? 's' : '');
                ?>
          <div class="stock-card" data-status="<?= $data_status ?>" data-prod-id="<?= $prod_id ?>">
            <div class="stock-card-main">

              <!-- Thumbnail -->
                <?php if (!empty($data['product_details']['image_path'])) : ?>
                <img src="<?= h(image_url($data['product_details']['image_path'])) ?>"
                     alt=""
                     class="stock-thumb"
                     loading="lazy">
                <?php else : ?>
                <div class="stock-thumb-placeholder" aria-hidden="true">
                    <?= strtoupper(substr($data['product_details']['name'], 0, 2)) ?>
                </div>
                <?php endif; ?>

              <!-- Info column -->
              <div class="stock-card-info">
                <div class="stock-card-name-row">
                  <span class="stock-card-name"><?= h($data['product_details']['name']) ?></span>
                  <span class="stock-badge <?= h($badge_class) ?>"><?= h($badge_label) ?></span>
                </div>
                <?php if (!empty($data['product_details']['product_id'])) : ?>
                  <div class="stock-sku"><?= h($data['product_details']['product_id']) ?></div>
                <?php endif; ?>
                <div class="stock-meta">
                  <?php if (!empty($data['product_details']['category_name'])) : ?>
                    <span><?= h($data['product_details']['category_name']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($data['updated_at'])) : ?>
                    <span>Updated <?= h(date('M j', strtotime($data['updated_at']))) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Qty display -->
              <div class="stock-qty">
                <div class="stock-qty-label">Qty</div>
                <div class="stock-qty-value"><?= number_format($total_qty) ?></div>
              </div>

              <!-- Actions -->
              <div class="stock-card-actions">
                <button class="add-btn quick-action-btn"
                        data-prod-id="<?= $prod_id ?>"
                        data-prod-name="<?= h($data['product_details']['name']) ?>"
                        data-action="add"
                        data-modal-target="adjustmentModal"
                        aria-label="Add stock for <?= h($data['product_details']['name']) ?>"
                        type="button"
                        <?= $viewing_other_user ? 'disabled' : '' ?>>
                  + Add
                </button>
                <button class="use-btn quick-action-btn"
                        data-prod-id="<?= $prod_id ?>"
                        data-prod-name="<?= h($data['product_details']['name']) ?>"
                        data-action="use"
                        data-modal-target="adjustmentModal"
                        aria-label="Use stock for <?= h($data['product_details']['name']) ?>"
                        type="button"
                        <?= $viewing_other_user ? 'disabled' : '' ?>>
                  − Use
                </button>
                <button class="details-btn"
                        data-prod-id="<?= $prod_id ?>"
                        data-prod-name="<?= h($data['product_details']['name']) ?>"
                        <?= $viewing_other_user ? 'data-van-user-id="' . (int)$view_user['id'] . '"' : '' ?>
                        aria-label="View item details for <?= h($data['product_details']['name']) ?>"
                        type="button">
                  Details
                </button>
              </div>

            </div><!-- /stock-card-main -->

            <!-- Distribution expand -->
            <div class="stock-card-distribution">
              <button class="toggle-breakdown"
                      type="button"
                      aria-expanded="false"
                      aria-controls="breakdown-<?= $prod_id ?>"
                      aria-label="Show location breakdown for <?= h($data['product_details']['name']) ?>">
                <?= h($loc_label) ?>
              </button>
              <div id="breakdown-<?= $prod_id ?>" class="breakdown-content" style="display:none;" aria-hidden="true">
                <div class="breakdown-table-container">
                  <table class="store-allocation-table">
                    <thead>
                      <tr>
                        <th>Location</th>
                        <th>Quantity</th>
                        <th>Transfer</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($data['stores'] as $store_data) :
                            $sq      = (int)$store_data['qty'];
                            $isEmpty = $sq === 0;
                            $qtyText = number_format($sq) . ' item' . ($sq !== 1 ? 's' : '');
                            ?>
                      <tr<?= $isEmpty ? ' class="row-empty"' : '' ?>>
                        <td><?= h($store_data['store_name']) ?></td>
                        <td class="<?= $isEmpty ? 'quantity-empty' : '' ?>"><?= $qtyText ?></td>
                        <td>
                          <button class="btn btn-sm btn-primary transfer-btn"
                                  type="button"
                                  <?= ($isEmpty || $viewing_other_user) ? 'disabled' : '' ?>
                                  data-prod-id="<?= $prod_id ?>"
                                  data-source-store-id="<?= (int)$store_data['store_id'] ?>"
                                  data-source-store-name="<?= h($store_data['store_name']) ?>"
                                  data-available-qty="<?= $sq ?>"
                                  data-prod-name="<?= h($data['product_details']['name']) ?>"
                                  title="<?= $isEmpty ? 'No stock to transfer' : 'Transfer from ' . h($store_data['store_name']) ?>"
                                  aria-label="<?= $isEmpty ? 'Transfer disabled' : 'Transfer from ' . h($store_data['store_name']) ?>">
                            Transfer
                          </button>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

          </div><!-- /stock-card -->
            <?php endforeach; ?>
        <?php endif; ?>
      </div><!-- /stock-cards -->

      <section class="tracked-assets-panel" aria-labelledby="tracked-assets-title">
        <div class="tracked-assets-heading">
          <div>
            <div class="stock-masthead-eyebrow">🔐 Snipe-IT</div>
            <h2 id="tracked-assets-title">Tracked equipment</h2>
            <p>Serialized equipment assigned to this van owner. Manage quantities and check-in/check-out from Snipe-IT.</p>
          </div>
          <a class="od-btn od-btn--secondary" href="/assets">Open Assets</a>
        </div>

        <?php if ($tracked_assets_error) : ?>
          <div class="info-banner tracked-assets-message"><?= h($tracked_assets_error) ?></div>
        <?php elseif (empty($tracked_assets)) : ?>
          <div class="tracked-assets-empty">No Snipe-IT assets are currently assigned to this van owner.</div>
        <?php else : ?>
          <div class="tracked-assets-list">
            <?php foreach ($tracked_assets as $asset) :
                $asset_id = (int)($asset['id'] ?? 0);
                $asset_name = trim((string)($asset['name'] ?? '')) ?: 'Unnamed asset';
                $asset_status = trim((string)($asset['status_label']['name'] ?? '')) ?: 'Unknown status';
                $asset_status_type = strtolower((string)($asset['status_label']['status_meta'] ?? $asset['status_label']['status_type'] ?? ''));
                $asset_status_class = $asset_status_type === 'deployed' ? 'is-deployed' : 'is-available';
                $asset_model = trim((string)($asset['model']['name'] ?? '')) ?: 'Model not provided';
                $asset_location = trim((string)($asset['location']['name'] ?? '')) ?: 'Location not provided';
                ?>
              <article class="tracked-asset-card">
                <div class="tracked-asset-main">
                  <div class="tracked-asset-icon" aria-hidden="true">▣</div>
                  <div class="tracked-asset-info">
                    <div class="stock-card-name-row">
                      <span class="stock-card-name"><?= h($asset_name) ?></span>
                      <span class="tracked-asset-badge <?= h($asset_status_class) ?>"><?= h($asset_status) ?></span>
                    </div>
                    <div class="tracked-asset-identifiers">
                      <?php if (!empty($asset['asset_tag'])) : ?>
                        <span>Tag: <?= h($asset['asset_tag']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($asset['serial'])) : ?>
                        <span>Serial: <?= h($asset['serial']) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="stock-meta">
                      <span>Model: <?= h($asset_model) ?></span>
                      <span>Location: <?= h($asset_location) ?></span>
                    </div>
                  </div>
                  <?php if ($asset_id > 0) : ?>
                    <div class="tracked-asset-actions">
                      <a class="tracked-asset-link" href="/assets?asset_id=<?= $asset_id ?>">Manage in Quack Track</a>
                        <?php if ($snipe_base_url !== '') : ?>
                        <a class="tracked-asset-link tracked-asset-link--external" href="<?= h(rtrim($snipe_base_url, '/') . '/hardware/' . $asset_id) ?>" target="_blank" rel="noopener">Snipe-IT ↗</a>
                        <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <div class="stock-results">
        Showing <?= count($display_products) ?> result<?= count($display_products) === 1 ? '' : 's' ?>
      </div>
    </section>

  </form><!-- /stock-layout -->

</div><!-- /stock-page -->

<?php
// Adjustment Modal
$stores    = $all_stores;
$modal_id  = 'adjustmentModal';
$form_id   = 'adjustmentForm';
require __DIR__ . '/components/modals/adjustment-modal.php';

// Transfer Modal
$modal_id = 'transferModal';
$form_id  = 'transferForm';
require __DIR__ . '/components/modals/transfer-modal.php';

// Add Item Modal
$modal_id         = 'addItemModal';
$form_id          = 'addItemForm';
$product_display_id = 'vanProdDisplay';
$product_id_field   = 'selectedProductId';
$picker_btn_id      = 'openVanProductPickerBtn';
$scanner_btn_id     = 'openVanScannerBtnPrimary';
require __DIR__ . '/components/modals/add-item-modal.php';

// Product Picker Modal
$modal_id         = 'vanProductPickerModal';
$search_input_id  = 'vanPickerSearch';
$list_container_id = 'vanPickerList';
$close_btn_id     = 'vanPickerCloseBtn';
$modal_title      = 'Choose Product';
require __DIR__ . '/components/modals/product-picker-modal.php';

// Scanner Modal
$modal_id = 'vanScannerModal';
require __DIR__ . '/components/modals/scanner-modal.php';
?>

<script src="/assets/stock.js"></script>

<?php require __DIR__ . '/footer.php'; ?>
