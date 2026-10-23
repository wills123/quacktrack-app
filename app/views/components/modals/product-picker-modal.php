<?php

/**
 * Product Picker Modal Component
 *
 * Reusable modal for searching and selecting products
 *
 * @param string $modal_id Optional modal ID (default: 'vanProductPickerModal')
 * @param string $search_input_id Optional search input ID (default: 'vanPickerSearch')
 * @param string $list_container_id Optional list container ID (default: 'vanPickerList')
 * @param string $close_btn_id Optional close button ID (default: 'vanPickerCloseBtn')
 * @param string $modal_title Optional modal title (default: 'Choose Product')
 */

// Set defaults
$modal_id = $modal_id ?? 'vanProductPickerModal';
$search_input_id = $search_input_id ?? 'vanPickerSearch';
$list_container_id = $list_container_id ?? 'vanPickerList';
$close_btn_id = $close_btn_id ?? 'vanPickerCloseBtn';
$modal_title = $modal_title ?? 'Choose Product';
?>

<!-- Product Picker Modal -->
<div id="<?= h($modal_id) ?>" class="modal" role="dialog" aria-labelledby="<?= h($modal_id) ?>-title" aria-hidden="true">
  <div class="modal-content" tabindex="-1">
    <button class="close-button" aria-label="Close" type="button">&times;</button>
    <h2 id="<?= h($modal_id) ?>-title"><?= h($modal_title) ?></h2>

    <div class="form-group" style="padding: 14px 16px 0;">
      <label for="<?= h($search_input_id) ?>">Search</label>
      <input type="text" id="<?= h($search_input_id) ?>" placeholder="Search by name or SKU…" autocomplete="off">
    </div>

    <div style="padding: 10px 16px 0;">
      <button type="button" class="aim-cancel-btn" id="<?= h($close_btn_id) ?>">Close</button>
    </div>

    <div id="<?= h($list_container_id) ?>"></div>
  </div>
</div>
