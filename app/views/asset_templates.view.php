<?php
$snipe_error = $snipe_error ?? null;
$products = $products ?? [];
$models = $models ?? [];
$statuses = $statuses ?? [];
require __DIR__ . '/header.php';

$configuredCount = 0;
$templateData = [];
foreach ($products as $p) {
    $configured = !empty($p['template_name']);
    if ($configured) {
        $configuredCount++;
    }
    $templateData[(int)$p['id']] = [
        'name' => (string)$p['name'],
        'sku' => (string)$p['sku'],
        'configured' => $configured,
        'template_name' => (string)($p['template_name'] ?? ''),
        'model_id' => (int)($p['model_id'] ?? 0),
        'status_id' => (int)($p['status_id'] ?? 0),
        'asset_name_template' => (string)($p['asset_name_template'] ?? ''),
        'asset_tag_prefix' => (string)($p['asset_tag_prefix'] ?? ''),
        'default_notes' => (string)($p['default_notes'] ?? ''),
        'is_active' => $configured ? !empty($p['is_active']) : true,
    ];
}
$productCount = count($products);
$canSave = $models && $statuses;
?>
<div class="stock-page asset-templates-page">

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Snipe-IT Asset Templates</h1>
        <p class="stock-masthead-sub">Pick a product to set its Snipe-IT defaults</p>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <a href="/asset-review" class="masthead-tab">Review Queue</a>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="page-stat-rail stock-stats asset-templates-stats">
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $productCount ?></span>
      <span class="page-stat-label stock-stat-label">Tracked products</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $configuredCount ?></span>
      <span class="page-stat-label stock-stat-label">Configured</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $productCount - $configuredCount ?></span>
      <span class="page-stat-label stock-stat-label">Not configured</span>
    </div>
  </div>

  <div class="asset-body">
    <?php foreach (flashes() as [$type, $message]) : ?>
      <div class="flash <?= h($type) ?>"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php if ($snipe_error) : ?>
      <div class="asset-banner" role="alert"><?= h($snipe_error) ?></div>
    <?php endif; ?>

    <?php if (!$products) : ?>
      <div class="orders-empty">
        <svg class="orders-empty-icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
          <rect x="8" y="10" width="32" height="34" rx="3" stroke="currentColor" stroke-width="2.5" fill="none"/>
          <path d="M16 10V8a8 8 0 0 1 16 0v2" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
          <path d="M16 22h16M16 30h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <h2 class="orders-empty-title">No tracked products</h2>
        <p class="orders-empty-text">Mark a product as a tracked asset before creating a Snipe-IT template.</p>
      </div>
    <?php else : ?>
      <div class="asset-toolbar">
        <input type="search" id="assetSearch" class="asset-search" placeholder="Search products by name or SKU" aria-label="Search products" autocomplete="off">
        <div class="asset-chips" role="group" aria-label="Filter products">
          <button type="button" class="asset-chip is-active" data-filter="all" aria-pressed="true">All <span><?= $productCount ?></span></button>
          <button type="button" class="asset-chip" data-filter="todo" aria-pressed="false">Not configured <span><?= $productCount - $configuredCount ?></span></button>
          <button type="button" class="asset-chip" data-filter="done" aria-pressed="false">Configured <span><?= $configuredCount ?></span></button>
        </div>
      </div>

      <div class="asset-grid" id="assetGrid">
        <?php foreach ($products as $product) : ?>
            <?php $configured = !empty($product['template_name']); ?>
          <button type="button" class="asset-tile" title="<?= h($product['name']) ?>" data-product-id="<?= (int)$product['id'] ?>"
                  data-search="<?= h(strtolower($product['name'] . ' ' . $product['sku'])) ?>"
                  data-configured="<?= $configured ? '1' : '0' ?>">
            <?php if (!empty($product['image_path'])) : ?>
              <img class="asset-tile-img" src="<?= h(image_url($product['image_path'])) ?>" alt="" width="200" height="160" loading="lazy">
            <?php else : ?>
              <span class="asset-tile-img asset-tile-img--empty">No image</span>
            <?php endif; ?>
            <span class="asset-tile-body">
              <span class="asset-tile-name"><?= h($product['name']) ?></span>
              <span class="asset-tile-sku"><?= h($product['sku']) ?></span>
              <span class="qt-status-badge qt-status-badge-<?= $configured ? 'received' : 'partial' ?>"><?= $configured ? 'Configured' : 'Not configured' ?></span>
            </span>
          </button>
        <?php endforeach; ?>
      </div>
      <p class="asset-no-results" id="assetNoResults" hidden>No products match your search.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($products) : ?>
<!-- Edit pop-out (one form, filled for whichever product was clicked) -->
<div id="assetTemplateModal" class="orders-modal asset-modal" hidden role="dialog" aria-modal="true" aria-labelledby="assetModalTitle">
  <div class="orders-modal-backdrop"></div>
  <div class="orders-modal-box">
    <div class="orders-modal-header">
      <div class="receiving-modal-heading">
        <h2 class="orders-modal-title" id="assetModalTitle">Asset template</h2>
        <span class="qt-status-badge" id="assetModalBadge"></span>
      </div>
      <button type="button" class="orders-modal-close" id="assetModalClose" aria-label="Close">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="18" height="18">
          <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
        </svg>
      </button>
    </div>
    <form method="post" action="/asset-templates" class="asset-modal-form" id="assetTemplateForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="product_id" id="assetProductId" value="">
      <div class="orders-modal-body">
        <p class="asset-modal-product" id="assetModalProduct"></p>
        <div class="asset-form">
          <label>Template name<input name="template_name" id="assetTemplateName" required maxlength="120"></label>
          <label>Model<select name="model_id" id="assetModelId" required <?= !$models ? 'disabled' : '' ?>><option value="">Select model…</option><?php foreach ($models as $model) :
                ?><option value="<?= (int)($model['id'] ?? 0) ?>"><?= h($model['name'] ?? 'Unnamed model') ?></option><?php
                                                                         endforeach; ?></select></label>
          <label>Status<select name="status_id" id="assetStatusId" required <?= !$statuses ? 'disabled' : '' ?>><option value="">Select status…</option><?php foreach ($statuses as $status) :
                ?><option value="<?= (int)($status['id'] ?? 0) ?>"><?= h($status['name'] ?? 'Unnamed status') ?></option><?php
                                                                            endforeach; ?></select></label>
          <label>Asset tag prefix<input name="asset_tag_prefix" id="assetTagPrefix" maxlength="100" placeholder="Example: VAN-"></label>
          <label class="asset-form-wide">Asset name pattern<input name="asset_name_template" id="assetNamePattern" maxlength="255"><small>Use <code>{{product_name}}</code> and <code>{{serial}}</code> as placeholders.</small></label>
          <label class="asset-form-wide">Default notes<textarea name="default_notes" id="assetNotes" maxlength="500" placeholder="Optional notes added to created assets"></textarea></label>
          <label class="asset-form-check asset-form-wide"><input type="checkbox" name="is_active" id="assetActive" value="1"> Use this template during review</label>
        </div>
      </div>
      <div class="receiving-modal-footer">
        <button class="orders-btn-primary asset-save-btn" type="submit" <?= !$canSave ? 'disabled' : '' ?>>Save template</button>
        <button class="orders-btn-secondary asset-remove-btn" type="submit" name="action" value="delete" id="assetRemoveBtn" formnovalidate hidden>Remove template</button>
        <button class="orders-btn-secondary asset-cancel-btn" type="button" id="assetModalCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="assetTemplateData"><?= json_encode($templateData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
