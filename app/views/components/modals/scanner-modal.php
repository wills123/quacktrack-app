<?php

/**
 * Scanner Modal Component
 *
 * Reusable modal for scanning QR/barcodes using ZXing library
 *
 * @param string $modal_id Optional modal ID (default: 'vanScannerModal')
 * @param string $video_id Optional video element ID (default: 'vanScannerVideo')
 * @param string $result_container_id Optional result container ID (default: 'vanScannerResult')
 * @param string $zoom_level_id Optional zoom level display ID (default: 'vanZoomLevel')
 * @param string $zoom_in_btn_id Optional zoom in button ID (default: 'vanZoomInBtn')
 * @param string $zoom_out_btn_id Optional zoom out button ID (default: 'vanZoomOutBtn')
 * @param string $close_btn_id Optional close button ID (default: 'vanCloseScannerButton')
 * @param string $stop_btn_id Optional stop button ID (default: 'vanStopScannerButton')
 * @param string $close_footer_btn_id Optional footer close button ID (default: 'vanScannerCloseFooter')
 * @param string $modal_title Optional modal title (default: 'Scan QR Code')
 */

// Set defaults
$modal_id = $modal_id ?? 'vanScannerModal';
$video_id = $video_id ?? 'vanScannerVideo';
$result_container_id = $result_container_id ?? 'vanScannerResult';
$zoom_level_id = $zoom_level_id ?? 'vanZoomLevel';
$zoom_in_btn_id = $zoom_in_btn_id ?? 'vanZoomInBtn';
$zoom_out_btn_id = $zoom_out_btn_id ?? 'vanZoomOutBtn';
$close_btn_id = $close_btn_id ?? 'vanCloseScannerButton';
$stop_btn_id = $stop_btn_id ?? 'vanStopScannerButton';
$close_footer_btn_id = $close_footer_btn_id ?? 'vanScannerCloseFooter';
$modal_title = $modal_title ?? 'Scan QR Code';
?>

<!-- Scanner Modal (reuses ZXing) -->
<div id="<?= h($modal_id) ?>" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><?= h($modal_title) ?></h2>
      <button class="close-button" id="<?= h($close_btn_id) ?>" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <div style="position: relative; width: 100%; display: flex; flex-direction: column;">
        <video id="<?= h($video_id) ?>" autoplay muted playsinline width="100%" height="auto" style="border: 1px solid var(--color-gray-300); border-radius: var(--radius-md); min-height: 200px; background: #f0f0f0;"></video>
        <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: center; align-items: center;">
          <button id="<?= h($zoom_out_btn_id) ?>" type="button" class="btn btn-sm" title="Zoom out">−</button>
          <span id="<?= h($zoom_level_id) ?>" style="min-width: 40px; text-align: center; font-weight: bold;">1×</span>
          <button id="<?= h($zoom_in_btn_id) ?>" type="button" class="btn btn-sm" title="Zoom in for small codes">+</button>
          <span style="font-size: 12px; color: var(--color-gray-600); margin-left: 8px;">Tip: Zoom in for small codes</span>
        </div>
        <div id="<?= h($result_container_id) ?>" style="margin-top: var(--space-md);"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button id="<?= h($stop_btn_id) ?>" class="btn btn-danger" style="display:none;">Stop Scanner</button>
      <button type="button" class="btn btn-secondary" id="<?= h($close_footer_btn_id) ?>">Close</button>
    </div>
  </div>
</div>
