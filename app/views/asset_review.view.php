<?php
$reviews = $reviews ?? [];
$snipe_error = $snipe_error ?? null;
$models = $models ?? [];
$statuses = $statuses ?? [];
require __DIR__ . '/header.php';

// One tile per product; the pending scans for that product open in a pop-out.
$groups = [];
$withTemplate = 0;
foreach ($reviews as $r) {
    $pid = (int)$r['product_id'];
    if (!isset($groups[$pid])) {
        $groups[$pid] = ['name' => $r['product_name'], 'sku' => $r['sku'], 'image' => $r['image_path'] ?? '', 'has_template' => !empty($r['template_name']), 'oldest' => $r['created_at'], 'items' => []];
    }
    $groups[$pid]['items'][] = $r;
    if (!empty($r['template_name'])) {
        $withTemplate++;
    }
}
$pendingCount = count($reviews);
?>
<div class="stock-page asset-review-page">

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Tracked Asset Review</h1>
        <p class="stock-masthead-sub">Scans Snipe-IT couldn’t identify</p>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <a href="/asset-templates" class="masthead-tab">Asset Templates</a>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <div class="page-stat-rail stock-stats asset-review-stats">
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value" id="assetPendingCount"><?= $pendingCount ?></span>
      <span class="page-stat-label stock-stat-label">Pending review</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value" id="assetProductCount"><?= count($groups) ?></span>
      <span class="page-stat-label stock-stat-label">Products affected</span>
    </div>
    <div class="page-stat stock-stat">
      <span class="page-stat-value stock-stat-value"><?= $withTemplate ?></span>
      <span class="page-stat-label stock-stat-label">With a template</span>
    </div>
  </div>

  <div class="asset-body">
    <?php if ($snipe_error) : ?>
      <div class="asset-banner" role="alert"><?= h($snipe_error) ?></div>
    <?php endif; ?>

    <div class="orders-empty" id="assetReviewEmpty" <?= $groups ? 'hidden' : '' ?>>
      <svg class="orders-empty-icon" viewBox="0 0 48 48" fill="none" aria-hidden="true">
        <rect x="8" y="10" width="32" height="34" rx="3" stroke="currentColor" stroke-width="2.5" fill="none"/>
        <path d="M16 10V8a8 8 0 0 1 16 0v2" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
        <path d="M16 28l6 6 10-12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <h2 class="orders-empty-title">Queue is clear</h2>
      <p class="orders-empty-text">No unmatched tracked-asset scans need review.</p>
    </div>

    <div class="asset-grid" id="assetGrid">
      <?php foreach ($groups as $pid => $group) : ?>
        <button type="button" class="asset-tile" title="<?= h($group['name']) ?>" data-product-id="<?= (int)$pid ?>" data-name="<?= h($group['name']) ?>">
          <span class="asset-tile-count" data-count><?= count($group['items']) ?> pending</span>
            <?php if (!empty($group['image'])) : ?>
            <img class="asset-tile-img" src="<?= h(image_url($group['image'])) ?>" alt="" width="200" height="160" loading="lazy">
            <?php else : ?>
            <span class="asset-tile-img asset-tile-img--empty">No image</span>
            <?php endif; ?>
          <span class="asset-tile-body">
            <span class="asset-tile-name"><?= h($group['name']) ?></span>
            <span class="asset-tile-sku"><?= h($group['sku']) ?></span>
            <span class="qt-status-badge qt-status-badge-<?= $group['has_template'] ? 'received' : 'partial' ?>"><?= $group['has_template'] ? 'Template ready' : 'No template' ?></span>
          </span>
        </button>
      <?php endforeach; ?>
    </div>

    <!-- The pending scans for each product. Hidden here; the pop-out shows one product's at a time. -->
    <div hidden id="assetReviewGroups">
      <?php foreach ($groups as $pid => $group) : ?>
        <div class="asset-review-group" data-product-id="<?= (int)$pid ?>">
            <?php foreach ($group['items'] as $review) : ?>
                <?php
                $name_default = (string)($review['asset_name_template'] ?? '');
                $name_default = str_replace(['{{product_name}}', '{{serial}}'], [$review['product_name'], $review['identifier']], $name_default);
                $name_default = trim($name_default) ?: $review['product_name'];
                $tag_default = trim((string)($review['asset_tag_prefix'] ?? '') . $review['identifier']);
                ?>
            <article class="order-card asset-review-card" data-review-id="<?= (int)$review['id'] ?>">

              <div class="order-card-header asset-scan-header">
                <div class="order-card-identity">
                  <span class="asset-scan-label">Scan</span>
                  <span class="asset-scan-serial"><?= h($review['identifier']) ?></span>
                  <span class="order-date">Order #<?= (int)$review['order_id'] ?> · <?= h($review['submitted_by']) ?> · <?= h(date('M j, Y g:i A', strtotime($review['created_at']))) ?></span>
                </div>
                <span class="qt-status-badge qt-status-badge-partial">Pending</span>
              </div>

                <?php if (!empty($review['template_name'])) : ?>
                <p class="asset-template-note"><strong>Template:</strong> <?= h($review['template_name']) ?> <span>Defaults loaded; verify before creating.</span></p>
                <?php endif; ?>

              <form class="asset-review-form">
                <?= csrf_field() ?>
                <input type="hidden" name="review_id" value="<?= (int)$review['id'] ?>">
                <div class="asset-form">
                  <label>Asset name<input name="name" required maxlength="255" value="<?= h($name_default) ?>"></label>
                  <label>Asset tag<input name="asset_tag" required maxlength="255" placeholder="Snipe-IT asset tag" value="<?= h($tag_default) ?>"></label>
                  <label>Serial number<input name="serial" maxlength="255" value="<?= h($review['identifier']) ?>"></label>
                  <label>Model<select name="model_id" required <?= !$models ? 'disabled' : '' ?>><option value="">Select model…</option><?php foreach ($models as $model) :
                        ?><option value="<?= (int)($model['id'] ?? 0) ?>" <?= (int)($review['template_model_id'] ?? 0) === (int)($model['id'] ?? 0) ? 'selected' : '' ?>><?= h($model['name'] ?? 'Unnamed model') ?></option><?php
                                                               endforeach; ?></select></label>
                  <label>Status<select name="status_id" required <?= !$statuses ? 'disabled' : '' ?>><option value="">Select status…</option><?php foreach ($statuses as $status) :
                        ?><option value="<?= (int)($status['id'] ?? 0) ?>" <?= (int)($review['template_status_id'] ?? 0) === (int)($status['id'] ?? 0) ? 'selected' : '' ?>><?= h($status['name'] ?? 'Unnamed status') ?></option><?php
                                                                 endforeach; ?></select></label>
                  <label class="asset-form-wide">Review note<textarea name="review_note" maxlength="500" placeholder="Optional context for the asset record"><?= h($review['default_notes'] ?? '') ?></textarea></label>
                </div>
                <div class="asset-link-box">
                  <label>Already in Snipe-IT? Link it instead
                    <span class="asset-link-row">
                      <input name="existing_identifier" maxlength="255" placeholder="Existing serial number or asset tag">
                      <button type="button" class="orders-btn-secondary review-link">Link existing</button>
                    </span>
                  </label>
                </div>
                <div class="order-card-footer asset-card-footer asset-scan-actions">
                  <button type="button" class="orders-btn-secondary review-dismiss">Dismiss scan</button>
                  <div class="asset-message asset-review-message" role="status" aria-live="polite"></div>
                  <button type="submit" class="orders-btn-primary" <?= (!$models || !$statuses) ? 'disabled' : '' ?>>Create in Snipe-IT</button>
                </div>
              </form>

            </article>
            <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Product pop-out -->
<div id="assetReviewModal" class="orders-modal asset-modal asset-review-modal" hidden role="dialog" aria-modal="true" aria-labelledby="assetReviewModalTitle">
  <div class="orders-modal-backdrop"></div>
  <div class="orders-modal-box">
    <div class="orders-modal-header">
      <div class="receiving-modal-heading">
        <h2 class="orders-modal-title" id="assetReviewModalTitle">Review</h2>
        <span class="qt-status-badge qt-status-badge-partial" id="assetReviewModalCount"></span>
      </div>
      <button type="button" class="orders-modal-close" id="assetReviewModalClose" aria-label="Close">
        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" width="18" height="18">
          <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
        </svg>
      </button>
    </div>
    <div class="orders-modal-body" id="assetReviewModalBody"></div>
    <div class="receiving-modal-footer">
      <button type="button" class="orders-btn-primary" id="assetReviewModalDone">Done</button>
    </div>
  </div>
</div>

<dialog class="asset-dialog" id="assetDismissDialog" aria-labelledby="assetDismissTitle">
  <form method="dialog" class="asset-dialog-body">
    <h2 id="assetDismissTitle">Dismiss this scan?</h2>
    <p>Tell us why this scan isn’t an inventory asset. It will be removed from the queue.</p>
    <textarea id="assetDismissNote" maxlength="500" aria-label="Reason for dismissing">Not an inventory asset</textarea>
    <div class="asset-dialog-actions">
      <button class="orders-btn-secondary" value="cancel" type="submit">Cancel</button>
      <button class="orders-btn-primary" value="confirm" type="submit">Dismiss scan</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/footer.php'; ?>
