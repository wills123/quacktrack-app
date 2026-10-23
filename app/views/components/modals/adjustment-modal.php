<?php

/**
 * Stock Adjustment Modal Component
 *
 * Reusable modal for adjusting stock quantities (add/use operations)
 *
 * @param array $stores Array of stores with 'id' and 'name' keys
 * @param string $modal_id Optional modal ID (default: 'adjustmentModal')
 * @param string $form_id Optional form ID (default: 'adjustmentForm')
 */

// Set defaults
$modal_id = $modal_id ?? 'adjustmentModal';
$form_id = $form_id ?? 'adjustmentForm';
$stores = $stores ?? [];
$max_note_length = $max_note_length ?? 500;
?>

<!-- Detailed Adjustment Modal with Enhanced Accessibility -->
<div id="<?= h($modal_id) ?>" class="modal" role="dialog" aria-labelledby="<?= h($modal_id) ?>-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close adjustment modal" type="button">&times;</button>
    <h2 id="<?= h($modal_id) ?>-title">Adjust Stock for <span id="modalProductName"></span></h2>
    <form method="post" action="/api/stock.php" id="<?= h($form_id) ?>" novalidate>
      <?=csrf_field()?>
      <input type="hidden" name="action" value="adjust">
      <input type="hidden" name="product_id" id="modalProdId">

      <div class="form-group">
        <label for="modalChangeQty" class="required">Change Quantity</label>
        <input type="number" name="quantity" id="modalChangeQty" required
               aria-describedby="changeQtyHelp" step="1" min="1" max="10000">
        <div id="changeQtyHelp" class="form-help">
          Enter the quantity to add or use
        </div>
      </div>

      <div class="form-group">
        <label for="modalReason" class="required">Operation Type</label>
        <select name="adjustment_type" id="modalReason" required aria-describedby="reasonHelp">
          <option value="">Select type</option>
          <option value="add">Add to Stock</option>
          <option value="use">Use from Stock</option>
        </select>
        <div id="reasonHelp" class="form-help">
          Choose whether to add or use stock
        </div>
      </div>

      <div class="form-group">
        <label for="modalStoreId" class="required">Location</label>
        <select name="store_id" id="modalStoreId" required aria-describedby="storeHelp">
          <option value="">Select a location</option>
          <?php foreach ($stores as $s) :
                ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
                <?php
          endforeach; ?>
        </select>
        <div id="storeHelp" class="form-help">
          Select which location this adjustment applies to
        </div>
      </div>

      <div class="form-group">
        <label for="modalNote">Additional Notes</label>
        <input type="text" name="note" id="modalNote" maxlength="<?= (int)$max_note_length ?>"
               placeholder="Optional note about this adjustment"
               aria-describedby="noteHelp">
        <div id="noteHelp" class="form-help">
          Optional: Add any additional details about this stock adjustment (max <?= (int)$max_note_length ?> characters)
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Submit Adjustment</button>
        <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
      </div>
    </form>
  </div>
</div>
