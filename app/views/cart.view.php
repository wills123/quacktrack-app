<?php
$title      = 'Shopping Cart';
$main_class = 'main-content';
$stores = $stores ?? [];
$max_note_length = $max_note_length ?? 1000;
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

<?= csrf_field() ?>

<div class="stock-page cart-page">

  <!-- Masthead -->
  <div class="page-masthead stock-masthead">
    <div class="stock-masthead-inner">
      <div class="stock-masthead-brand">
        <h1 class="stock-masthead-title">Shopping Cart</h1>
        <div class="stock-masthead-sub">
          <?php if (empty($cart_items)) : ?>
            Your cart is empty
          <?php else : ?>
              <?= count($cart_items) ?> item<?= count($cart_items) !== 1 ? 's' : '' ?> ready to order
          <?php endif; ?>
        </div>
      </div>
      <div class="masthead-tabs stock-masthead-actions">
        <?php if (!empty($cart_items)) : ?>
          <button id="clearCartBtn" class="masthead-tab">Clear Cart</button>
        <?php endif; ?>
        <a href="/products" class="masthead-tab">Continue Shopping</a>
      </div>
    </div>
  </div>

  <!-- Stat strip -->
  <?php
    $total_items = count($cart_items);
    $total_qty   = array_sum(array_column($cart_items, 'quantity'));
    $unique_skus = count(array_unique(array_column($cart_items, 'product_id')));
    $has_notes   = count(array_filter($cart_items, fn($i) => !empty($i['notes'])));
    ?>
  <div class="stock-stats">
    <div class="stock-stat">
      <div class="stock-stat-value"><?= $total_items ?></div>
      <div class="stock-stat-label">Line Items</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value"><?= $total_qty ?></div>
      <div class="stock-stat-label">Total Units</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value"><?= $unique_skus ?></div>
      <div class="stock-stat-label">Unique Products</div>
    </div>
    <div class="stock-stat">
      <div class="stock-stat-value<?= $has_notes ? ' warn' : '' ?>"><?= $has_notes ?></div>
      <div class="stock-stat-label">With Notes</div>
    </div>
  </div>

  <!-- Body -->
  <?php if (empty($cart_items)) : ?>
    <div class="cart-empty-state">
      <div class="cart-empty-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
      </div>
      <h2 class="cart-empty-title">Your cart is empty</h2>
      <p class="cart-empty-body">Browse the <a href="/products">product catalog</a> to add items.</p>
    </div>

  <?php else : ?>
    <div class="cart-layout">

      <!-- Items column -->
      <section class="cart-items-col">
        <?php foreach ($cart_items as $item) : ?>
            <?php
            $quantity   = (int)($item['quantity'] ?? 0);
            $van_qty    = (int)($item['receive_to_van_qty'] ?? 0);
            $region_qty = (int)($item['receive_to_region_qty'] ?? 0);
            $has_region = !empty($_SESSION['region_id']);

            if ($quantity <= 0 || ($van_qty + $region_qty) !== $quantity) {
                $van_qty    = $quantity;
                $region_qty = 0;
            }
            if (!$has_region) {
                $van_qty    = $quantity;
                $region_qty = 0;
            }

            $receive_mode = 'van';
            if ($quantity > 0 && $region_qty === $quantity) {
                $receive_mode = 'region';
            } elseif ($quantity > 1 && $van_qty > 0 && $region_qty > 0) {
                $receive_mode = 'split';
            }
            ?>
          <div class="cart-item" data-cart-id="<?= $item['id'] ?>" data-product-sku="<?= h($item['sku']) ?>">

            <!-- Header: image + identity -->
            <div class="cart-item-header">
              <div class="cart-item-img">
                <?php if ($item['image_path']) : ?>
                  <img src="<?= h(image_url($item['image_path'])) ?>" alt="<?= h($item['name']) ?>">
                <?php else : ?>
                  <div class="cart-item-no-img">No Image</div>
                <?php endif; ?>
              </div>
              <div class="cart-item-identity">
                <h3 class="cart-item-name"><?= h($item['name']) ?></h3>
                <div class="cart-item-meta">
                  <span class="cart-item-sku">SKU: <?= h($item['sku']) ?></span>
                  <span class="cart-item-stock">Van: <?= (int)$item['van_stock'] ?></span>
                  <span class="cart-item-stock">Region: <?= (int)$item['region_stock'] ?></span>
                </div>
              </div>
            </div>

            <!-- Body: billing + receive destination -->
            <div class="cart-item-body">
              <div class="cart-field-group">
                <label class="cart-field-label" for="bill-to-<?= $item['id'] ?>">Bill To</label>
                <select id="bill-to-<?= $item['id'] ?>"
                        class="cart-select bill-to-store-select"
                        data-cart-id="<?= $item['id'] ?>"
                        aria-label="Bill to store for <?= h($item['name']) ?>">
                  <option value="">Same as Ship To</option>
                  <?php foreach ($stores as $store) : ?>
                    <option value="<?= $store['id'] ?>"
                        <?= ($item['bill_to_store_id'] ?? 0) == $store['id'] ? 'selected' : '' ?>>
                        <?= h($store['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($item['bill_to_store_id'])) : ?>
                  <small class="billing-indicator" aria-live="polite"><?= h($item['bill_to_store_name']) ?></small>
                <?php endif; ?>
              </div>

              <div class="cart-field-group">
                <label class="cart-field-label" for="receive-to-<?= $item['id'] ?>">Receive To</label>
                <select id="receive-to-<?= $item['id'] ?>"
                        class="cart-select receive-to-select"
                        data-cart-id="<?= $item['id'] ?>"
                        aria-label="Receive to destination for <?= h($item['name']) ?>">
                  <option value="van" <?= $receive_mode === 'van' ? 'selected' : '' ?>>Van stock</option>
                  <option value="region"
                    <?= !$has_region ? 'disabled' : '' ?>
                    <?= $receive_mode === 'region' ? 'selected' : '' ?>>
                    Region storage<?= $has_region && !empty($_SESSION['region']) ? ' (' . h($_SESSION['region']) . ')' : '' ?>
                  </option>
                  <option value="split"
                    <?= ($quantity <= 1 || !$has_region) ? 'disabled' : '' ?>
                    <?= $receive_mode === 'split' ? 'selected' : '' ?>>
                    Split quantities
                  </option>
                </select>

                <div class="receive-split-wrap" data-cart-id="<?= $item['id'] ?>" <?= $receive_mode === 'split' ? '' : 'hidden' ?>>
                  <div class="receive-split-row">
                    <label for="receive-van-<?= $item['id'] ?>">Van</label>
                    <input type="number" id="receive-van-<?= $item['id'] ?>"
                           class="receive-to-van-qty"
                           min="0" max="<?= $quantity ?>" value="<?= $van_qty ?>"
                           data-cart-id="<?= $item['id'] ?>"
                           aria-label="Van quantity for <?= h($item['name']) ?>">
                  </div>
                  <div class="receive-split-row">
                    <label for="receive-region-<?= $item['id'] ?>">Region</label>
                    <input type="number" id="receive-region-<?= $item['id'] ?>"
                           class="receive-to-region-qty"
                           min="0" max="<?= $quantity ?>" value="<?= $region_qty ?>"
                           data-cart-id="<?= $item['id'] ?>"
                           aria-label="Region quantity for <?= h($item['name']) ?>">
                  </div>
                </div>
                <small class="receive-split-help" <?= $receive_mode !== 'split' ? 'hidden' : '' ?>>
                  Split quantities must sum to the item total.
                </small>
              </div>
            </div>

            <!-- Footer: qty, notes, remove -->
            <div class="cart-item-footer">
              <label class="cart-footer-field">
                <span class="cart-footer-label">Qty</span>
                <input type="number" class="quantity-input cart-qty-input"
                       value="<?= $item['quantity'] ?>" min="1"
                       data-cart-id="<?= $item['id'] ?>"
                       aria-label="Quantity for <?= h($item['name']) ?>">
              </label>

              <label class="cart-footer-field cart-footer-notes">
                <span class="cart-footer-label">Notes</span>
                <input type="text" class="notes-input"
                       value="<?= h($item['notes']) ?>"
                       maxlength="<?= (int)$max_note_length ?>"
                       placeholder="Optional note…"
                       data-cart-id="<?= $item['id'] ?>"
                       aria-label="Notes for <?= h($item['name']) ?>">
              </label>

              <button class="remove-item cart-remove-btn"
                      data-cart-id="<?= $item['id'] ?>"
                      aria-label="Remove <?= h($item['name']) ?> from cart">
                Remove
              </button>
            </div>

          </div>
        <?php endforeach; ?>
      </section>

      <!-- Order summary sidebar -->
      <aside class="cart-summary-col">
        <div class="cart-summary-card">
          <div class="cart-summary-header">
            <h2 class="cart-summary-title">Order Summary</h2>
            <span class="cart-summary-count" id="totalItems"><?= count($cart_items) ?> items</span>
          </div>

          <!-- Shipping destination -->
          <div class="cart-summary-section">
            <label class="cart-summary-label" for="shipToStoreSelect">
              Ship To Store <span class="cart-required">*</span>
            </label>
            <select id="shipToStoreSelect" class="cart-select" required aria-describedby="shipToHelp">
              <option value="">Select destination…</option>
              <?php foreach ($stores as $store) : ?>
                <option value="<?= $store['id'] ?>"><?= h($store['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <small id="shipToHelp" class="cart-field-help">All items ship to this location</small>
          </div>

          <!-- Purchasing email -->
          <div class="cart-summary-section">
            <label class="cart-summary-label" for="purchasingEmailSelect">
              Send Order To <span class="cart-required">*</span>
            </label>
            <select id="purchasingEmailSelect" class="cart-select" required aria-describedby="purchasingEmailHelp">
              <option value="">Loading…</option>
            </select>
            <small id="purchasingEmailHelp" class="cart-field-help">Purchasing department recipient</small>
          </div>

          <!-- Quick billing -->
          <div class="cart-summary-section">
            <label class="cart-summary-label" for="quickBillingSelect">Quick Billing</label>
            <select id="quickBillingSelect" class="cart-select" aria-label="Quick billing actions">
              <option value="">Select action…</option>
              <option value="bill-all-to-shipping">Bill All to Shipping Store</option>
              <optgroup label="Copy Billing From:">
                <?php foreach ($cart_items as $item) : ?>
                  <option value="copy-from-<?= $item['id'] ?>" data-bill-to-store="<?= $item['bill_to_store_id'] ?? '' ?>">
                    <?= h($item['name']) ?><?php if (!empty($item['bill_to_store_name'])) :
                        ?> &rarr; <?= h($item['bill_to_store_name']) ?><?php
                    endif; ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>

          <!-- Billing breakdown (shown when split billing) -->
          <div class="cart-billing-summary" id="billingSummarySection" hidden>
            <div class="cart-billing-summary-title">Billing Distribution</div>
            <div id="billingBreakdown" aria-live="polite"></div>
          </div>

          <!-- Order notes -->
          <div class="cart-summary-section">
            <label class="cart-summary-label" for="orderNotes">Order Notes</label>
            <textarea id="orderNotes"
                      class="cart-textarea"
                      maxlength="<?= (int)$max_note_length ?>"
                      placeholder="Special instructions for this order…"
                      aria-describedby="orderNotesHelp"></textarea>
            <small id="orderNotesHelp" class="cart-field-help">Optional, max <?= (int)$max_note_length ?> characters</small>
          </div>

          <!-- Primary action -->
          <button id="sendOrderBtn" class="cart-send-btn">Send Order</button>

          <!-- Template actions -->
          <div class="cart-template-actions">
            <button id="saveTemplateBtn" class="cart-template-btn">Save as Template</button>
            <button id="loadTemplateBtn" class="cart-template-btn">Load Template</button>
          </div>

        </div>
      </aside>

    </div><!-- /.cart-layout -->
  <?php endif; ?>

</div><!-- /.cart-page -->

<!-- Save Template Modal -->
<div id="saveTemplateModal" class="qt-modal" hidden>
  <div class="qt-modal-backdrop" onclick="document.getElementById('saveTemplateModal').hidden=true"></div>
  <div class="qt-modal-box" role="dialog" aria-labelledby="saveTemplateTitle">
    <div class="qt-modal-header">
      <h2 id="saveTemplateTitle" class="qt-modal-title">Save Order Template</h2>
      <button class="qt-modal-close" onclick="document.getElementById('saveTemplateModal').hidden=true" aria-label="Close">&times;</button>
    </div>
    <form id="saveTemplateForm" class="qt-modal-body">
      <div class="qt-form-row">
        <label for="templateName">Template Name</label>
        <input type="text" id="templateName" required placeholder="e.g., Standard Install Kit">
      </div>
      <div class="qt-form-row">
        <label for="templateDescription">Description</label>
        <textarea id="templateDescription" placeholder="Optional description"></textarea>
      </div>
      <label class="qt-checkbox-row">
        <input type="checkbox" id="templatePublic">
        <span>Make available to all users</span>
      </label>
      <div class="qt-modal-footer">
        <button type="submit" class="cart-send-btn">Save Template</button>
      </div>
    </form>
  </div>
</div>

<!-- Load Template Modal -->
<div id="loadTemplateModal" class="qt-modal" hidden>
  <div class="qt-modal-backdrop" onclick="document.getElementById('loadTemplateModal').hidden=true"></div>
  <div class="qt-modal-box" role="dialog" aria-labelledby="loadTemplateTitle">
    <div class="qt-modal-header">
      <h2 id="loadTemplateTitle" class="qt-modal-title">Load Order Template</h2>
      <button class="qt-modal-close" onclick="document.getElementById('loadTemplateModal').hidden=true" aria-label="Close">&times;</button>
    </div>
    <div class="qt-modal-body">
      <div id="templatesList"></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Input change handlers
    document.querySelectorAll('.quantity-input, .notes-input, .bill-to-store-select, .receive-to-select, .receive-to-van-qty, .receive-to-region-qty').forEach(input => {
        input.addEventListener('change', function() {
            const row = this.closest('.cart-item');
            if (!row) return;

            if (this.classList.contains('quantity-input'))          resetReceiveToDefaults(row);
            if (this.classList.contains('receive-to-select'))       updateReceiveToUI(row);
            if (this.classList.contains('receive-to-van-qty') ||
                this.classList.contains('receive-to-region-qty'))   syncSplitQuantities(row, this);
            if (this.classList.contains('bill-to-store-select'))    updateBillingIndicator(row, this.value);

            updateCartItem(this.dataset.cartId, this);
        });
    });

    document.getElementById('shipToStoreSelect')?.addEventListener('change', updateBillingSummary);

    document.getElementById('quickBillingSelect')?.addEventListener('change', function() {
        const value = this.value;
        if (!value) return;
        if (value === 'bill-all-to-shipping')       billAllToShippingStore();
        else if (value.startsWith('copy-from-'))    copyBillingFromItem(value.replace('copy-from-', ''));
        this.value = '';
    });

    document.querySelectorAll('.cart-item').forEach(row => updateReceiveToUI(row));

    if (document.getElementById('shipToStoreSelect')?.value) updateBillingSummary();

    // Remove item
    document.querySelectorAll('.remove-item').forEach(btn => {
        btn.addEventListener('click', function() {
            const cartId = this.dataset.cartId;
            QuackNotify.confirm('Remove this item from cart?', {
                title: 'Remove Item',
                confirmText: 'Remove',
                cancelText: 'Cancel',
                confirmStyle: 'danger'
            }).then(confirmed => { if (confirmed) removeCartItem(cartId); });
        });
    });

    // Clear cart
    document.getElementById('clearCartBtn')?.addEventListener('click', function() {
        QuackNotify.confirm('Clear entire cart?', {
            title: 'Clear Cart',
            confirmText: 'Clear All',
            cancelText: 'Cancel',
            confirmStyle: 'danger'
        }).then(confirmed => { if (confirmed) clearCart(); });
    });

    document.getElementById('sendOrderBtn')?.addEventListener('click', sendOrder);

    // Template modals
    document.getElementById('saveTemplateBtn')?.addEventListener('click', () => {
        document.getElementById('saveTemplateModal').hidden = false;
    });
    document.getElementById('loadTemplateBtn')?.addEventListener('click', () => {
        loadTemplates();
        document.getElementById('loadTemplateModal').hidden = false;
    });

    document.getElementById('saveTemplateForm')?.addEventListener('submit', saveTemplate);
});

async function updateCartItem(cartId, input) {
    const row = input.closest('.cart-item');
    const quantity = row.querySelector('.quantity-input').value;
    const notes = row.querySelector('.notes-input').value;
    const billToStoreId = row.querySelector('.bill-to-store-select')?.value || '';
    const receiveSplit = getReceiveSplitValues(row, Number(quantity));

    try {
        const response = await secureFetch('/api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildFormBody({
                action: 'update',
                cart_item_id: cartId,
                quantity,
                notes,
                bill_to_store_id: billToStoreId,
                receive_to_van_qty: receiveSplit.van,
                receive_to_region_qty: receiveSplit.region
            })
        });

        const data = await response.json();
        if (!data.success) {
            const err = new Error(data.error || 'Update failed');
            err.details = data.details || {};
            throw err;
        }

        if (quantity <= 0) {
            row.remove();
            if (typeof updateHeaderCartCount === 'function') updateHeaderCartCount();
        } else {
            updateBillingIndicator(row, billToStoreId);
        }

        updateBillingSummary();

    } catch (error) {
        QuackNotify.error(error.message);
    }
}

function getReceiveSplitValues(row, quantity) {
    const mode = row.querySelector('.receive-to-select')?.value || 'van';
    let vanQty = quantity, regionQty = 0;

    if (mode === 'region') {
        vanQty = 0; regionQty = quantity;
    } else if (mode === 'split') {
        const vanInput    = row.querySelector('.receive-to-van-qty');
        const regionInput = row.querySelector('.receive-to-region-qty');
        vanQty    = parseInt(vanInput?.value || '0', 10);
        regionQty = parseInt(regionInput?.value || '0', 10);
        if (!Number.isFinite(vanQty))    vanQty = 0;
        if (!Number.isFinite(regionQty)) regionQty = 0;
        if (vanQty + regionQty !== quantity) { vanQty = quantity; regionQty = 0; }
    }

    return { van: Math.max(0, vanQty), region: Math.max(0, regionQty) };
}

function resetReceiveToDefaults(row) {
    const select      = row.querySelector('.receive-to-select');
    const vanInput    = row.querySelector('.receive-to-van-qty');
    const regionInput = row.querySelector('.receive-to-region-qty');
    const quantity    = parseInt(row.querySelector('.quantity-input')?.value || '0', 10);

    if (select)      select.value = 'van';
    if (vanInput)    vanInput.value = Number.isFinite(quantity) ? quantity : 0;
    if (regionInput) regionInput.value = 0;

    updateReceiveToUI(row);
}

function updateReceiveToUI(row) {
    const quantity    = parseInt(row.querySelector('.quantity-input')?.value || '0', 10);
    const select      = row.querySelector('.receive-to-select');
    const splitWrap   = row.querySelector('.receive-split-wrap');
    const splitHelp   = row.querySelector('.receive-split-help');
    const splitOption = select?.querySelector('option[value="split"]');
    const hasRegion   = !(select?.querySelector('option[value="region"]')?.disabled);

    if (!hasRegion && select?.value === 'region') select.value = 'van';

    if (splitOption) {
        splitOption.disabled = !hasRegion || quantity <= 1;
        if (splitOption.disabled && select?.value === 'split') select.value = 'van';
    }

    if (splitWrap) {
        if (select?.value === 'split') {
            splitWrap.removeAttribute('hidden');
            if (!splitWrap.dataset.initialized) {
                const vanInput    = row.querySelector('.receive-to-van-qty');
                const regionInput = row.querySelector('.receive-to-region-qty');
                const splitVan    = Math.max(1, Math.floor(quantity / 2));
                if (vanInput)    vanInput.value    = splitVan;
                if (regionInput) regionInput.value = Math.max(0, quantity - splitVan);
                splitWrap.dataset.initialized = 'true';
            }
        } else {
            splitWrap.setAttribute('hidden', 'hidden');
            splitWrap.dataset.initialized = '';
        }
    }

    if (splitHelp) {
        if (select?.value === 'split') splitHelp.removeAttribute('hidden');
        else splitHelp.setAttribute('hidden', 'hidden');
    }
}

function syncSplitQuantities(row, changedInput) {
    const quantity    = parseInt(row.querySelector('.quantity-input')?.value || '0', 10);
    const vanInput    = row.querySelector('.receive-to-van-qty');
    const regionInput = row.querySelector('.receive-to-region-qty');

    if (!vanInput || !regionInput || !Number.isFinite(quantity)) return;

    let vanQty    = parseInt(vanInput.value || '0', 10);
    let regionQty = parseInt(regionInput.value || '0', 10);
    if (!Number.isFinite(vanQty))    vanQty = 0;
    if (!Number.isFinite(regionQty)) regionQty = 0;

    if (changedInput === vanInput) {
        vanQty    = Math.max(0, Math.min(quantity, vanQty));
        regionQty = Math.max(0, quantity - vanQty);
        regionInput.value = regionQty;
        vanInput.value    = vanQty;
    } else if (changedInput === regionInput) {
        regionQty = Math.max(0, Math.min(quantity, regionQty));
        vanQty    = Math.max(0, quantity - regionQty);
        vanInput.value    = vanQty;
        regionInput.value = regionQty;
    }
}

async function removeCartItem(cartId) {
    try {
        const response = await secureFetch('/api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildFormBody({ action: 'remove', cart_item_id: cartId })
        });

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        document.querySelector(`[data-cart-id="${cartId}"]`).remove();
        if (typeof updateHeaderCartCount === 'function') updateHeaderCartCount();
        if (document.querySelectorAll('.cart-item').length === 0) location.reload();

    } catch (error) {
        QuackNotify.error(error.message);
    }
}

async function clearCart() {
    try {
        const response = await secureFetch('/api/cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildFormBody({ action: 'clear' })
        });

        const data = await response.json();
        if (!data.success) throw new Error(data.error);
        location.reload();

    } catch (error) {
        QuackNotify.error('Error clearing cart: ' + error.message);
    }
}

async function sendOrder() {
    const storeId           = document.getElementById('shipToStoreSelect').value;
    const purchasingEmailId = document.getElementById('purchasingEmailSelect').value;
    const notes             = document.getElementById('orderNotes').value;

    if (!storeId)           { QuackNotify.warning('Please select a shipping store for this order.'); return; }
    if (!purchasingEmailId) { QuackNotify.warning('Please select a purchasing email.'); return; }

    try {
        const response = await secureFetch('/api/checkout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Idempotency-Key': crypto.randomUUID ? crypto.randomUUID() : (`ck_${Date.now()}_${Math.random().toString(16).slice(2)}`)
            },
            body: buildFormBody({ action: 'create_order', store_id: storeId, purchasing_email_id: purchasingEmailId, notes })
        });

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        try {
            window.location.href = data.mailto_link;
            setTimeout(() => { window.location.href = `/order_confirmation.php?order_id=${data.order_id}`; }, 2000);
        } catch (e) {
            window.location.href = `/email_preview.php?order_id=${data.order_id}`;
        }

    } catch (error) {
        const skuList = error.details?.items ?? error?.responseJSON?.details?.items;
        if (Array.isArray(skuList) && skuList.length > 0) {
            highlightProblemSkus(skuList);
            QuackNotify.error(`${error.message} See highlighted items for details.`);
        } else {
            QuackNotify.error('Error creating order: ' + error.message);
        }
    }
}

function highlightProblemSkus(items) {
    const skuSet = new Set(items.map(item => item.split(' ')[0]));
    document.querySelectorAll('[data-product-sku]').forEach(row => {
        const sku = row.getAttribute('data-product-sku');
        row.classList.toggle('cart-item-alert', skuSet.has(sku));
        if (skuSet.has(sku)) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}

function updateBillingIndicator(row, billToStoreId) {
    const billToSection = row.querySelector('.cart-field-group');
    if (!billToSection) return;
    const selectElement = row.querySelector('.bill-to-store-select');
    if (!selectElement) return;

    let indicator = row.querySelector('.billing-indicator');

    if (billToStoreId && billToStoreId !== '') {
        const storeName = selectElement.options[selectElement.selectedIndex]?.text || '';
        if (!indicator) {
            indicator = document.createElement('small');
            indicator.className = 'billing-indicator';
            indicator.setAttribute('aria-live', 'polite');
            selectElement.parentNode.appendChild(indicator);
        }
        indicator.textContent = storeName;
        indicator.hidden = false;
    } else if (indicator) {
        indicator.hidden = true;
    }
}

async function billAllToShippingStore() {
    const shipToStoreId = document.getElementById('shipToStoreSelect').value;
    if (!shipToStoreId) { QuackNotify.warning('Please select a shipping store first.'); return; }

    const selects = document.querySelectorAll('.bill-to-store-select');
    const updates = [];
    selects.forEach(select => {
        select.value = '';
        updates.push(updateCartItem(select.dataset.cartId, select));
    });

    try {
        await Promise.all(updates);
        QuackNotify.success('All items set to bill to shipping store');
        updateBillingSummary();
    } catch (error) {
        QuackNotify.error('Error updating billing: ' + error.message);
    }
}

async function copyBillingFromItem(sourceCartId) {
    if (!sourceCartId) return;
    const sourceItem = document.querySelector(`[data-cart-id="${sourceCartId}"]`);
    if (!sourceItem) { QuackNotify.error('Source item not found'); return; }

    const sourceBillToValue = sourceItem.querySelector('.bill-to-store-select').value;
    const allItems = document.querySelectorAll('.cart-item');
    const updates  = [];

    allItems.forEach(item => {
        const cartId = item.dataset.cartId;
        if (cartId === sourceCartId) return;
        const billToSelect = item.querySelector('.bill-to-store-select');
        billToSelect.value = sourceBillToValue;
        updates.push(updateCartItem(cartId, billToSelect));
    });

    try {
        await Promise.all(updates);
        QuackNotify.success('Billing copied to all items');
        updateBillingSummary();
    } catch (error) {
        QuackNotify.error('Error copying billing: ' + error.message);
    }
}

function updateBillingSummary() {
    const shipToStoreId = document.getElementById('shipToStoreSelect').value;
    const section       = document.getElementById('billingSummarySection');

    if (!shipToStoreId) { section.hidden = true; return; }

    const shipToStoreName = document.querySelector(`#shipToStoreSelect option[value="${shipToStoreId}"]`)?.text;
    const billingSummary  = {};

    document.querySelectorAll('.cart-item').forEach(item => {
        const billToSelect  = item.querySelector('.bill-to-store-select');
        const quantityInput = item.querySelector('.quantity-input');
        const billToStoreId = billToSelect.value || shipToStoreId;
        const storeName     = billToSelect.value
            ? billToSelect.options[billToSelect.selectedIndex].text
            : shipToStoreName;
        const quantity = parseInt(quantityInput.value, 10) || 0;

        if (!billingSummary[billToStoreId]) billingSummary[billToStoreId] = { name: storeName, items: 0, quantity: 0 };
        billingSummary[billToStoreId].items++;
        billingSummary[billToStoreId].quantity += quantity;
    });

    const entries = Object.entries(billingSummary);
    if (entries.length <= 1) { section.hidden = true; return; }

    section.hidden = false;
    let html = '<table class="cart-billing-table"><thead><tr><th>Store</th><th>Items</th><th>Qty</th></tr></thead><tbody>';
    entries.forEach(([, d]) => {
        html += `<tr><td>${d.name}</td><td>${d.items}</td><td>${d.quantity}</td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('billingBreakdown').innerHTML = html;
}

async function saveTemplate(e) {
    e.preventDefault();
    const name        = document.getElementById('templateName').value;
    const description = document.getElementById('templateDescription').value;
    const isPublic    = document.getElementById('templatePublic').checked;

    try {
        const response = await fetch('/api/templates.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildFormBody({ action: 'create', name, description, is_public: isPublic ? '1' : '0' })
        });

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        QuackNotify.success('Template saved successfully!');
        document.getElementById('saveTemplateModal').hidden = true;
        document.getElementById('saveTemplateForm')?.reset();

    } catch (error) {
        QuackNotify.error('Error saving template: ' + error.message);
    }
}

async function loadTemplates() {
    const templatesList = document.getElementById('templatesList');
    if (!templatesList) return;
    templatesList.innerHTML = '<p class="cart-loading">Loading templates…</p>';

    try {
        const response  = await fetch('/api/templates.php?action=list');
        const data      = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to load templates');

        const templates = Array.isArray(data.templates) ? data.templates : [];

        if (templates.length === 0) {
            templatesList.innerHTML = '<p class="cart-empty-msg">No templates available.</p>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'templates-grid';

        templates.forEach(template => {
            const card       = document.createElement('div');
            card.className   = 'template-card';
            const countValue = Number.isFinite(Number(template.item_count)) ? Number(template.item_count) : 0;

            [
                ['template-card-title', template.name || 'Untitled template'],
                ['template-card-desc', template.description || 'No description'],
                ['template-card-meta', `${countValue} item${countValue === 1 ? '' : 's'}`]
            ].forEach(([className, text]) => {
                const field = document.createElement('div');
                field.className = className;
                field.textContent = text;
                card.appendChild(field);
            });

            const loadButton      = document.createElement('button');
            loadButton.type       = 'button';
            loadButton.className  = 'cart-send-btn';
            loadButton.style.marginTop = '8px';
            loadButton.textContent = 'Load Template';

            const templateId = Number(template.id);
            if (Number.isFinite(templateId) && templateId > 0) {
                loadButton.addEventListener('click', () => applyTemplate(templateId));
            } else {
                loadButton.disabled = true;
            }

            card.appendChild(loadButton);
            grid.appendChild(card);
        });

        templatesList.innerHTML = '';
        templatesList.appendChild(grid);

    } catch (error) {
        const errorMessage = document.createElement('p');
        errorMessage.className = 'cart-error-msg';
        errorMessage.textContent = `Error loading templates: ${error.message}`;
        templatesList.replaceChildren(errorMessage);
    }
}

async function applyTemplate(templateId) {
    try {
        const response = await fetch('/api/templates.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildFormBody({ action: 'apply', template_id: templateId })
        });

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        QuackNotify.success('Template items added to cart!');
        document.getElementById('loadTemplateModal').hidden = true;
        location.reload();

    } catch (error) {
        QuackNotify.error('Error applying template: ' + error.message);
    }
}

function getCsrfToken() {
    return document.querySelector('input[name="csrf"]')?.value || '';
}

function buildFormBody(params = {}) {
    const body = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null) body.set(key, String(value));
    });
    const token = getCsrfToken();
    if (token) body.set('csrf', token);
    return body.toString();
}

async function loadPurchasingEmails() {
    try {
        const response = await fetch('/api/purchasing_emails.php?action=list');
        const data     = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to load purchasing emails');

        const select = document.getElementById('purchasingEmailSelect');
        if (!select) return;
        select.innerHTML = '<option value="">Select purchasing email…</option>';

        data.emails.forEach(email => {
            const option      = document.createElement('option');
            option.value      = email.id;
            option.textContent = email.label;
            if (data.preferred_id && email.id == data.preferred_id) option.selected = true;
            else if (!data.preferred_id && email.is_default)         option.selected = true;
            select.appendChild(option);
        });

    } catch (error) {
        if (window.QuackNotify) QuackNotify.error('Failed to load purchasing emails: ' + error.message);
    }
}

document.getElementById('purchasingEmailSelect')?.addEventListener('change', async function() {
    const emailId = this.value;
    if (!emailId) return;
    try {
        await fetch('/api/purchasing_emails.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildFormBody({ action: 'save_preference', email_id: emailId })
        });
    } catch {}
});

loadPurchasingEmails();
</script>

<?php require __DIR__ . '/footer.php'; ?>
