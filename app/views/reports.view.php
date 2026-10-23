<?php
$tab = $tab ?? 'store_usage';
$start_date = $start_date ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $end_date ?? date('Y-m-d');
$store_id = $store_id ?? 0;
$scope = $scope ?? 'own';
$limit = $limit ?? 50;
$page = $page ?? 1;
$can_view_reports = $can_view_reports ?? false;
$snipe_enabled = $snipe_enabled ?? false;
$pagination = $pagination ?? [];
$usage_summary = $usage_summary ?? [];
$equipment_rows = $equipment_rows ?? [];
$stores = $stores ?? [];
require __DIR__ . '/header.php';

// ── Build filter param sets ─────────────────────────────────────────
$filter_params = array_filter([
    'tab'        => $tab,
    'start_date' => $start_date,
    'end_date'   => $end_date,
    'store_id'   => $store_id ?: null,
    'scope'      => $scope,
    'limit'      => $limit,
], fn($v) => $v !== null && $v !== '');

$base_filter_params = array_diff_key($filter_params, ['tab' => true, 'page' => true]);

$store_usage_params  = http_build_query(array_merge($base_filter_params, ['tab' => 'store_usage']));
$all_activity_params = http_build_query(array_merge($base_filter_params, ['tab' => 'all']));
$equipment_params    = http_build_query(array_merge($base_filter_params, ['tab' => 'equipment']));
$summary_export      = http_build_query(array_merge($filter_params, ['export' => 'summary']));
$detailed_export     = http_build_query(array_merge($filter_params, ['export' => 'detailed']));
?>

<style>
/* ============================================================
   RPT — Operational Dashboard Design Spec
   ============================================================ */

:root {
  --rpt-green:      var(--od-green, #006A4E);
  --rpt-green-dark: var(--od-green-dark, #004f3b);
  --rpt-green-mid:  var(--od-green-dark, #005542);
  --rpt-yellow:     #FFD42A;
  --rpt-ink:        var(--od-ink, #0f1f17);
  --rpt-ink-light:  var(--od-card-bg, #1a2e22);
  --rpt-mist:       var(--od-mist, #f3f7f5);
  --rpt-border:     var(--od-border, #e2ebe7);
  --rpt-radius:     var(--od-radius, 3px);
  --rpt-font-mono:  'SF Mono', 'Roboto Mono', 'Courier New', monospace;
}

/* ── Escape the main-content padding ───────────────────────── */
#main-content.main-content {
  padding: 0 !important;
  margin: 0 !important;
  max-width: none !important;
}
/* Make masthead sticky so it's always at the top */
.rpt-masthead,
.rpt-stat-rail,
.rpt-controls-wrap {
  position: sticky;
  top: 0;
  z-index: 100;
}

.rpt-stat-rail { z-index: 99; }
.rpt-controls-wrap { z-index: 98; }

@media (width <= 768px) {
  .rpt-masthead,
  .rpt-stat-rail,
  .rpt-controls-wrap {
    position: relative;
    top: auto;
  }
}

/* ============================================================
   MASTHEAD
   ============================================================ */
.rpt-masthead {
  position: relative;
  background: var(--rpt-green);
  padding: 0.875rem 2rem 0;
  overflow: hidden;
}

.rpt-masthead::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 32px 32px;
  pointer-events: none;
}

.rpt-masthead::after {
  content: '';
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 3px;
  background: var(--rpt-yellow);
}

.rpt-masthead-inner {
  position: relative;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.rpt-masthead-title {
  font-size: 1.5rem;
  font-weight: 800;
  color: #fff;
  margin: 0;
  letter-spacing: -0.02em;
  line-height: 1.1;
}

/* ============================================================
   STAT RAIL
   ============================================================ */
.rpt-stat-rail {
  display: flex;
  background: var(--rpt-green-dark);
  border-bottom: 3px solid var(--rpt-yellow);
  overflow-x: auto;
}

.rpt-stat {
  flex: 1;
  min-width: 120px;
  padding: 0.625rem 1.25rem;
  border-right: 1px solid rgba(255,255,255,.08);
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.rpt-stat:last-child { border-right: none; }

.rpt-stat-value {
  font-size: 1.375rem;
  font-weight: 800;
  color: #fff;
  font-family: var(--rpt-font-mono);
  line-height: 1;
}

.rpt-stat-label {
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: rgba(255,255,255,.5);
  line-height: 1.3;
}

/* ============================================================
   CONTROLS BAR
   ============================================================ */
.rpt-controls-wrap {
  background: #fff;
  border-bottom: 1px solid var(--rpt-border);
}

.rpt-controls {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  flex-wrap: nowrap;
  overflow-x: auto;
}

.rpt-controls-label {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: #888;
  white-space: nowrap;
}

.rpt-controls input[type="date"],
.rpt-controls select {
  padding: 0.3125rem 0.5rem;
  border: 1px solid #d1d5db;
  border-radius: var(--rpt-radius);
  font-size: 0.8125rem;
  background: #fff;
  color: #111;
  min-height: 32px;
  line-height: 1;
}

.rpt-controls input[type="date"]:focus,
.rpt-controls select:focus {
  outline: none;
  border-color: var(--rpt-green);
  box-shadow: 0 0 0 2px rgba(0,106,78,.12);
}

.rpt-sep {
  width: 1px;
  height: 20px;
  background: var(--rpt-border);
  flex-shrink: 0;
}

.rpt-chip {
  display: inline-block;
  padding: 0.25rem 0.5625rem;
  font-size: 0.75rem;
  font-weight: 600;
  border: 1px solid #d1d5db;
  border-radius: 2px;
  background: #fff;
  color: #555;
  cursor: pointer;
  transition: all .12s;
  white-space: nowrap;
  line-height: 1.4;
  text-decoration: none;
}

.rpt-chip:hover {
  border-color: var(--rpt-green);
  color: var(--rpt-green);
  background: rgba(0,106,78,.04);
}

.rpt-chip.active {
  background: var(--rpt-green);
  border-color: var(--rpt-green);
  color: #fff;
}

.rpt-apply-btn {
  padding: 0.3125rem 0.875rem;
  font-size: 0.8125rem;
  font-weight: 700;
  background: var(--rpt-green);
  color: #fff;
  border: none;
  border-radius: var(--rpt-radius);
  cursor: pointer;
  transition: background .12s;
  white-space: nowrap;
  min-height: 32px;
}

.rpt-apply-btn:hover { background: var(--rpt-green-dark); }

.rpt-ghost-btn {
  padding: 0.3125rem 0.75rem;
  font-size: 0.8125rem;
  font-weight: 600;
  background: transparent;
  color: var(--rpt-green);
  border: 1px solid var(--rpt-green);
  border-radius: var(--rpt-radius);
  cursor: pointer;
  transition: all .12s;
  text-decoration: none;
  white-space: nowrap;
  min-height: 32px;
  display: inline-flex;
  align-items: center;
}

.rpt-ghost-btn:hover {
  background: var(--rpt-green);
  color: #fff;
}

.rpt-controls-end {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.375rem;
  flex-shrink: 0;
}

/* ============================================================
   CONTENT AREA
   ============================================================ */
.rpt-body {
  background: var(--rpt-mist);
  min-height: calc(100vh - 200px);
  padding: 1.25rem 1.5rem;
}

/* ============================================================
   SECTION CARD
   ============================================================ */
.rpt-card {
  background: #fff;
  border: 1px solid var(--rpt-border);
  border-radius: var(--rpt-radius);
  margin-bottom: 1.25rem;
  overflow: hidden;
}

.rpt-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1.125rem;
  border-bottom: 1px solid var(--rpt-border);
  background: linear-gradient(to right, var(--rpt-mist), #fff);
  gap: 0.75rem;
}

.rpt-card-title {
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--rpt-ink);
  margin: 0;
  text-transform: uppercase;
  letter-spacing: .05em;
}

.rpt-card-sub {
  font-size: 0.75rem;
  color: #888;
  margin: 0.125rem 0 0;
  font-weight: 400;
  text-transform: none;
  letter-spacing: 0;
}

/* ============================================================
   RESTOCK TOOLBAR
   ============================================================ */
.rpt-restock-bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1.125rem;
  background: rgba(0,106,78,.04);
  border-bottom: 1px solid var(--rpt-border);
  flex-wrap: wrap;
}

.rpt-selected-pill {
  font-size: 0.75rem;
  color: #555;
  background: #fff;
  border: 1px solid var(--rpt-border);
  border-radius: 2px;
  padding: 0.1875rem 0.5rem;
}

.rpt-selected-pill strong { color: var(--rpt-green); }

/* ============================================================
   TABLES
   ============================================================ */
.rpt-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}

.rpt-table thead th {
  padding: 0.5625rem 0.875rem;
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: rgba(255,255,255,.75);
  background: var(--rpt-ink);
  border-bottom: none;
  white-space: nowrap;
}

.rpt-table thead th:first-child {
  border-left: 3px solid var(--rpt-yellow);
}

.rpt-table tbody td {
  padding: 0.5625rem 0.875rem;
  color: #1a1a1a;
  border-bottom: 1px solid #f0f0f0;
  vertical-align: middle;
}

.rpt-table tbody tr:last-child td { border-bottom: none; }

.rpt-table tbody tr { transition: background .1s; }

.rpt-table tbody tr:hover { background: rgba(0,106,78,.03); }

.rpt-table tbody tr:hover td:first-child {
  border-left: 3px solid var(--rpt-green);
  padding-left: calc(0.875rem - 3px);
}

.rpt-col-check { width: 40px; text-align: center; }
.rpt-col-date  { white-space: nowrap; }
.rpt-col-sku   { font-family: var(--rpt-font-mono); font-size: 0.75rem; color: #666; }
.rpt-col-num   { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.rpt-qty-pos   { color: #059669; }
.rpt-qty-neg   { color: #dc2626; }
.rpt-muted     { color: #9ca3af; font-style: italic; }

/* Asset name + tag stacked in one cell */
.rpt-asset-name {
  font-weight: 600;
  color: #1a1a1a;
  line-height: 1.3;
}

.rpt-asset-tag {
  font-family: var(--rpt-font-mono);
  font-size: 0.6875rem;
  color: #888;
  margin-top: 0.125rem;
}

.rpt-target-type {
  display: inline-block;
  margin-left: 0.25rem;
  font-size: 0.6875rem;
  color: #888;
  font-style: italic;
}

/* ============================================================
   BADGES
   ============================================================ */
.rpt-badge {
  display: inline-block;
  padding: 0.1875rem 0.5rem;
  border-radius: 2px;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.rpt-badge--default  { background: #f3f4f6; color: #6b7280; }
.rpt-badge--checkout { background: #fef3c7; color: #92400e; }
.rpt-badge--checkin  { background: #d1fae5; color: #065f46; }
.rpt-badge--create   { background: #dbeafe; color: #1e40af; }
.rpt-badge--update   { background: #ede9fe; color: #5b21b6; }
.rpt-badge--use      { background: #fde8e8; color: #b91c1c; }
.rpt-badge--add      { background: #d1fae5; color: #065f46; }
.rpt-badge--transfer { background: #dbeafe; color: #1e40af; }
.rpt-badge--adjust   { background: #fef3c7; color: #92400e; }
.rpt-badge--return   { background: #d1fae5; color: #065f46; }
.rpt-badge--damage   { background: #fde8e8; color: #b91c1c; }
.rpt-badge--loss     { background: #fde8e8; color: #b91c1c; }

/* ============================================================
   PAGINATION FOOTER
   ============================================================ */
.rpt-tfoot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.625rem 1.125rem;
  background: var(--rpt-mist);
  border-top: 1px solid var(--rpt-border);
  flex-wrap: wrap;
  gap: 0.5rem;
}

.rpt-tfoot-info {
  font-size: 0.75rem;
  color: #666;
  font-family: var(--rpt-font-mono);
}

.rpt-page-nav {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.rpt-page-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  padding: 0 0.4375rem;
  border: 1px solid #d1d5db;
  border-radius: 2px;
  background: #fff;
  color: #374151;
  font-size: 0.75rem;
  font-weight: 600;
  text-decoration: none;
  transition: all .1s;
}

.rpt-page-btn:hover:not(.disabled) {
  border-color: var(--rpt-green);
  color: var(--rpt-green);
  background: rgba(0,106,78,.04);
}

.rpt-page-btn.disabled {
  opacity: .35;
  cursor: not-allowed;
  pointer-events: none;
}

.rpt-page-current {
  font-size: 0.75rem;
  color: #555;
  padding: 0 0.375rem;
  font-family: var(--rpt-font-mono);
}

/* ============================================================
   EMPTY / ERROR STATES
   ============================================================ */
.rpt-state {
  padding: 2.5rem 1.5rem;
  text-align: center;
}

.rpt-state-eyebrow {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--rpt-green);
  margin-bottom: 0.375rem;
}

.rpt-state-msg {
  font-size: 0.9375rem;
  font-weight: 600;
  color: #374151;
  margin-bottom: 0.25rem;
}

.rpt-state-sub {
  font-size: 0.8125rem;
  color: #9ca3af;
}

.rpt-error-bar {
  padding: 0.875rem 1.125rem;
  background: #fde8e8;
  color: #b91c1c;
  border-left: 3px solid #b91c1c;
  font-size: 0.8125rem;
}

/* ============================================================
   DIALOG MODAL
   ============================================================ */
.rpt-dialog-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  opacity: 0;
  visibility: hidden;
  transition: opacity .2s, visibility .2s;
}

.rpt-dialog-overlay.active { opacity: 1; visibility: visible; }

.rpt-dialog {
  background: #fff;
  border-radius: var(--rpt-radius);
  box-shadow: 0 24px 48px rgba(0,0,0,.22);
  max-width: 400px;
  width: 90%;
  transform: scale(.92) translateY(-16px);
  transition: transform .2s;
  overflow: hidden;
}

.rpt-dialog-overlay.active .rpt-dialog { transform: scale(1) translateY(0); }

.rpt-dialog-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f0f0f0;
  background: var(--rpt-ink);
}

.rpt-dialog-icon {
  width: 32px; height: 32px;
  border-radius: 2px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

.rpt-dialog-icon.success { background: rgba(255,212,42,.2); color: var(--rpt-yellow); }
.rpt-dialog-icon.error   { background: rgba(220,38,38,.2);  color: #f87171; }
.rpt-dialog-icon.info    { background: rgba(59,130,246,.2); color: #93c5fd; }

.rpt-dialog-icon svg { width: 18px; height: 18px; }

.rpt-dialog-title {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: .01em;
}

.rpt-dialog-body { padding: 1.125rem 1.25rem; }

.rpt-dialog-msg {
  margin: 0;
  font-size: 0.875rem;
  color: #374151;
  line-height: 1.5;
}

.rpt-dialog-foot {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  padding: 0.75rem 1.25rem;
  background: var(--rpt-mist);
  border-top: 1px solid var(--rpt-border);
}

.rpt-dialog-btn {
  padding: 0.375rem 1rem;
  border-radius: var(--rpt-radius);
  font-size: 0.8125rem;
  font-weight: 700;
  cursor: pointer;
  border: 1px solid transparent;
  transition: all .12s;
}

.rpt-dialog-btn.secondary {
  background: #fff;
  border-color: #d1d5db;
  color: #374151;
}

.rpt-dialog-btn.secondary:hover {
  background: var(--rpt-mist);
  border-color: #9ca3af;
}

.rpt-dialog-btn.primary { background: var(--rpt-green); color: #fff; }
.rpt-dialog-btn.primary:hover { background: var(--rpt-green-dark); }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
  .rpt-body { padding: 0.875rem; }
  .rpt-controls { flex-wrap: wrap; }
  .rpt-stat-rail { flex-wrap: wrap; }
  .rpt-stat { min-width: 100px; }
}

/* ── Shared app surface colors ───────────────────────────── */
.rpt-body { background: var(--bg); color: var(--text-primary); }
.rpt-controls-wrap,
.rpt-card,
.rpt-dialog,
.rpt-selected-pill,
.rpt-page-btn {
  background: var(--surface);
  color: var(--text-primary);
  border-color: var(--border);
}
.rpt-card-header,
.rpt-tfoot,
.rpt-dialog-foot { background: var(--surface-2); }
.rpt-controls input[type="date"],
.rpt-controls select,
.rpt-chip,
.rpt-dialog-btn.secondary {
  background: var(--input-bg);
  color: var(--text-primary);
  border-color: var(--input-border);
}
.rpt-card-title,
.rpt-asset-name,
.rpt-table tbody td,
.rpt-state-msg,
.rpt-dialog-msg { color: var(--text-primary); }
.rpt-card-sub,
.rpt-col-sku,
.rpt-asset-tag,
.rpt-target-type,
.rpt-tfoot-info,
.rpt-page-current { color: var(--text-muted); }
.rpt-table tbody td { border-bottom-color: var(--border-subtle); }

/* ── Dark mode ────────────────────────────────────────────── */
[data-kit-theme="dark"] {
  --rpt-green:      var(--od-green);
  --rpt-green-dark: var(--od-green-dark);
  --rpt-green-mid:  var(--od-green-dark);
  --rpt-ink:        var(--od-ink);
  --rpt-ink-light:  var(--od-card-bg);
  --rpt-mist:       var(--od-mist);
  --rpt-border:     var(--od-border);
}

[data-kit-theme="dark"] .rpt-controls-wrap,
[data-kit-theme="dark"] .rpt-card,
[data-kit-theme="dark"] .rpt-dialog,
[data-kit-theme="dark"] .rpt-selected-pill,
[data-kit-theme="dark"] .rpt-page-btn {
  background: var(--surface);
  color: var(--text-primary);
  border-color: var(--rpt-border);
}

[data-kit-theme="dark"] .rpt-controls-wrap,
[data-kit-theme="dark"] .rpt-card-header,
[data-kit-theme="dark"] .rpt-tfoot,
[data-kit-theme="dark"] .rpt-dialog-foot {
  background: var(--rpt-mist);
}

[data-kit-theme="dark"] .rpt-controls-wrap,
[data-kit-theme="dark"] .rpt-controls {
  background: var(--surface-2) !important;
}

[data-kit-theme="dark"] .rpt-controls input[type="date"],
[data-kit-theme="dark"] .rpt-controls select {
  background: var(--input-bg);
  color: var(--text-primary);
  border-color: var(--rpt-border);
}

[data-kit-theme="dark"] .rpt-chip,
[data-kit-theme="dark"] .rpt-dialog-btn.secondary {
  background: var(--surface-2);
  color: var(--text-primary);
  border-color: var(--rpt-border);
}

[data-kit-theme="dark"] .rpt-card-header {
  background: linear-gradient(to right, var(--surface-2), var(--surface));
}

[data-kit-theme="dark"] .rpt-card-title,
[data-kit-theme="dark"] .rpt-asset-name,
[data-kit-theme="dark"] .rpt-table tbody td,
[data-kit-theme="dark"] .rpt-state-msg,
[data-kit-theme="dark"] .rpt-dialog-msg {
  color: var(--text-primary);
}

[data-kit-theme="dark"] .rpt-card-sub,
[data-kit-theme="dark"] .rpt-col-sku,
[data-kit-theme="dark"] .rpt-asset-tag,
[data-kit-theme="dark"] .rpt-target-type,
[data-kit-theme="dark"] .rpt-tfoot-info,
[data-kit-theme="dark"] .rpt-page-current {
  color: var(--text-muted);
}

[data-kit-theme="dark"] .rpt-table tbody td {
  border-bottom-color: var(--rpt-border);
}

[data-kit-theme="dark"] .rpt-table tbody tr:hover {
  background: rgba(0,168,120,.07);
}

[data-kit-theme="dark"] .rpt-badge--default  { background: #1f2937; color: #9ca3af; }
[data-kit-theme="dark"] .rpt-badge--checkout,
[data-kit-theme="dark"] .rpt-badge--adjust    { background: #451a03; color: #fcd34d; }
[data-kit-theme="dark"] .rpt-badge--checkin,
[data-kit-theme="dark"] .rpt-badge--add,
[data-kit-theme="dark"] .rpt-badge--return    { background: #052e16; color: #6ee7b7; }
[data-kit-theme="dark"] .rpt-badge--create,
[data-kit-theme="dark"] .rpt-badge--transfer  { background: #1e3a8a; color: #93c5fd; }
[data-kit-theme="dark"] .rpt-badge--update    { background: #3b0764; color: #e9d5ff; }
[data-kit-theme="dark"] .rpt-badge--use,
[data-kit-theme="dark"] .rpt-badge--damage,
[data-kit-theme="dark"] .rpt-badge--loss      { background: #450a0a; color: #fca5a5; }

[data-kit-theme="dark"] .rpt-selected-pill {
  color: var(--text-secondary);
}

[data-kit-theme="dark"] .rpt-error-bar {
  background: #450a0a;
  color: #fca5a5;
  border-left-color: #ef4444;
}

[data-kit-theme="dark"] .rpt-dialog-msg {
  color: #c8d8ce;
}
</style>

<!-- ======================================================
     DIALOG MODAL
     ====================================================== -->
<div class="rpt-dialog-overlay" id="rpt-dialog">
  <div class="rpt-dialog" role="dialog" aria-modal="true" aria-labelledby="rpt-dialog-title">
    <div class="rpt-dialog-head">
      <div class="rpt-dialog-icon success" id="rpt-dialog-icon">
        <svg id="rpt-icon-success" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
        <svg id="rpt-icon-error" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:none">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
        <svg id="rpt-icon-info" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="display:none">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <h3 class="rpt-dialog-title" id="rpt-dialog-title">Notice</h3>
    </div>
    <div class="rpt-dialog-body">
      <p class="rpt-dialog-msg" id="rpt-dialog-message">Operation completed.</p>
    </div>
    <div class="rpt-dialog-foot" id="rpt-dialog-footer">
      <button type="button" class="rpt-dialog-btn secondary" id="rpt-dialog-cancel">Cancel</button>
      <button type="button" class="rpt-dialog-btn primary" id="rpt-dialog-confirm">OK</button>
    </div>
  </div>
</div>

<!-- ======================================================
     MASTHEAD
     ====================================================== -->
<div class="page-masthead rpt-masthead">
  <div class="rpt-masthead-inner">
    <div class="rpt-masthead-brand">
      <h1 class="rpt-masthead-title">Field Reports</h1>
    </div>
    <nav class="masthead-tabs" role="tablist">
      <a href="?<?= h($store_usage_params) ?>"
         class="masthead-tab rpt-tab <?= $tab === 'store_usage' ? 'active' : '' ?>"
         role="tab" aria-selected="<?= $tab === 'store_usage' ? 'true' : 'false' ?>">
        Store Usage
      </a>
      <?php if ($can_view_reports) : ?>
      <a href="?<?= h($all_activity_params) ?>"
         class="masthead-tab rpt-tab <?= $tab === 'all' ? 'active' : '' ?>"
         role="tab" aria-selected="<?= $tab === 'all' ? 'true' : 'false' ?>">
        All Activity
      </a>
      <?php endif; ?>
      <?php if ($snipe_enabled) : ?>
      <a href="?<?= h($equipment_params) ?>"
         class="masthead-tab rpt-tab <?= $tab === 'equipment' ? 'active' : '' ?>"
         role="tab" aria-selected="<?= $tab === 'equipment' ? 'true' : 'false' ?>">
        Equipment
      </a>
      <?php endif; ?>
    </nav>
  </div>
</div>

<!-- ======================================================
     STAT RAIL
     ====================================================== -->
<div class="page-stat-rail rpt-stat-rail">
  <?php if ($tab !== 'equipment') : ?>
  <div class="page-stat rpt-stat">
    <div>
      <div class="page-stat-value rpt-stat-value"><?= number_format($pagination['total']) ?></div>
      <div class="page-stat-label rpt-stat-label">Records</div>
    </div>
  </div>
        <?php if ($tab === 'store_usage') : ?>
  <div class="page-stat rpt-stat">
    <div>
      <div class="page-stat-value rpt-stat-value"><?= count($usage_summary) ?></div>
      <div class="page-stat-label rpt-stat-label">Products used</div>
    </div>
  </div>
        <?php endif; ?>
  <?php else : ?>
  <div class="page-stat rpt-stat">
    <div>
      <div class="page-stat-value rpt-stat-value"><?= count($equipment_rows) ?></div>
      <div class="page-stat-label rpt-stat-label">Activities</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="page-stat rpt-stat">
    <div>
      <?php
        $d1 = new DateTime($start_date);
        $d2 = new DateTime($end_date);
        $days_diff = $d1->diff($d2)->days + 1;
        ?>
      <div class="page-stat-value rpt-stat-value"><?= $days_diff ?></div>
      <div class="page-stat-label rpt-stat-label">Day range</div>
    </div>
  </div>
  <div class="page-stat page-stat--wide rpt-stat">
    <div>
      <div class="page-stat-value page-stat-value--compact rpt-stat-value">
        <?= date('M j', strtotime($start_date)) ?> – <?= date('M j, Y', strtotime($end_date)) ?>
      </div>
      <div class="page-stat-label rpt-stat-label">Period</div>
    </div>
  </div>
</div>

<!-- ======================================================
     CONTROLS BAR
     ====================================================== -->
<div class="rpt-controls-wrap">
  <form method="get" class="rpt-controls" id="rpt-filters">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">

    <span class="rpt-controls-label">Range</span>
    <input type="date" id="rpt-start" name="start_date" value="<?= h($start_date) ?>">
    <span style="font-size:.75rem;color:#aaa;">→</span>
    <input type="date" id="rpt-end" name="end_date" value="<?= h($end_date) ?>">

    <div class="rpt-sep"></div>

    <button type="button" class="rpt-chip" data-range="7">7d</button>
    <button type="button" class="rpt-chip" data-range="30">30d</button>
    <button type="button" class="rpt-chip" data-range="90">90d</button>

    <?php if ($tab !== 'equipment') : ?>
    <div class="rpt-sep"></div>

    <select name="store_id" onchange="this.form.submit()" style="min-width:120px;">
      <option value="0">All stores</option>
        <?php foreach ($stores as $s) : ?>
        <option value="<?= (int)$s['id'] ?>" <?= $store_id === (int)$s['id'] ? 'selected' : '' ?>>
            <?= h($s['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>

        <?php if ($can_view_reports) : ?>
    <select name="scope" onchange="this.form.submit()">
      <option value="own" <?= $scope === 'own' ? 'selected' : '' ?>>My usage</option>
      <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All techs</option>
    </select>
        <?php endif; ?>

    <select name="limit" onchange="this.form.submit()">
      <option value="25"  <?= $limit === 25  ? 'selected' : '' ?>>25 / page</option>
      <option value="50"  <?= $limit === 50  ? 'selected' : '' ?>>50 / page</option>
      <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 / page</option>
    </select>
    <?php endif; ?>

    <div class="rpt-controls-end">
      <button type="submit" class="rpt-apply-btn">Apply</button>
      <a href="/reports?tab=<?= h($tab) ?>" class="rpt-ghost-btn">Clear</a>
      <?php if ($can_view_reports && $tab !== 'equipment') : ?>
      <a href="?<?= h($summary_export) ?>" class="rpt-ghost-btn">↓ Summary</a>
      <a href="?<?= h($detailed_export) ?>" class="rpt-ghost-btn">↓ Details</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ======================================================
     BODY
     ====================================================== -->
<div class="rpt-body">

<?php if ($tab === 'store_usage') : ?>
<!-- ====================================================== -->
<!-- STORE USAGE TAB                                        -->
<!-- ====================================================== -->

<div class="rpt-card">
  <div class="rpt-card-header">
    <div>
      <div class="rpt-card-title">Usage Summary</div>
      <div class="rpt-card-sub">Billable items used at a store. Select items to restock via cart.</div>
    </div>
  </div>

    <?php if (empty($usage_summary)) : ?>
    <div class="rpt-state">
      <div class="rpt-state-eyebrow">Store Usage</div>
      <div class="rpt-state-msg">No billable usage for this period</div>
      <div class="rpt-state-sub">Try expanding the date range or changing filters</div>
    </div>
    <?php else : ?>
    <div class="rpt-restock-bar">
      <input type="checkbox" id="rpt-select-all">
      <label for="rpt-select-all" class="rpt-controls-label">Select All</label>
      <span class="rpt-selected-pill"><strong id="rpt-sel-count">0</strong> selected</span>
      <button type="button" class="rpt-apply-btn" id="rpt-restock-btn" disabled>Add to Cart</button>
    </div>
    <table class="rpt-table" id="rpt-summary-table">
      <thead>
        <tr>
          <th class="rpt-col-check"></th>
          <th>Used At</th>
          <th>From</th>
          <th>Product</th>
          <th>SKU</th>
          <th class="rpt-col-num">Qty</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_summary as $row) : ?>
          <tr>
            <td class="rpt-col-check">
              <input type="checkbox" class="rpt-restock-item"
                     data-product-id="<?= (int)$row['product_db_id'] ?>"
                     data-quantity="<?= (int)$row['total_qty'] ?>"
                     data-store-id="<?= (int)$row['target_store_id'] ?>"
                     data-product-name="<?= h($row['product_name'] ?? '') ?>">
            </td>
            <td><?= h($row['target_store_name'] ?? 'Unknown') ?></td>
            <td class="<?= empty($row['source_store_name']) ? 'rpt-muted' : '' ?>">
              <?= h($row['source_store_name'] ?? 'Van') ?>
            </td>
            <td><?= h($row['product_name'] ?? '') ?></td>
            <td class="rpt-col-sku"><?= h($row['sku'] ?? '') ?></td>
            <td class="rpt-col-num"><?= (int)$row['total_qty'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="rpt-card">
  <div class="rpt-card-header">
    <div>
      <div class="rpt-card-title">Transaction Details</div>
      <div class="rpt-card-sub">Individual records for the selected period</div>
    </div>
  </div>

    <?php if (empty($usage_rows)) : ?>
    <div class="rpt-state">
      <div class="rpt-state-eyebrow">Details</div>
      <div class="rpt-state-msg">No transactions found</div>
      <div class="rpt-state-sub">Try expanding the date range</div>
    </div>
    <?php else : ?>
    <table class="rpt-table">
      <thead>
        <tr>
          <th class="rpt-col-date">Date</th>
          <?php if ($can_view_reports && $scope === 'all') :
                ?><th>User</th><?php
          endif; ?>
          <th>Product</th>
          <th>SKU</th>
          <th class="rpt-col-num">Qty</th>
          <th>From</th>
          <th>Used At</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_rows as $row) : ?>
          <tr>
            <td class="rpt-col-date"><?= h(date('M j, g:ia', strtotime($row['created_at']))) ?></td>
            <?php if ($can_view_reports && $scope === 'all') : ?>
            <td><?= h($row['user_display'] ?? $row['user_email'] ?? '') ?></td>
            <?php endif; ?>
            <td><?= h($row['product_name'] ?? '') ?></td>
            <td class="rpt-col-sku"><?= h($row['sku'] ?? '') ?></td>
            <?php $qty = (int)$row['quantity_change']; ?>
            <td class="rpt-col-num <?= $qty >= 0 ? 'rpt-qty-pos' : 'rpt-qty-neg' ?>">
              <?= $qty >= 0 ? '+' . $qty : $qty ?>
            </td>
            <td class="<?= empty($row['source_store_name']) ? 'rpt-muted' : '' ?>">
              <?= h($row['source_store_name'] ?? 'Van') ?>
            </td>
            <td><?= h($row['target_store_name'] ?? '') ?></td>
            <td class="rpt-muted"><?= h((string)($row['note'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
        <?php if ($pagination['total_pages'] > 1) : ?>
            <?php
            $pp = $filter_params;
            $pp['page'] = 1;
            $p1    = http_build_query($pp);
            $pp['page'] = $page - 1;
            $pprev = http_build_query($pp);
            $pp['page'] = $page + 1;
            $pnext = http_build_query($pp);
            $pp['page'] = $pagination['total_pages'];
            $plast = http_build_query($pp);
            ?>
      <div class="rpt-tfoot">
        <span class="rpt-tfoot-info"><?= $pagination['start_item'] ?>–<?= $pagination['end_item'] ?> of <?= number_format($pagination['total']) ?></span>
        <div class="rpt-page-nav">
          <a href="?<?= $p1 ?>"    class="rpt-page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">«</a>
          <a href="?<?= $pprev ?>" class="rpt-page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">‹</a>
          <span class="rpt-page-current"><?= $page ?> / <?= $pagination['total_pages'] ?></span>
          <a href="?<?= $pnext ?>" class="rpt-page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">›</a>
          <a href="?<?= $plast ?>" class="rpt-page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">»</a>
        </div>
      </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'all') : ?>
<!-- ====================================================== -->
<!-- ALL ACTIVITY TAB                                       -->
<!-- ====================================================== -->

<div class="rpt-card">
  <div class="rpt-card-header">
    <div>
      <div class="rpt-card-title">Activity Log</div>
      <div class="rpt-card-sub">Complete transaction history across all stock operations</div>
    </div>
  </div>

    <?php if (empty($usage_rows)) : ?>
    <div class="rpt-state">
      <div class="rpt-state-eyebrow">All Activity</div>
      <div class="rpt-state-msg">No activity found for this period</div>
      <div class="rpt-state-sub">Try expanding the date range</div>
    </div>
    <?php else : ?>
    <table class="rpt-table">
      <thead>
        <tr>
          <th class="rpt-col-date">Date</th>
          <?php if ($scope === 'all') :
                ?><th>User</th><?php
          endif; ?>
          <th>Product</th>
          <th>SKU</th>
          <th class="rpt-col-num">Change</th>
          <th>Reason</th>
          <th>Source</th>
          <th>Target</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usage_rows as $row) : ?>
          <tr>
            <td class="rpt-col-date"><?= h(date('M j, g:ia', strtotime($row['created_at']))) ?></td>
            <?php if ($scope === 'all') : ?>
            <td><?= h($row['user_display'] ?? $row['user_email'] ?? '') ?></td>
            <?php endif; ?>
            <td><?= h($row['product_name'] ?? '') ?></td>
            <td class="rpt-col-sku"><?= h($row['sku'] ?? '') ?></td>
            <?php $qty = (int)$row['quantity_change']; ?>
            <td class="rpt-col-num <?= $qty >= 0 ? 'rpt-qty-pos' : 'rpt-qty-neg' ?>">
              <?= $qty >= 0 ? '+' . $qty : $qty ?>
            </td>
            <td>
              <?php $r = strtolower($row['reason'] ?? ''); ?>
              <span class="rpt-badge rpt-badge--<?= h($r) ?>"><?= h($r ?: '—') ?></span>
            </td>
            <td><?= h($row['source_store_name'] ?? '—') ?></td>
            <td><?= h($row['target_store_name'] ?? '—') ?></td>
            <td class="rpt-muted"><?= h((string)($row['note'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
        <?php if ($pagination['total_pages'] > 1) : ?>
            <?php
            $pp = $filter_params;
            $pp['page'] = 1;
            $p1    = http_build_query($pp);
            $pp['page'] = $page - 1;
            $pprev = http_build_query($pp);
            $pp['page'] = $page + 1;
            $pnext = http_build_query($pp);
            $pp['page'] = $pagination['total_pages'];
            $plast = http_build_query($pp);
            ?>
      <div class="rpt-tfoot">
        <span class="rpt-tfoot-info"><?= $pagination['start_item'] ?>–<?= $pagination['end_item'] ?> of <?= number_format($pagination['total']) ?></span>
        <div class="rpt-page-nav">
          <a href="?<?= $p1 ?>"    class="rpt-page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">«</a>
          <a href="?<?= $pprev ?>" class="rpt-page-btn <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">‹</a>
          <span class="rpt-page-current"><?= $page ?> / <?= $pagination['total_pages'] ?></span>
          <a href="?<?= $pnext ?>" class="rpt-page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">›</a>
          <a href="?<?= $plast ?>" class="rpt-page-btn <?= !$pagination['has_next'] ? 'disabled' : '' ?>">»</a>
        </div>
      </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'equipment') : ?>
<!-- ====================================================== -->
<!-- EQUIPMENT TAB                                          -->
<!-- ====================================================== -->

<div class="rpt-card">
  <div class="rpt-card-header">
    <div>
      <div class="rpt-card-title">Equipment Checkouts</div>
      <div class="rpt-card-sub">IT asset activity from Snipe-IT</div>
    </div>
  </div>

    <?php if (!empty($snipe_error)) : ?>
    <div class="rpt-error-bar"><strong>Snipe-IT Error:</strong> <?= h($snipe_error) ?></div>
    <?php elseif (empty($equipment_rows)) : ?>
    <div class="rpt-state">
      <div class="rpt-state-eyebrow">Equipment</div>
      <div class="rpt-state-msg">No equipment activity for this period</div>
      <div class="rpt-state-sub">Try expanding the date range</div>
    </div>
    <?php else : ?>
    <table class="rpt-table">
      <thead>
        <tr>
          <th class="rpt-col-date">Date</th>
          <th>Asset</th>
          <th>Action</th>
          <th>Checked Out To</th>
          <th>Performed By</th>
          <th>Location</th>
          <th>Note</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($equipment_rows as $row) : ?>
            <?php
            $a = strtolower($row['action_type'] ?? '');
            $badge_cls = 'rpt-badge--default';
            if (str_contains($a, 'checkout')) {
                $badge_cls = 'rpt-badge--checkout';
            } elseif (str_contains($a, 'checkin')) {
                $badge_cls = 'rpt-badge--checkin';
            } elseif (str_contains($a, 'create')) {
                $badge_cls = 'rpt-badge--create';
            } elseif (str_contains($a, 'update')) {
                $badge_cls = 'rpt-badge--update';
            }

            $dt = $row['created_at'] ?? '';
            $date_str = '';
            if ($dt) {
                try {
                    $date_str = date('M j, Y', strtotime($dt));
                } catch (Throwable $e) {
                    $date_str = $dt;
                }
            }

            // "Checked out to" is the target; for non-checkout actions it may not apply
            $checked_out_to = $row['target_name'] ?? '—';
            $target_type    = $row['target_type'] ?? '';

            // "Performed by" is the admin who did the action
            $performed_by = $row['admin_name'] ?? '—';

            // If target and admin are the same, only show once in "Performed By"
            $show_target = ($checked_out_to !== '—' && $checked_out_to !== $performed_by);
            ?>
          <tr>
            <td class="rpt-col-date"><?= $date_str ?: '—' ?></td>
            <td>
              <div class="rpt-asset-name"><?= h($row['item_name'] ?? 'Unknown') ?></div>
              <?php if (!empty($row['asset_tag'])) : ?>
                <div class="rpt-asset-tag"><?= h($row['asset_tag']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="rpt-badge <?= $badge_cls ?>"><?= h($row['action_type'] ?? '—') ?></span>
            </td>
            <td>
              <?php if ($show_target) : ?>
                <span><?= h($checked_out_to) ?></span>
                    <?php if ($target_type && $target_type !== 'user') : ?>
                  <span class="rpt-target-type"><?= h($target_type) ?></span>
                    <?php endif; ?>
              <?php else : ?>
                <span class="rpt-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= h($performed_by) ?></td>
            <td><?= !empty($row['location']) ? h($row['location']) : '<span class="rpt-muted">—</span>' ?></td>
            <td class="rpt-muted"><?= !empty($row['note']) ? h($row['note']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

</div><!-- /.rpt-body -->

<script>
(function() {
  'use strict';

  // ======================================================
  // DIALOG SYSTEM
  // ======================================================
  const overlay   = document.getElementById('rpt-dialog');
  const icon      = document.getElementById('rpt-dialog-icon');
  const titleEl   = document.getElementById('rpt-dialog-title');
  const msgEl     = document.getElementById('rpt-dialog-message');
  const confirmEl = document.getElementById('rpt-dialog-confirm');
  const cancelEl  = document.getElementById('rpt-dialog-cancel');
  const iSuccess  = document.getElementById('rpt-icon-success');
  const iError    = document.getElementById('rpt-icon-error');
  const iInfo     = document.getElementById('rpt-icon-info');

  let _resolve = null;

  function showDialog({ type='info', title='Notice', message='', confirmText='OK', cancelText='Cancel', showCancel=false }) {
    icon.className = 'rpt-dialog-icon ' + type;
    iSuccess.style.display = type === 'success' ? 'block' : 'none';
    iError.style.display   = type === 'error'   ? 'block' : 'none';
    iInfo.style.display    = type === 'info'    ? 'block' : 'none';
    titleEl.textContent   = title;
    msgEl.textContent     = message;
    confirmEl.textContent = confirmText;
    cancelEl.textContent  = cancelText;
    cancelEl.style.display = showCancel ? 'inline-block' : 'none';
    overlay.classList.add('active');
    confirmEl.focus();
    return new Promise(r => { _resolve = r; });
  }

  function hideDialog(val) {
    overlay.classList.remove('active');
    if (_resolve) { _resolve(val); _resolve = null; }
  }

  confirmEl.addEventListener('click', () => hideDialog(true));
  cancelEl.addEventListener('click',  () => hideDialog(false));
  overlay.addEventListener('click', e => { if (e.target === overlay) hideDialog(false); });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && overlay.classList.contains('active')) hideDialog(false);
  });

  function showAlert(msg, type='info', title=null) {
    const titles = { success:'Success', error:'Error', info:'Notice' };
    return showDialog({ type, title: title || titles[type] || 'Notice', message: msg, confirmText: 'OK' });
  }

  function showConfirm(msg, opts={}) {
    return showDialog({
      type: opts.type || 'info',
      title: opts.title || 'Confirm',
      message: msg,
      confirmText: opts.confirmText || 'Yes',
      cancelText: opts.cancelText || 'No',
      showCancel: true,
    });
  }

  // ======================================================
  // QUICK DATE CHIPS
  // ======================================================
  const form    = document.getElementById('rpt-filters');
  const startIn = document.getElementById('rpt-start');
  const endIn   = document.getElementById('rpt-end');

  function toISO(d) {
    return d.getFullYear() + '-'
      + String(d.getMonth() + 1).padStart(2, '0') + '-'
      + String(d.getDate()).padStart(2, '0');
  }

  document.querySelectorAll('.rpt-chip[data-range]').forEach(btn => {
    btn.addEventListener('click', () => {
      const days  = parseInt(btn.dataset.range, 10);
      const today = new Date();
      const start = new Date();
      start.setDate(today.getDate() - (days - 1));
      if (startIn) startIn.value = toISO(start);
      if (endIn)   endIn.value   = toISO(today);
      if (form) form.submit();
    });
  });

  // ======================================================
  // RESTOCK FUNCTIONALITY (Store Usage tab)
  // ======================================================
  const selectAll  = document.getElementById('rpt-select-all');
  const restockBtn = document.getElementById('rpt-restock-btn');
  const selCount   = document.getElementById('rpt-sel-count');
  const items      = document.querySelectorAll('.rpt-restock-item');

  if (selectAll && restockBtn && items.length > 0) {
    const updateCount = () => {
      const checked = document.querySelectorAll('.rpt-restock-item:checked');
      const n = checked.length;
      selCount.textContent = n;
      restockBtn.disabled = n === 0;
      selectAll.checked = n === items.length;
      selectAll.indeterminate = n > 0 && n < items.length;
    };

    selectAll.addEventListener('change', () => {
      items.forEach(cb => cb.checked = selectAll.checked);
      updateCount();
    });

    items.forEach(cb => cb.addEventListener('change', updateCount));

    restockBtn.addEventListener('click', async () => {
      const checked = document.querySelectorAll('.rpt-restock-item:checked');
      if (checked.length === 0) {
        await showAlert('Please select items to restock.', 'info', 'No Items Selected');
        return;
      }

      const payload = Array.from(checked).map(cb => ({
        product_id: parseInt(cb.dataset.productId, 10),
        quantity:   parseInt(cb.dataset.quantity, 10),
        store_id:   parseInt(cb.dataset.storeId, 10),
      }));

      restockBtn.disabled = true;
      restockBtn.textContent = 'Adding…';

      try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res  = await fetch('/api/transactions.php?action=restock', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ csrf, items: payload }),
        });
        const data = await res.json();

        if (data.success) {
          items.forEach(cb => cb.checked = false);
          selectAll.checked = false;
          updateCount();
          const go = await showConfirm(
            (data.message || `Added ${data.added} item(s) to cart.`) + ' Go to cart now?',
            { type: 'success', title: 'Items Added', confirmText: 'Go to Cart', cancelText: 'Stay Here' }
          );
          if (go) window.location.href = '/cart';
        } else {
          await showAlert(data.error || 'Failed to add items.', 'error', 'Error');
        }
      } catch (err) {
        await showAlert('An error occurred. Please try again.', 'error', 'Error');
      } finally {
        restockBtn.disabled = false;
        restockBtn.textContent = 'Add to Cart';
        updateCount();
      }
    });
  }

})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
