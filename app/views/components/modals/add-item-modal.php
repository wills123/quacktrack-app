<?php

/**
 * Add Item Modal Component
 *
 * @param array  $stores             stores with 'id' and 'name'
 * @param string $modal_id           default: 'addItemModal'
 * @param string $form_id            default: 'addItemForm'
 * @param string $product_display_id default: 'vanProdDisplay'
 * @param string $product_id_field   default: 'selectedProductId'
 * @param string $picker_btn_id      default: 'openVanProductPickerBtn'
 * @param string $scanner_btn_id     default: 'openVanScannerBtnPrimary'
 */

$modal_id           = $modal_id           ?? 'addItemModal';
$form_id            = $form_id            ?? 'addItemForm';
$product_display_id = $product_display_id ?? 'vanProdDisplay';
$product_id_field   = $product_id_field   ?? 'selectedProductId';
$picker_btn_id      = $picker_btn_id      ?? 'openVanProductPickerBtn';
$scanner_btn_id     = $scanner_btn_id     ?? 'openVanScannerBtnPrimary';
$stores             = $stores             ?? [];
$max_note_length    = $max_note_length    ?? 500;
?>

<div id="<?= h($modal_id) ?>" class="modal" role="dialog" aria-labelledby="<?= h($modal_id) ?>-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">

    <button class="close-button" aria-label="Close" type="button">&times;</button>
    <h2 id="<?= h($modal_id) ?>-title">Add Item to Van Stock</h2>

    <form method="post" action="/api/stock.php" id="<?= h($form_id) ?>" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_item">
      <input type="hidden" name="product_id" id="<?= h($product_id_field) ?>">

      <!-- Product -->
      <div class="form-group">
        <label for="<?= h($product_display_id) ?>" class="required">Product</label>
        <div class="aim-product-row">
          <input type="text"
                 id="<?= h($product_display_id) ?>"
                 placeholder="Choose a product…"
                 readonly>
          <button type="button" class="aim-choose-btn" id="<?= h($picker_btn_id) ?>">Choose</button>
          <button type="button" class="aim-scan-btn"   id="<?= h($scanner_btn_id) ?>">Scan</button>
        </div>
      </div>

      <!-- Quantity -->
      <div class="form-group">
        <label for="addItemQuantity" class="required">Quantity</label>
        <input type="number"
               name="quantity"
               id="addItemQuantity"
               required
               min="1"
               max="10000"
               step="1"
               placeholder="0"
               aria-describedby="addItemQtyHelp">
        <span id="addItemQtyHelp" class="form-help">Must be a positive whole number</span>
      </div>

      <!-- Location -->
      <div class="form-group">
        <label for="addItemStoreId" class="required">Location</label>
        <select name="store_id" id="addItemStoreId" required aria-describedby="addItemStoreHelp">
          <option value="">Select a location</option>
          <?php foreach ($stores as $s) : ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span id="addItemStoreHelp" class="form-help">Which location will hold this stock</span>
      </div>

      <!-- Notes -->
      <div class="form-group">
        <label for="addItemNote">Notes</label>
        <input type="text"
               name="note"
               id="addItemNote"
               maxlength="<?= (int)$max_note_length ?>"
               placeholder="Optional"
               aria-describedby="addItemNoteHelp">
        <span id="addItemNoteHelp" class="form-help">Max <?= (int)$max_note_length ?> characters</span>
      </div>

      <div class="aim-actions">
        <button type="submit" class="aim-submit-btn" id="addItemSubmit" disabled>
          Add to Van Stock
        </button>
        <button type="button" class="aim-cancel-btn" id="addItemCancel">
          Cancel
        </button>
      </div>
    </form>

  </div>
</div>
