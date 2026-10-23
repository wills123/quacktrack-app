<?php
$active_tab = $active_tab ?? 'templates';
$can_manage = $can_manage ?? false;
$can_ship = $can_ship ?? false;
$active_id = $active_id ?? 0;
$can_view_shipments = $can_view_shipments ?? false;
$active_shipment = $active_shipment ?? null;
$templates = $templates ?? [];
$active_template = $active_template ?? null;
$active_items = $active_items ?? [];
$shipments = $shipments ?? [];
$can_manage_shipments = $can_manage_shipments ?? false;
$canApproveDeploymentShipments = $canApproveDeploymentShipments ?? false;
$can_receive_deployment_shipments = $can_receive_deployment_shipments ?? false;
$stores = $stores ?? [];
require __DIR__ . '/header.php';
?>

<style>
/* ============================================================
   KIT — Operational Dashboard Design Spec (rpt-* extended)
   ============================================================ */

:root {
  --kit-green:      var(--od-green, #006A4E);
  --kit-green-dark: var(--od-green-dark, #004f3b);
  --kit-yellow:     #FFD42A;
  --kit-ink:        var(--od-ink, #0f1f17);
  --kit-mist:       var(--od-mist, #f3f7f5);
  --kit-border:     var(--od-border, #e2ebe7);
  --kit-radius:     var(--od-radius, 3px);
  --kit-mono:       'SF Mono', 'Roboto Mono', 'Courier New', monospace;

  /* Light mode semantic tokens */
  --kit-bg:         var(--od-bg, #f3f7f5);
  --kit-card-bg:    var(--od-card-bg, #ffffff);
  --kit-sidebar-bg: var(--od-sidebar-bg, #ffffff);
  --kit-text:       var(--od-text, #1a1a1a);
  --kit-text-muted: var(--od-muted, #6b7280);
  --kit-row-hover:  var(--od-row-hover, rgba(0,106,78,.03));
  --kit-input-bg:   var(--od-input-bg, #ffffff);
}

/* ── Dark Mode ─────────────────────────────────────────────── */
[data-kit-theme="dark"] {
  --kit-green:      var(--od-green);
  --kit-green-dark: var(--od-green-dark);
  --kit-yellow:     #FFD42A;
  --kit-ink:        var(--od-ink);
  --kit-mist:       var(--od-mist);
  --kit-border:     var(--od-border);

  --kit-bg:         var(--od-bg);
  --kit-card-bg:    var(--od-card-bg);
  --kit-sidebar-bg: var(--od-sidebar-bg);
  --kit-text:       var(--od-text);
  --kit-text-muted: var(--od-muted);
  --kit-row-hover:  var(--od-row-hover);
  --kit-input-bg:   var(--od-input-bg);
}

/* ── Escape main container ───────────────────────────────── */
#main-content.main-content {
  padding: 0 !important;
  margin: 0 !important;
  max-width: none !important;
}
/* ============================================================
   MASTHEAD
   ============================================================ */
.kit-masthead {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--kit-green);
  padding: 0.875rem 2rem 0;
  overflow: hidden;
}

.kit-masthead::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
  background-size: 32px 32px;
  pointer-events: none;
}

.kit-masthead::after {
  content: '';
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 3px;
  background: var(--kit-yellow);
}

.kit-masthead-inner {
  position: relative;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.kit-masthead-title {
  font-size: 1.5rem;
  font-weight: 800;
  color: #fff;
  margin: 0;
  letter-spacing: -0.02em;
  line-height: 1.1;
}

/* ── Sticky header wrapper (masthead + stat rail as one unit) */
.kit-header-wrap {
  position: sticky;
  top: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
}

/* Override the masthead's own sticky — it's now part of the wrapper */
.kit-header-wrap .kit-masthead {
  position: relative;
  top: auto;
  z-index: auto;
}

/* ============================================================
   STAT RAIL
   ============================================================ */
.kit-stat-rail {
  position: relative;
  top: auto;
  z-index: auto;
  display: flex;
  background: var(--kit-green-dark);
  border-bottom: 3px solid var(--kit-yellow);
  overflow-x: auto;
}

.kit-stat {
  flex: 1;
  min-width: 100px;
  padding: 0.5rem 1.125rem;
  border-right: 1px solid rgba(255,255,255,.08);
  display: flex;
  align-items: center;
  gap: 0.625rem;
}
.kit-stat:last-child { border-right: none; }

.kit-stat-value {
  font-size: 1.25rem;
  font-weight: 800;
  color: #fff;
  font-family: var(--kit-mono);
  line-height: 1;
}

.kit-stat-label {
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: rgba(255,255,255,.5);
  line-height: 1.3;
}

/* ============================================================
   BODY / LAYOUT
   ============================================================ */
.kit-body {
  background: var(--kit-bg);
  min-height: calc(100vh - 160px);
  padding: 0;
  display: flex;
  flex-direction: column;
}

.kit-layout {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 0;
  align-items: start;
  flex: 1;
}

/* ============================================================
   SIDEBAR (kit list / shipment list)
   ============================================================ */
.kit-sidebar {
  position: sticky;
  top: 0;
  background: var(--kit-sidebar-bg, #fff);
  border-right: 1px solid var(--kit-border);
  height: 100vh;
  overflow-y: auto;
  padding: 1rem;
}

.kit-sidebar-hdr {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #888;
  padding: 0 0 0.5rem;
  margin-bottom: 0.5rem;
  border-bottom: 1px solid var(--kit-border);
}

.kit-list {
  list-style: none;
  margin: 0;
  padding: 0;
  background: var(--kit-card-bg);
  border: 1px solid var(--kit-border);
  border-radius: var(--kit-radius);
  overflow: hidden;
}

.kit-list-item {
  border-bottom: 1px solid var(--kit-border);
}
.kit-list-item:last-child { border-bottom: none; }

.kit-list-link {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 0.625rem 0.875rem;
  text-decoration: none;
  color: inherit;
  transition: background .1s;
  border-left: 3px solid transparent;
}

.kit-list-link:hover { background: var(--kit-mist); }

.kit-list-item--active .kit-list-link {
  background: var(--kit-row-hover);
  border-left-color: var(--kit-green);
}

.kit-list-item--archived .kit-list-name { color: var(--kit-text-muted); }

.kit-list-name {
  font-weight: 600;
  font-size: 0.8125rem;
  color: var(--kit-text);
  line-height: 1.3;
}

.kit-list-meta {
  font-size: 0.6875rem;
  color: var(--kit-text-muted);
  line-height: 1.4;
}

/* sidebar new-kit btn */
.kit-sidebar-new {
  display: block;
  width: 100%;
  padding: 0.4375rem 0.875rem;
  margin-bottom: 0.75rem;
  font-size: 0.8125rem;
  font-weight: 700;
  background: var(--kit-green);
  color: #fff;
  border: none;
  border-radius: var(--kit-radius);
  cursor: pointer;
  text-align: center;
  transition: background .12s;
  text-decoration: none;
}
.kit-sidebar-new:hover { background: var(--kit-green-dark); }

/* ============================================================
   DETAIL PANEL
   ============================================================ */
.kit-detail {
  padding: 1.25rem 1.5rem;
  min-height: 100vh;
}

.kit-detail-empty {
  background: var(--kit-card-bg);
  border: 1px solid var(--kit-border);
  border-radius: var(--kit-radius);
  padding: 3rem 2rem;
  text-align: center;
}
.kit-detail-empty-eyebrow {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--kit-green);
  margin-bottom: 0.375rem;
}
.kit-detail-empty-msg {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--kit-text);
}
.kit-detail-empty-sub {
  font-size: 0.8125rem;
  color: var(--kit-text-muted);
  margin-top: 0.25rem;
}

/* ── Detail card ─────────────────────────────────────────── */
.kit-card {
  background: var(--kit-card-bg);
  border: 1px solid var(--kit-border);
  border-radius: var(--kit-radius);
  overflow: hidden;
  margin-bottom: 1rem;
}

.kit-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1.125rem;
  border-bottom: 1px solid var(--kit-border);
  background: linear-gradient(to right, var(--kit-mist), var(--kit-card-bg));
  gap: 0.75rem;
}

.kit-card-title {
  font-size: 0.875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--kit-text);
  margin: 0;
}

.kit-card-sub {
  font-size: 0.75rem;
  color: var(--kit-text-muted);
  margin: 0.125rem 0 0;
  font-weight: 400;
  text-transform: none;
  letter-spacing: 0;
}

.kit-card-actions {
  display: flex;
  gap: 0.375rem;
  flex-shrink: 0;
  flex-wrap: wrap;
}

/* ── Meta row (shipment status/notes) ───────────────────── */
.kit-meta-row {
  display: flex;
  gap: 2rem;
  flex-wrap: wrap;
  padding: 0.875rem 1.125rem;
  border-bottom: 1px solid var(--kit-border);
  background: var(--kit-card-bg);
}

.kit-meta-block {}

.kit-meta-label {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--kit-text-muted);
  margin-bottom: 0.25rem;
}

.kit-meta-value {
  font-size: 0.875rem;
  color: var(--kit-text);
  font-weight: 500;
}

/* ============================================================
   TABLES
   ============================================================ */
.kit-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}

.kit-table thead th {
  padding: 0.5625rem 0.875rem;
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: rgba(255,255,255,.75);
  background: var(--kit-ink);
  white-space: nowrap;
}

.kit-table thead th:first-child {
  border-left: 3px solid var(--kit-yellow);
}

.kit-table tbody td {
  padding: 0.5625rem 0.875rem;
  color: var(--kit-text);
  border-bottom: 1px solid var(--kit-border);
  vertical-align: middle;
}

.kit-table tbody tr:last-child td { border-bottom: none; }
.kit-table tbody tr { transition: background .1s; }
.kit-table tbody tr:hover { background: var(--kit-row-hover); }
.kit-table tbody tr:hover td:first-child {
  border-left: 3px solid var(--kit-green);
  padding-left: calc(0.875rem - 3px);
}

.kit-col-num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.kit-muted   { color: var(--kit-text-muted); font-style: italic; }
.kit-mono    { font-family: var(--kit-mono); font-size: 0.75rem; color: var(--kit-text-muted); }

.kit-item-name { font-weight: 600; color: var(--kit-text); line-height: 1.3; }
.kit-item-sub  { font-family: var(--kit-mono); font-size: 0.6875rem; color: var(--kit-text-muted); margin-top: 0.125rem; }

/* ============================================================
   BADGES
   ============================================================ */
.kit-badge {
  display: inline-block;
  padding: 0.1875rem 0.5rem;
  border-radius: 2px;
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.kit-badge--catalog    { background: #dbeafe; color: #1e40af; }
.kit-badge--snipe      { background: #fef3c7; color: #92400e; }
.kit-badge--tracked    { background: #d1fae5; color: #065f46; }
.kit-badge--archived   { background: #f3f4f6; color: #6b7280; }
.kit-badge--dispatched { background: #dbeafe; color: #1e40af; }
.kit-badge--received   { background: #d1fae5; color: #065f46; }
.kit-badge--cancelled  { background: #f3f4f6; color: #6b7280; }
.kit-badge--fulfilled  { background: #d1fae5; color: #065f46; }
.kit-badge--partial    { background: #fef3c7; color: #92400e; }
.kit-badge--unavailable{ background: #fde8e8; color: #b91c1c; }
.kit-badge--pending    { background: #f3f4f6; color: #6b7280; }
.kit-badge--preparing,
.kit-badge--dispatching { background: #fef3c7; color: #92400e; }
.kit-badge--partially_received { background: #ffedd5; color: #9a3412; }
.kit-badge--declined { background: #fde8e8; color: #b91c1c; }
.kit-badge--warning    { background: #fef3c7; color: #92400e; }

/* ============================================================
   ACTION BUTTONS (inside detail panel)
   ============================================================ */
.kit-btn {
  display: inline-flex;
  align-items: center;
  padding: 0.3125rem 0.75rem;
  font-size: 0.8125rem;
  font-weight: 700;
  border-radius: var(--kit-radius);
  cursor: pointer;
  border: 1px solid transparent;
  transition: all .12s;
  text-decoration: none;
  white-space: nowrap;
  line-height: 1.5;
}

.kit-btn--primary { background: var(--kit-green); color: #fff; }
.kit-btn--primary:hover { background: var(--kit-green-dark); }

.kit-btn--ghost {
  background: transparent;
  color: var(--kit-green);
  border-color: var(--kit-green);
}
.kit-btn--ghost:hover { background: var(--kit-green); color: #fff; }

.kit-btn--danger {
  background: transparent;
  color: #b91c1c;
  border-color: #b91c1c;
}
.kit-btn--danger:hover { background: #b91c1c; color: #fff; }

.kit-btn--sm { padding: 0.1875rem 0.5625rem; font-size: 0.75rem; }
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
.kit-empty {
  padding: 2.5rem 1.5rem;
  text-align: center;
}
.kit-empty-eyebrow {
  font-size: 0.6875rem;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--kit-green);
  margin-bottom: 0.375rem;
}
.kit-empty-msg  { font-size: 0.9375rem; font-weight: 600; color: var(--kit-text); margin-bottom: 0.25rem; }
.kit-empty-sub  { font-size: 0.8125rem; color: var(--kit-text-muted); }

/* ============================================================
   MODAL (redesigned with dark header)
   ============================================================ */
.kit-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.55);
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.kit-modal-overlay.show { display: flex; }

.kit-modal {
  background: var(--kit-card-bg);
  border-radius: var(--kit-radius);
  box-shadow: 0 24px 48px rgba(0,0,0,.35);
  width: 90%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  transform: scale(.95) translateY(-12px);
  transition: transform .2s;
  animation: kit-modal-in .2s ease forwards;
}

.kit-modal--wide { max-width: 560px; }

@keyframes kit-modal-in {
  from { opacity: 0; transform: scale(.95) translateY(-12px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}

.kit-modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem;
  background: var(--kit-ink);
  border-radius: var(--kit-radius) var(--kit-radius) 0 0;
}

.kit-modal-title {
  font-size: 0.9375rem;
  font-weight: 700;
  color: #fff;
  margin: 0;
  letter-spacing: .01em;
}

.kit-modal-close {
  background: none;
  border: none;
  color: rgba(255,255,255,.6);
  font-size: 1.25rem;
  cursor: pointer;
  line-height: 1;
  padding: 0.25rem;
  transition: color .1s;
}
.kit-modal-close:hover { color: #fff; }

.kit-modal-body {
  padding: 1.125rem 1.25rem;
  color: var(--kit-text);
}

.kit-modal-foot {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  padding: 0.75rem 1.25rem;
  background: var(--kit-bg);
  border-top: 1px solid var(--kit-border);
  border-radius: 0 0 var(--kit-radius) var(--kit-radius);
}

/* ── Form elements inside modal ─────────────────────────── */
.kit-form-group {
  margin-bottom: 1rem;
}
.kit-form-group:last-child { margin-bottom: 0; }

.kit-form-group label {
  display: block;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: .05em;
  text-transform: uppercase;
  color: var(--kit-text-muted);
  margin-bottom: 0.3125rem;
}

.kit-form-group input[type="text"],
.kit-form-group input[type="number"],
.kit-form-group select,
.kit-form-group textarea {
  width: 100%;
  padding: 0.4375rem 0.625rem;
  border: 1px solid var(--kit-border);
  border-radius: var(--kit-radius);
  font-size: 0.875rem;
  background: var(--kit-input-bg);
  color: var(--kit-text);
  transition: border-color .12s, box-shadow .12s;
  box-sizing: border-box;
}

.kit-form-group input:focus,
.kit-form-group select:focus,
.kit-form-group textarea:focus {
  outline: none;
  border-color: var(--kit-green);
  box-shadow: 0 0 0 2px rgba(0,106,78,.12);
}

.kit-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.kit-form-hint {
  font-size: 0.75rem;
  color: #888;
  margin: 0.375rem 0 0;
  line-height: 1.4;
}

/* ── Item search ─────────────────────────────────────────── */
.kit-search-results {
  max-height: 220px;
  overflow-y: auto;
  border: 1px solid var(--kit-border);
  border-radius: var(--kit-radius);
  background: var(--kit-card-bg);
  display: none;
  margin-top: 0.375rem;
}
.kit-search-results.has-results { display: block; }

.kit-result-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  border-bottom: 1px solid var(--kit-border);
  transition: background .1s;
}
.kit-result-item:last-child { border-bottom: none; }
.kit-result-item:hover { background: var(--kit-mist); }
.kit-result-name { font-weight: 600; font-size: 0.8125rem; color: var(--kit-text); }
.kit-result-sub  { font-size: 0.6875rem; color: var(--kit-text-muted); }

.kit-selected-item {
  padding: 0.5rem 0.75rem;
  background: var(--kit-row-hover);
  border: 1px solid var(--kit-green);
  border-radius: var(--kit-radius);
  font-size: 0.875rem;
  margin-bottom: 0.75rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  color: var(--kit-text);
}

.kit-btn-link {
  background: none;
  border: none;
  color: var(--kit-green);
  cursor: pointer;
  font-size: 0.75rem;
  padding: 0;
  text-decoration: underline;
  white-space: nowrap;
}

/* serial verify row */
.kit-serial-row {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  margin-bottom: 0.5rem;
  flex-wrap: wrap;
}
.kit-serial-row label {
  flex: 1;
  min-width: 120px;
  font-size: 0.8125rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-transform: none;
  letter-spacing: 0;
  font-weight: 500;
  color: var(--kit-text);
}
.kit-serial-row input[type="text"] {
  flex: 2;
  min-width: 140px;
}
.kit-serial-status {
  font-size: 0.75rem;
  white-space: nowrap;
  flex-shrink: 0;
}

/* asset ID list in shipment detail */
.kit-asset-ids {
  font-family: var(--kit-mono);
  font-size: 0.6875rem;
  color: #888;
  margin-top: 0.1875rem;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
  .kit-layout { grid-template-columns: 1fr; }
  .kit-sidebar { position: static; height: auto; border-right: none; border-bottom: 1px solid var(--kit-border); }
  .kit-stat-rail { flex-wrap: wrap; }
}

/* ── Dark mode — app shell overrides ─────────────────────── */
[data-kit-theme="dark"] .app-main { background: var(--kit-bg); }
[data-kit-theme="dark"] body { background: var(--bg); }

/* ── Dark mode — extra overrides ──────────────────────────── */
[data-kit-theme="dark"] .kit-sidebar-hdr { color: var(--kit-text-muted); }
[data-kit-theme="dark"] .kit-form-hint { color: var(--kit-text-muted); }
[data-kit-theme="dark"] .kit-modal-body { color: var(--kit-text); }
[data-kit-theme="dark"] .kit-form-group input,
[data-kit-theme="dark"] .kit-form-group select,
[data-kit-theme="dark"] .kit-form-group textarea {
  background: var(--kit-input-bg) !important;
  color: var(--kit-text) !important;
  border-color: var(--kit-border) !important;
}
[data-kit-theme="dark"] .kit-form-group input::placeholder,
[data-kit-theme="dark"] .kit-form-group textarea::placeholder {
  color: var(--kit-text-muted);
}
[data-kit-theme="dark"] .kit-search-results,
[data-kit-theme="dark"] .kit-selected-item {
  background: var(--kit-card-bg);
  border-color: var(--kit-border);
  color: var(--kit-text);
}
[data-kit-theme="dark"] .kit-result-item:hover { background: var(--kit-row-hover); }
[data-kit-theme="dark"] .kit-form-hint[style*="color:#dc2626"] { color: #fca5a5 !important; }
[data-kit-theme="dark"] .kit-card-title span[style*="color:#888"] { color: var(--kit-text-muted) !important; }
[data-kit-theme="dark"] .kit-btn--ghost { color: var(--kit-green); border-color: var(--kit-green); }
[data-kit-theme="dark"] .kit-btn-link { color: var(--kit-green); }
[data-kit-theme="dark"] .kit-asset-ids { color: var(--kit-text-muted); }
[data-kit-theme="dark"] .kit-badge--catalog    { background: #1e3a8a; color: #93c5fd; }
[data-kit-theme="dark"] .kit-badge--snipe      { background: #451a03; color: #fcd34d; }
[data-kit-theme="dark"] .kit-badge--tracked    { background: #052e16; color: #6ee7b7; }
[data-kit-theme="dark"] .kit-badge--archived   { background: #1f2937; color: #9ca3af; }
[data-kit-theme="dark"] .kit-badge--dispatched { background: #1e3a8a; color: #93c5fd; }
[data-kit-theme="dark"] .kit-badge--received   { background: #052e16; color: #6ee7b7; }
[data-kit-theme="dark"] .kit-badge--cancelled  { background: #1f2937; color: #9ca3af; }
[data-kit-theme="dark"] .kit-badge--fulfilled  { background: #052e16; color: #6ee7b7; }
[data-kit-theme="dark"] .kit-badge--partial    { background: #451a03; color: #fcd34d; }
[data-kit-theme="dark"] .kit-badge--unavailable{ background: #450a0a; color: #fca5a5; }
[data-kit-theme="dark"] .kit-badge--pending    { background: #1f2937; color: #9ca3af; }
[data-kit-theme="dark"] .kit-badge--preparing,
[data-kit-theme="dark"] .kit-badge--dispatching { background: #451a03; color: #fcd34d; }
[data-kit-theme="dark"] .kit-badge--partially_received { background: #431407; color: #fdba74; }
[data-kit-theme="dark"] .kit-badge--declined { background: #450a0a; color: #fca5a5; }
[data-kit-theme="dark"] .kit-badge--warning    { background: #451a03; color: #fcd34d; }

</style>

<script>
// Apply saved theme immediately + signal od-page active (hides legacy topbar)
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme','dark');
  }
  /* Fallback for browsers without CSS :has() */
  var main = document.querySelector('.app-main');
  if (main) main.classList.add('od-active');
})();
</script>

<?php
// ── Flash messages ────────────────────────────────────────────────────────
foreach (flashes() as [$type, $msg]) :
    $flash_cls = match ($type) {
        'success' => 'kit-badge--received', 'error' => 'kit-badge--unavailable', default => 'kit-badge--warning'
    };
    ?>
<div style="padding: 0.75rem 1.5rem; background: <?= $type === 'error' ? '#fde8e8' : ($type === 'success' ? '#d1fae5' : '#fef3c7') ?>; border-bottom: 1px solid var(--kit-border); font-size: 0.875rem; color: <?= $type === 'error' ? '#b91c1c' : ($type === 'success' ? '#065f46' : '#92400e') ?>;">
    <?= h($msg) ?>
</div>
<?php endforeach; ?>

<!-- ======================================================
     STICKY HEADER (masthead + stat rail as one unit)
     ====================================================== -->
<div class="kit-header-wrap">
<div class="page-masthead kit-masthead">
  <div class="kit-masthead-inner">
    <div class="kit-masthead-brand">
      <h1 class="kit-masthead-title">Kits &amp; Shipments</h1>
    </div>
    <div class="masthead-cluster">

      <!-- Action buttons -->
      <div class="masthead-tabs kit-masthead-actions">
        <?php if ($can_manage && $active_tab !== 'shipments') : ?>
          <button type="button" class="masthead-tab" id="createKitBtn">+ New Kit</button>
        <?php endif; ?>
        <?php if ($can_ship) : ?>
          <button type="button" class="masthead-tab" id="shipKitBtn">Ship a Kit</button>
        <?php endif; ?>
      </div>

      <!-- Tab strip -->
      <nav class="masthead-tabs" role="tablist">
        <a href="/kits?tab=templates<?= $active_id ? '&id=' . $active_id : '' ?>"
           class="masthead-tab <?= $active_tab !== 'shipments' ? 'active' : '' ?>"
           role="tab" aria-selected="<?= $active_tab !== 'shipments' ? 'true' : 'false' ?>">
          Templates
        </a>
        <?php if ($can_view_shipments) : ?>
        <a href="/kits?tab=shipments<?= $active_shipment ? '&shipment=' . $active_shipment['id'] : '' ?>"
           class="masthead-tab <?= $active_tab === 'shipments' ? 'active' : '' ?>"
           role="tab" aria-selected="<?= $active_tab === 'shipments' ? 'true' : 'false' ?>">
          Shipments
        </a>
        <?php endif; ?>
      </nav>
    </div>
  </div>
</div>

<!-- ======================================================
     STAT RAIL
     ====================================================== -->
<div class="page-stat-rail kit-stat-rail">
  <?php if ($active_tab !== 'shipments') : ?>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= count(array_filter($templates, fn($t) => $t['is_active'])) ?></div>
        <div class="kit-stat-label">Active Kits</div>
      </div>
    </div>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= count(array_filter($templates, fn($t) => !$t['is_active'])) ?></div>
        <div class="kit-stat-label">Archived</div>
      </div>
    </div>
        <?php if ($active_template) : ?>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= count($active_items) ?></div>
        <div class="kit-stat-label">Items in Kit</div>
      </div>
    </div>
        <?php endif; ?>
  <?php else : ?>
      <?php
        $pending_count    = count(array_filter($shipments, fn($s) => $s['status'] === 'pending'));
        $preparing_count  = count(array_filter($shipments, fn($s) => in_array($s['status'], ['preparing', 'dispatching'], true)));
        $dispatched_count = count(array_filter($shipments, fn($s) => $s['status'] === 'dispatched'));
        $partial_count    = count(array_filter($shipments, fn($s) => $s['status'] === 'partially_received'));
        $received_count   = count(array_filter($shipments, fn($s) => $s['status'] === 'received'));
        $declined_count   = count(array_filter($shipments, fn($s) => $s['status'] === 'declined'));
        $cancelled_count  = count(array_filter($shipments, fn($s) => $s['status'] === 'cancelled'));
        $issue_count      = array_sum(array_column($shipments, 'issue_count'));
        ?>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= count($shipments) ?></div>
        <div class="kit-stat-label">Total Shipments</div>
      </div>
    </div>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= $pending_count ?></div>
        <div class="kit-stat-label">Pending Approval</div>
      </div>
    </div>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= $preparing_count ?></div>
        <div class="kit-stat-label">Preparing</div>
      </div>
    </div>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= $dispatched_count ?></div>
        <div class="kit-stat-label">Dispatched</div>
      </div>
    </div>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= $partial_count ?></div>
        <div class="kit-stat-label">Partial Receipt</div>
      </div>
    </div>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= $received_count ?></div>
        <div class="kit-stat-label">Received</div>
      </div>
    </div>
      <?php if ($declined_count + $cancelled_count > 0) : ?>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value"><?= $declined_count + $cancelled_count ?></div>
        <div class="kit-stat-label">Closed</div>
      </div>
    </div>
      <?php endif; ?>
      <?php if ($issue_count > 0) : ?>
    <div class="kit-stat">
      <div>
        <div class="kit-stat-value" style="color: var(--kit-yellow);"><?= $issue_count ?></div>
        <div class="kit-stat-label">Issues</div>
      </div>
    </div>
      <?php endif; ?>
  <?php endif; ?>
</div><!-- /.kit-stat-rail -->
</div><!-- /.kit-header-wrap -->

<!-- ======================================================
     BODY
     ====================================================== -->
<div class="kit-body">

<?php if ($active_tab !== 'shipments') : ?>
<!-- ====================================================== -->
<!-- TEMPLATES TAB                                          -->
<!-- ====================================================== -->
<div class="kit-layout">

  <!-- Sidebar: kit list -->
  <aside class="kit-sidebar">
    <?php if ($can_manage) : ?>
      <button type="button" class="kit-sidebar-new" id="createKitBtnEmpty">+ New Kit</button>
    <?php endif; ?>
    <div class="kit-sidebar-hdr">Kit Templates</div>
    <?php if (empty($templates)) : ?>
      <div class="kit-detail-empty" style="padding: 1.5rem 1rem;">
        <div class="kit-detail-empty-msg">No kits yet</div>
        <div class="kit-detail-empty-sub">Create your first kit template above</div>
      </div>
    <?php else : ?>
      <ul class="kit-list" role="list">
        <?php foreach ($templates as $tpl) : ?>
            <?php $isActive = (int)$tpl['id'] === (int)($active_template['id'] ?? 0); ?>
          <li class="kit-list-item <?= $isActive ? 'kit-list-item--active' : '' ?> <?= !$tpl['is_active'] ? 'kit-list-item--archived' : '' ?>">
            <a href="/kits?tab=templates&id=<?= (int)$tpl['id'] ?>" class="kit-list-link">
              <span class="kit-list-name"><?= h($tpl['name']) ?></span>
              <span class="kit-list-meta">
                <?= (int)$tpl['item_count'] ?> item<?= $tpl['item_count'] != 1 ? 's' : '' ?>
                <?php if (!$tpl['is_active']) : ?>
                  &nbsp;<span class="kit-badge kit-badge--archived">Archived</span>
                <?php endif; ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>

  <!-- Detail panel -->
  <section class="kit-detail">
    <?php if (!$active_template) : ?>
      <div class="kit-detail-empty">
        <div class="kit-detail-empty-eyebrow">Templates</div>
        <div class="kit-detail-empty-msg">Select a kit to view or edit it</div>
        <div class="kit-detail-empty-sub">Or create a new kit template from the sidebar</div>
      </div>
    <?php else : ?>
      <!-- Kit header card -->
      <div class="kit-card">
        <div class="kit-card-header">
          <div>
            <div class="kit-card-title" id="kitDetailName"><?= h($active_template['name']) ?></div>
            <?php if ($active_template['description']) : ?>
              <div class="kit-card-sub"><?= h($active_template['description']) ?></div>
            <?php endif; ?>
          </div>
          <div class="kit-card-actions">
            <?php if ($can_ship && $active_template['is_active']) : ?>
              <button type="button" class="kit-btn kit-btn--primary" id="shipThisKitBtn"
                data-kit-id="<?= (int)$active_template['id'] ?>"
                data-kit-name="<?= h($active_template['name']) ?>">
                Ship This Kit
              </button>
            <?php endif; ?>
            <?php if ($can_manage) : ?>
              <button type="button" class="kit-btn kit-btn--ghost" id="editKitBtn"
                data-id="<?= (int)$active_template['id'] ?>"
                data-name="<?= h($active_template['name']) ?>"
                data-description="<?= h($active_template['description'] ?? '') ?>">
                Edit
              </button>
              <form method="post" action="/kits" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="id" value="<?= (int)$active_template['id'] ?>">
                <button type="submit" class="kit-btn <?= $active_template['is_active'] ? 'kit-btn--danger' : 'kit-btn--ghost' ?>">
                  <?= $active_template['is_active'] ? 'Archive' : 'Restore' ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Items card -->
      <div class="kit-card">
        <div class="kit-card-header">
          <div>
            <div class="kit-card-title">Items <span style="font-weight:400; color:#888;">(<?= count($active_items) ?>)</span></div>
            <div class="kit-card-sub">Products and IT assets included in this kit</div>
          </div>
          <?php if ($can_manage) : ?>
            <button type="button" class="kit-btn kit-btn--primary kit-btn--sm" id="addItemBtn">+ Add Item</button>
          <?php endif; ?>
        </div>

        <?php if (empty($active_items)) : ?>
          <div class="kit-empty">
            <div class="kit-empty-eyebrow">No items</div>
            <div class="kit-empty-msg">This kit is empty</div>
            <div class="kit-empty-sub">Add products from the catalog or Snipe-IT models</div>
          </div>
        <?php else : ?>
          <table class="kit-table">
            <thead>
              <tr>
                <th>Item</th>
                <th>Source</th>
                <th class="kit-col-num">Qty</th>
                <th>Notes</th>
                <?php if ($can_manage) :
                    ?><th></th><?php
                endif; ?>
              </tr>
            </thead>
            <tbody id="kitItemsBody">
              <?php foreach ($active_items as $item) : ?>
                <tr data-item-id="<?= (int)$item['id'] ?>">
                  <td>
                    <?php if ($item['item_type'] === 'product' && $item['product_name']) : ?>
                      <div class="kit-item-name"><?= h($item['product_name']) ?></div>
                        <?php if ($item['product_sku']) : ?>
                        <div class="kit-item-sub"><?= h($item['product_sku']) ?></div>
                        <?php endif; ?>
                    <?php elseif ($item['item_type'] === 'snipe_model') : ?>
                      <div class="kit-item-name"><?= h($item['snipe_model_name'] ?? 'Snipe-IT Model #' . $item['snipe_model_id']) ?></div>
                      <div class="kit-item-sub">Model ID: <?= (int)$item['snipe_model_id'] ?></div>
                    <?php else : ?>
                      <span class="kit-muted">— item removed —</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($item['item_type'] === 'product') : ?>
                      <span class="kit-badge kit-badge--catalog">Catalog</span>
                        <?php if ($item['is_tracked_asset']) : ?>
                        <span class="kit-badge kit-badge--tracked">Tracked</span>
                        <?php endif; ?>
                    <?php else : ?>
                      <span class="kit-badge kit-badge--snipe">Snipe-IT</span>
                    <?php endif; ?>
                  </td>
                  <td class="kit-col-num"><?= (int)$item['quantity'] ?></td>
                  <td class="kit-muted"><?= h($item['notes'] ?? '') ?></td>
                    <?php if ($can_manage) : ?>
                  <td>
                    <button type="button" class="kit-btn kit-btn--danger kit-btn--sm remove-item-btn"
                      data-item-id="<?= (int)$item['id'] ?>">Remove</button>
                  </td>
                    <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </section>
</div>

<?php else : ?>
<!-- ====================================================== -->
<!-- SHIPMENTS TAB                                          -->
<!-- ====================================================== -->
<div class="kit-layout">

  <!-- Sidebar: shipment list -->
  <aside class="kit-sidebar">
    <?php if ($can_ship) : ?>
      <button type="button" class="kit-sidebar-new" id="shipKitBtnSidebar">Ship a Kit</button>
    <?php endif; ?>
    <div class="kit-sidebar-hdr">Shipments</div>
    <?php if (empty($shipments)) : ?>
      <div class="kit-detail-empty" style="padding: 1.5rem 1rem;">
        <div class="kit-detail-empty-msg">No shipments yet</div>
      </div>
    <?php else : ?>
      <ul class="kit-list" role="list">
        <?php foreach ($shipments as $sh) : ?>
            <?php $isActive = $active_shipment && (int)$sh['id'] === (int)$active_shipment['id']; ?>
          <li class="kit-list-item <?= $isActive ? 'kit-list-item--active' : '' ?>">
            <a href="/kits?tab=shipments&shipment=<?= (int)$sh['id'] ?>" class="kit-list-link">
              <span class="kit-list-name"><?= h($sh['kit_name_snapshot']) ?></span>
              <span class="kit-list-meta">
                <?= h($sh['destination_name'] ?: ($sh['store_name'] ?: ($sh['deployment_site_name'] ?: 'Destination pending'))) ?>
                &nbsp;<span class="kit-badge kit-badge--<?= h($sh['status']) ?>"><?= ucfirst(h($sh['status'])) ?></span>
                <?php if ($sh['issue_count'] > 0) : ?>
                  <span class="kit-badge kit-badge--warning"><?= (int)$sh['issue_count'] ?> issue<?= $sh['issue_count'] != 1 ? 's' : '' ?></span>
                <?php endif; ?>
                <?php if (!empty($sh['deployment_id'])) : ?>
                  <br>Deployment request<?= $sh['requested_by_name'] ? ' · ' . h($sh['requested_by_name']) : '' ?>
                <?php endif; ?>
                <br><?= h(date('M j, Y', strtotime($sh['requested_at'] ?? $sh['dispatched_at'] ?? $sh['created_at']))) ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>

  <!-- Shipment detail panel -->
  <section class="kit-detail">
    <?php if (!$active_shipment) : ?>
      <div class="kit-detail-empty">
        <div class="kit-detail-empty-eyebrow">Shipments</div>
        <div class="kit-detail-empty-msg">Select a shipment to view its details</div>
        <div class="kit-detail-empty-sub">Or dispatch a new kit using the button above</div>
      </div>
    <?php else : ?>
        <?php $sh = $active_shipment; ?>

      <!-- Shipment header card -->
      <div class="kit-card">
        <div class="kit-card-header">
          <div>
            <div class="kit-card-title"><?= h($sh['kit_name_snapshot']) ?></div>
            <div class="kit-card-sub">
              <?= !empty($sh['deployment_id']) ? 'Deployment request for' : 'Shipped to' ?>
              <strong><?= h($sh['destination_name'] ?: ($sh['store_name'] ?: ($sh['deployment_site_name'] ?: 'Destination pending'))) ?></strong>
              <?php if ($sh['dispatched_at']) : ?>
                on <?= h(date('M j, Y g:i a', strtotime($sh['dispatched_at']))) ?> by <?= h($sh['dispatched_by_name'] ?? 'Unknown') ?>
              <?php elseif ($sh['requested_at']) : ?>
                requested <?= h(date('M j, Y g:i a', strtotime($sh['requested_at']))) ?><?= $sh['requested_by_name'] ? ' by ' . h($sh['requested_by_name']) : '' ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="kit-card-actions">
            <?php if ($sh['dispatched_at']) : ?>
              <a href="/kits/packing-slip?shipment=<?= (int)$sh['id'] ?>" target="_blank" class="kit-btn kit-btn--ghost">Packing Slip</a>
            <?php endif; ?>
            <?php if (!empty($sh['deployment_id']) && $sh['status'] === 'pending' && $sh['approval_status'] === 'pending' && $canApproveDeploymentShipments) : ?>
              <form method="post" action="/kits" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve_deployment_shipment">
                <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
                <input type="hidden" name="approval_reason" value="">
                <button type="submit" class="kit-btn kit-btn--primary">Approve</button>
              </form>
              <form method="post" action="/kits" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="decline_deployment_shipment">
                <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
                <label class="sr-only" for="declineReason<?= (int)$sh['id'] ?>">Decline reason</label>
                <input id="declineReason<?= (int)$sh['id'] ?>" name="decline_reason" required maxlength="500" placeholder="Reason required">
                <button type="submit" class="kit-btn kit-btn--danger">Decline</button>
              </form>
            <?php endif; ?>
            <?php if (!empty($sh['deployment_id']) && $sh['status'] === 'preparing' && in_array($sh['approval_status'], ['approved', 'bypassed', 'not_required'], true) && $can_ship) : ?>
              <form method="post" action="/kits" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dispatch_deployment_shipment">
                <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
                <label class="sr-only" for="carrier<?= (int)$sh['id'] ?>">Carrier</label>
                <input id="carrier<?= (int)$sh['id'] ?>" name="carrier" maxlength="100" placeholder="Carrier (optional)">
                <label class="sr-only" for="tracking<?= (int)$sh['id'] ?>">Tracking number</label>
                <input id="tracking<?= (int)$sh['id'] ?>" name="tracking_number" maxlength="190" placeholder="Tracking (optional)">
                <button type="submit" class="kit-btn kit-btn--primary">Dispatch</button>
              </form>
            <?php endif; ?>
            <?php if (empty($sh['deployment_id']) && $can_manage_shipments && $sh['status'] === 'dispatched') : ?>
              <form method="post" action="/kits" style="display:inline;"
                data-confirm="Mark this shipment as received?"
                data-confirm-title="Mark Received"
                data-confirm-confirm-text="Mark Received"
                data-confirm-style="primary">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_received">
                <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
                <button type="submit" class="kit-btn kit-btn--primary">Mark Received</button>
              </form>
              <form method="post" action="/kits" style="display:inline;"
                data-confirm="Are you sure you want to cancel this shipment?"
                data-confirm-title="Cancel Shipment"
                data-confirm-confirm-text="Cancel Shipment"
                data-confirm-style="danger">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_shipment">
                <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
                <button type="submit" class="kit-btn kit-btn--danger">Cancel</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Status / meta row -->
        <div class="kit-meta-row">
          <div class="kit-meta-block">
            <div class="kit-meta-label">Status</div>
            <div class="kit-meta-value">
              <span class="kit-badge kit-badge--<?= h($sh['status']) ?>" style="font-size:.8rem; padding: 3px 10px;">
                <?= ucfirst(h($sh['status'])) ?>
              </span>
            </div>
          </div>
          <?php if ($sh['received_at']) : ?>
          <div class="kit-meta-block">
            <div class="kit-meta-label">Received</div>
            <div class="kit-meta-value"><?= h(date('M j, Y', strtotime($sh['received_at']))) ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($sh['deployment_id'])) : ?>
          <div class="kit-meta-block">
            <div class="kit-meta-label">Deployment</div>
            <div class="kit-meta-value"><?= h($sh['deployment_site_name'] ?: 'Deployment #' . $sh['deployment_id']) ?></div>
          </div>
          <div class="kit-meta-block">
            <div class="kit-meta-label">Requested By</div>
            <div class="kit-meta-value"><?= h($sh['requested_by_name'] ?: 'Unknown') ?></div>
          </div>
          <?php endif; ?>
          <?php if ($sh['needed_by']) : ?>
          <div class="kit-meta-block">
            <div class="kit-meta-label">Needed By</div>
            <div class="kit-meta-value"><?= h(date('M j, Y', strtotime($sh['needed_by']))) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($sh['carrier'] || $sh['tracking_number']) : ?>
          <div class="kit-meta-block">
            <div class="kit-meta-label">Tracking</div>
            <div class="kit-meta-value"><?= h(trim(($sh['carrier'] ? $sh['carrier'] . ' · ' : '') . ($sh['tracking_number'] ?? ''))) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($sh['approval_reason']) : ?>
          <div class="kit-meta-block" style="flex:1; min-width:200px;">
            <div class="kit-meta-label"><?= $sh['approval_status'] === 'bypassed' ? 'Approval Bypass Reason' : 'Approval Decision' ?></div>
            <div class="kit-meta-value"><?= h($sh['approval_reason']) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($sh['request_notes']) : ?>
          <div class="kit-meta-block" style="flex:1; min-width:200px;">
            <div class="kit-meta-label">Request Notes</div>
            <div class="kit-meta-value"><?= nl2br(h($sh['request_notes'])) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($sh['notes']) : ?>
          <div class="kit-meta-block" style="flex:1; min-width:200px;">
            <div class="kit-meta-label">Notes</div>
            <div class="kit-meta-value"><?= h($sh['notes']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

        <?php $can_receive_this_shipment = !empty($sh['deployment_id']) && $can_receive_deployment_shipments && in_array($sh['status'], ['dispatched', 'partially_received'], true); ?>
        <?php if ($can_receive_this_shipment) : ?>
      <form method="post" action="/kits">
            <?= csrf_field() ?>
        <input type="hidden" name="action" value="receive_deployment_shipment">
        <input type="hidden" name="shipment_id" value="<?= (int)$sh['id'] ?>">
        <?php endif; ?>

      <!-- Line items card -->
      <div class="kit-card">
        <div class="kit-card-header">
          <div>
            <div class="kit-card-title">Line Items <span style="font-weight:400; color:#888;">(<?= count($sh['items']) ?>)</span></div>
            <div class="kit-card-sub">Fulfillment status for each item in this shipment</div>
          </div>
        </div>

        <?php if (empty($sh['items'])) : ?>
          <div class="kit-empty">
            <div class="kit-empty-msg">No line item data recorded</div>
          </div>
        <?php else : ?>
          <table class="kit-table">
            <thead>
              <tr>
                <th>Item</th>
                <th>Source</th>
                <th class="kit-col-num">Planned</th>
                <th class="kit-col-num">Fulfilled</th>
                <th class="kit-col-num">Received</th>
                <th>Result</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sh['items'] as $line) : ?>
                <tr>
                  <td>
                    <?php if ($line['item_type'] === 'product' && $line['product_name']) : ?>
                      <div class="kit-item-name"><?= h($line['product_name']) ?></div>
                    <?php else : ?>
                      <div class="kit-item-name"><?= h($line['snipe_model_name'] ?? 'Snipe-IT Model #' . $line['snipe_model_id']) ?></div>
                    <?php endif; ?>
                    <?php
                    if ($line['snipe_asset_ids']) {
                        $ids = json_decode($line['snipe_asset_ids'], true) ?? [];
                        if ($ids) : ?>
                          <div class="kit-asset-ids">Asset IDs: <?= implode(', ', array_map('intval', $ids)) ?></div>
                        <?php endif;
                    }
                    ?>
                  </td>
                  <td>
                    <?php if ($line['item_type'] === 'product') : ?>
                      <span class="kit-badge kit-badge--catalog">Catalog</span>
                    <?php else : ?>
                      <span class="kit-badge kit-badge--snipe">Snipe-IT</span>
                    <?php endif; ?>
                  </td>
                  <td class="kit-col-num"><?= (int)$line['quantity_planned'] ?></td>
                  <td class="kit-col-num"><?= (int)$line['quantity_fulfilled'] ?></td>
                  <td class="kit-col-num">
                    <?php if ($can_receive_this_shipment) : ?>
                      <label class="sr-only" for="receivedQty<?= (int)$line['id'] ?>">Received quantity</label>
                      <input id="receivedQty<?= (int)$line['id'] ?>" type="number" name="quantity_received[<?= (int)$line['id'] ?>]" min="<?= (int)($line['quantity_received'] ?? 0) ?>" max="<?= (int)$line['quantity_fulfilled'] ?>" value="<?= (int)$line['quantity_fulfilled'] ?>" style="width:4.5rem;">
                    <?php else : ?>
                        <?= (int)($line['quantity_received'] ?? 0) ?>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="kit-badge kit-badge--<?= h($line['line_status']) ?>">
                      <?= ucfirst(h($line['line_status'])) ?>
                    </span>
                  </td>
                  <td class="kit-muted"><?= h($line['line_notes'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

        <?php if ($can_receive_this_shipment) : ?>
      <div class="kit-card">
        <div class="kit-card-header">
          <div>
            <div class="kit-card-title">Confirm Receipt</div>
            <div class="kit-card-sub">Adjust received quantities for any missing items before confirming.</div>
          </div>
          <div class="kit-card-actions">
            <label class="sr-only" for="receiptNotes">Receipt notes</label>
            <input id="receiptNotes" name="receipt_notes" maxlength="500" placeholder="Receipt notes (optional)">
            <button type="submit" class="kit-btn kit-btn--primary">Confirm Receipt</button>
          </div>
        </div>
      </div>
      </form>
        <?php endif; ?>

        <?php if (!empty($sh['events'])) : ?>
      <div class="kit-card">
        <div class="kit-card-header">
          <div>
            <div class="kit-card-title">Shipment Activity</div>
            <div class="kit-card-sub">Request, approval, dispatch, and receipt history</div>
          </div>
        </div>
        <table class="kit-table">
          <thead><tr><th>When</th><th>Event</th><th>Details</th><th>By</th></tr></thead>
          <tbody>
            <?php foreach ($sh['events'] as $event) : ?>
            <tr>
              <td><?= h(date('M j, Y g:i a', strtotime($event['created_at']))) ?></td>
              <td><span class="kit-badge kit-badge--<?= h($event['event_type'] === 'approved' ? 'preparing' : ($event['event_type'] === 'declined' ? 'declined' : 'pending')) ?>"><?= h(str_replace('_', ' ', $event['event_type'])) ?></span></td>
              <td><?= h($event['message']) ?></td>
              <td><?= h($event['actor_name'] ?: 'System') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
        <?php endif; ?>

    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

</div><!-- /.kit-body -->


<!-- ======================================================
     MODALS
     ====================================================== -->

<!-- Create / Edit kit modal -->
<div id="kitModal" class="kit-modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="kitModalTitle">
  <div class="kit-modal">
    <div class="kit-modal-head">
      <h2 class="kit-modal-title" id="kitModalTitle">New Kit</h2>
      <button type="button" class="kit-modal-close" id="kitModalClose" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="/kits" id="kitForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_template" id="kitFormAction">
      <input type="hidden" name="id" id="kitFormId">
      <div class="kit-modal-body">
        <div class="kit-form-group">
          <label for="kitName">Kit Name <span aria-hidden="true">*</span></label>
          <input type="text" id="kitName" name="name" required maxlength="150" placeholder="e.g. New Store Opening Kit">
        </div>
        <div class="kit-form-group">
          <label for="kitDescription">Description</label>
          <textarea id="kitDescription" name="description" rows="3" placeholder="Optional — describe what this kit is used for"></textarea>
        </div>
      </div>
      <div class="kit-modal-foot">
        <button type="button" class="kit-btn kit-btn--ghost" id="kitModalCancel">Cancel</button>
        <button type="submit" class="kit-btn kit-btn--primary">Save Kit</button>
      </div>
    </form>
  </div>
</div>

<!-- Ship kit modal -->
<?php if ($can_ship) : ?>
<div id="shipModal" class="kit-modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="shipModalTitle">
  <div class="kit-modal kit-modal--wide">
    <div class="kit-modal-head">
      <h2 class="kit-modal-title" id="shipModalTitle">Ship a Kit</h2>
      <button type="button" class="kit-modal-close" id="shipModalClose" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="/kits" id="shipForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_shipment">
      <input type="hidden" name="snipe_serials" id="shipSnipeSerials" value="">
      <div class="kit-modal-body">
        <div class="kit-form-group">
          <label for="shipKitSelect">Kit Template <span aria-hidden="true">*</span></label>
          <select id="shipKitSelect" name="kit_template_id" required>
            <option value="">— choose a kit —</option>
            <?php foreach ($templates as $tpl) : ?>
                <?php if ($tpl['is_active']) : ?>
                <option value="<?= (int)$tpl['id'] ?>"
                    <?= (int)($active_template['id'] ?? 0) === (int)$tpl['id'] ? 'selected' : '' ?>>
                    <?= h($tpl['name']) ?> (<?= (int)$tpl['item_count'] ?> items)
                </option>
                <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="shipSnipeSerialFields"></div>
        <div class="kit-form-group">
          <label for="shipStoreSelect">Destination Store <span aria-hidden="true">*</span></label>
          <select id="shipStoreSelect" name="store_id" required>
            <option value="">— choose a store —</option>
            <?php foreach ($stores as $store) : ?>
              <option value="<?= (int)$store['id'] ?>"><?= h($store['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="kit-form-group">
          <label for="shipNotes">Notes <span style="font-weight:400; text-transform:none; letter-spacing:0;">(optional)</span></label>
          <textarea id="shipNotes" name="notes" rows="2" placeholder="e.g. New store opening — contact Jane on site"></textarea>
        </div>
        <p class="kit-form-hint">
          Dispatching will immediately check out all Snipe-IT assets to the store's location.
          Enter a serial or asset tag per device, or leave blank to auto-assign any available unit.
          Catalog items are marked fulfilled for physical delivery.
        </p>
      </div>
      <div class="kit-modal-foot">
        <button type="button" class="kit-btn kit-btn--ghost" id="shipModalCancel">Cancel</button>
        <button type="submit" class="kit-btn kit-btn--primary" id="shipSubmitBtn">Dispatch Kit</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Add item modal -->
<div id="addItemModal" class="kit-modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="addItemModalTitle">
  <div class="kit-modal kit-modal--wide">
    <div class="kit-modal-head">
      <h2 class="kit-modal-title" id="addItemModalTitle">Add Item to Kit</h2>
      <button type="button" class="kit-modal-close" id="addItemModalClose" aria-label="Close">&times;</button>
    </div>
    <div class="kit-modal-body">
      <div class="kit-form-group">
        <label for="itemSearch">Search catalog or Snipe-IT models</label>
        <input type="text" id="itemSearch" placeholder="Type to search products or models…" autocomplete="off">
        <div id="itemSearchResults" class="kit-search-results" aria-live="polite"></div>
      </div>
      <div id="selectedItemSummary" class="kit-selected-item" style="display:none;">
        <span><strong>Selected:</strong> <span id="selectedItemLabel"></span></span>
        <button type="button" class="kit-btn-link" id="clearSelectedItem">Change</button>
      </div>
      <div id="itemQtySection" style="display:none;">
        <div class="kit-form-row">
          <div class="kit-form-group">
            <label for="itemQty">Quantity</label>
            <input type="number" id="itemQty" min="1" max="999" value="1">
          </div>
          <div class="kit-form-group">
            <label for="itemNotes">Notes <span style="font-weight:400; text-transform:none; letter-spacing:0;">(optional)</span></label>
            <input type="text" id="itemNotes" placeholder="e.g. pre-configured">
          </div>
        </div>
      </div>
    </div>
    <div class="kit-modal-foot">
      <button type="button" class="kit-btn kit-btn--ghost" id="addItemModalCancel">Cancel</button>
      <button type="button" class="kit-btn kit-btn--primary" id="confirmAddItemBtn" disabled>Add to Kit</button>
    </div>
  </div>
</div>

<script>
(function () {
  const kitTemplateId = <?= (int)($active_template['id'] ?? 0) ?>;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

  // ── Modal helpers ────────────────────────────────────────────────────────
  function openModal(el) {
    if (!el) return;
    el.classList.add('show');
    el.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    el.querySelector('input, select, textarea, button')?.focus();
  }
  function closeModal(el) {
    if (!el) return;
    el.classList.remove('show');
    el.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ── Kit create / edit modal ──────────────────────────────────────────────
  const kitModal      = document.getElementById('kitModal');
  const kitModalTitle = document.getElementById('kitModalTitle');
  const kitFormAction = document.getElementById('kitFormAction');
  const kitFormId     = document.getElementById('kitFormId');
  const kitName       = document.getElementById('kitName');
  const kitDesc       = document.getElementById('kitDescription');

  function openKitModal(id, name, description) {
    if (id) {
      kitModalTitle.textContent = 'Edit Kit';
      kitFormAction.value = 'update_template';
      kitFormId.value = id;
      kitName.value = name || '';
      kitDesc.value = description || '';
    } else {
      kitModalTitle.textContent = 'New Kit';
      kitFormAction.value = 'create_template';
      kitFormId.value = '';
      kitName.value = '';
      kitDesc.value = '';
    }
    openModal(kitModal);
  }

  document.getElementById('createKitBtn')?.addEventListener('click', () => openKitModal());
  document.getElementById('createKitBtnEmpty')?.addEventListener('click', () => openKitModal());
  document.getElementById('kitModalClose')?.addEventListener('click', () => closeModal(kitModal));
  document.getElementById('kitModalCancel')?.addEventListener('click', () => closeModal(kitModal));
  document.getElementById('editKitBtn')?.addEventListener('click', function () {
    openKitModal(this.dataset.id, this.dataset.name, this.dataset.description);
  });

  // ── Ship kit modal ───────────────────────────────────────────────────────
  const shipModal        = document.getElementById('shipModal');
  const shipKitSelect    = document.getElementById('shipKitSelect');
  const shipSerialFields = document.getElementById('shipSnipeSerialFields');
  const shipSerialHidden = document.getElementById('shipSnipeSerials');
  const dispatchBtn      = document.getElementById('shipSubmitBtn');

  let serialVerifyState = {};
  let hasSnipeItems = false;

  function resetDispatchBtn() {
    if (!dispatchBtn) return;
    dispatchBtn.disabled = hasSnipeItems;
    dispatchBtn.textContent = 'Dispatch Kit';
  }

  function refreshDispatchState() {
    if (!hasSnipeItems) { if (dispatchBtn) dispatchBtn.disabled = false; return; }
    let allOk = true;
    shipSerialFields?.querySelectorAll('input[data-kit-item-id]').forEach(inp => {
      const val = inp.value.trim();
      const id  = inp.dataset.kitItemId;
      if (val && serialVerifyState[id] !== true) allOk = false;
      if (val && serialVerifyState[id] === true && inp.dataset.verifiedValue !== val) {
        serialVerifyState[id] = null;
        clearSerialStatus(inp);
        allOk = false;
      }
    });
    if (dispatchBtn) dispatchBtn.disabled = !allOk;
  }

  function setSerialStatus(inp, ok, message) {
    const row = inp.closest('.kit-serial-row');
    if (!row) return;
    let badge = row.querySelector('.kit-serial-status');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'kit-serial-status';
      row.appendChild(badge);
    }
    badge.textContent = ok ? '✓ ' + message : '✗ ' + message;
    badge.style.color = ok ? '#16a34a' : '#dc2626';
    inp.style.borderColor = ok ? '#16a34a' : '#dc2626';
  }

  function clearSerialStatus(inp) {
    const row = inp.closest('.kit-serial-row');
    if (!row) return;
    row.querySelector('.kit-serial-status')?.remove();
    inp.style.borderColor = '';
  }

  function loadShipSerialFields(kitId) {
    if (!shipSerialFields) return;
    shipSerialFields.innerHTML = '';
    if (shipSerialHidden) shipSerialHidden.value = '';
    serialVerifyState = {};
    hasSnipeItems = false;
    resetDispatchBtn();
    if (!kitId) return;

    shipSerialFields.innerHTML = '<p class="kit-form-hint">Loading device fields…</p>';

    fetch('/api/kit-templates.php?action=get_kit_snipe_items&kit_template_id=' + encodeURIComponent(kitId), {
      headers: { 'X-CSRF-Token': csrfToken }
    })
      .then(r => r.json())
      .then(data => {
        shipSerialFields.innerHTML = '';
        if (!data.success || !data.items || data.items.length === 0) return;

        hasSnipeItems = true;
        resetDispatchBtn();

        const wrapper = document.createElement('div');
        wrapper.className = 'kit-form-group';

        const heading = document.createElement('label');
        heading.textContent = 'Snipe-IT Device Serials';
        wrapper.appendChild(heading);

        const hint = document.createElement('p');
        hint.className = 'kit-form-hint';
        hint.style.marginBottom = '0.625rem';
        hint.textContent = 'Enter a serial or asset tag for each device, then click Verify. Leave blank to auto-assign any available unit.';
        wrapper.appendChild(hint);

        data.items.forEach(item => {
          const row = document.createElement('div');
          row.className = 'kit-serial-row';
          row.dataset.snipeModelId   = item.snipe_model_id;
          row.dataset.snipeModelName = item.snipe_model_name || '';

          const lbl = document.createElement('label');
          lbl.textContent = item.snipe_model_name || ('Model #' + item.snipe_model_id);
          lbl.setAttribute('for', 'serial_kit_item_' + item.kit_item_id);

          const input = document.createElement('input');
          input.type = 'text';
          input.id   = 'serial_kit_item_' + item.kit_item_id;
          input.placeholder = 'Serial / asset tag (optional)';
          input.dataset.kitItemId     = item.kit_item_id;
          input.dataset.snipeModelId  = item.snipe_model_id;
          input.dataset.verifiedValue = '';
          input.autocomplete = 'off';
          input.spellcheck   = false;
          input.style.flex = '2';
          input.style.minWidth = '140px';

          input.addEventListener('input', () => {
            const id = input.dataset.kitItemId;
            serialVerifyState[id] = null;
            clearSerialStatus(input);
            input.dataset.verifiedValue = '';
            refreshDispatchState();
          });

          row.appendChild(lbl);
          row.appendChild(input);
          wrapper.appendChild(row);
        });

        const verifyBtn = document.createElement('button');
        verifyBtn.type = 'button';
        verifyBtn.id   = 'verifySerialBtn';
        verifyBtn.className = 'kit-btn kit-btn--ghost';
        verifyBtn.style.marginTop = '0.5rem';
        verifyBtn.textContent = 'Verify Serials';
        verifyBtn.addEventListener('click', runSerialVerification);
        wrapper.appendChild(verifyBtn);

        shipSerialFields.appendChild(wrapper);
      })
      .catch(() => {
        shipSerialFields.innerHTML = '<p class="kit-form-hint" style="color:#dc2626;">Could not load device fields. Check your connection and try again.</p>';
      });
  }

  function runSerialVerification() {
    const verifyBtn = document.getElementById('verifySerialBtn');
    if (verifyBtn) { verifyBtn.disabled = true; verifyBtn.textContent = 'Verifying…'; }

    const inputs   = Array.from(shipSerialFields?.querySelectorAll('input[data-kit-item-id]') || []);
    const toVerify = inputs.filter(inp => inp.value.trim() !== '');

    inputs.forEach(inp => {
      if (inp.value.trim() === '') {
        serialVerifyState[inp.dataset.kitItemId] = true;
        clearSerialStatus(inp);
      }
    });

    if (toVerify.length === 0) {
      if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify Serials'; }
      refreshDispatchState();
      return;
    }

    let pending = toVerify.length;
    toVerify.forEach(inp => {
      const serial  = inp.value.trim();
      const modelId = inp.dataset.snipeModelId || '0';
      const itemId  = inp.dataset.kitItemId;

      fetch('/api/kit-templates.php?action=lookup_serial'
          + '&serial=' + encodeURIComponent(serial)
          + '&snipe_model_id=' + encodeURIComponent(modelId), {
        headers: { 'X-CSRF-Token': csrfToken }
      })
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            serialVerifyState[itemId] = false;
            setSerialStatus(inp, false, data.error || 'Lookup failed');
          } else if (!data.found) {
            serialVerifyState[itemId] = false;
            setSerialStatus(inp, false, 'Not found');
          } else if (!data.available) {
            serialVerifyState[itemId] = false;
            setSerialStatus(inp, false, data.message);
          } else {
            serialVerifyState[itemId] = true;
            inp.dataset.verifiedValue = serial;
            const detail = [data.asset_name, data.asset_tag ? '#' + data.asset_tag : ''].filter(Boolean).join(' · ');
            setSerialStatus(inp, true, detail || 'Ready');
          }
        })
        .catch(() => {
          serialVerifyState[itemId] = false;
          setSerialStatus(inp, false, 'Network error');
        })
        .finally(() => {
          pending--;
          if (pending === 0) {
            if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify Serials'; }
            refreshDispatchState();
          }
        });
    });
  }

  function openShipModal(kitId) {
    if (kitId && shipKitSelect) shipKitSelect.value = String(kitId);
    loadShipSerialFields(shipKitSelect?.value || null);
    openModal(shipModal);
  }

  shipKitSelect?.addEventListener('change', function () {
    loadShipSerialFields(this.value || null);
  });

  document.getElementById('shipKitBtn')?.addEventListener('click', () => openShipModal(null));
  document.getElementById('shipKitBtnSidebar')?.addEventListener('click', () => openShipModal(null));
  document.getElementById('shipThisKitBtn')?.addEventListener('click', function () {
    openShipModal(this.dataset.kitId);
  });
  document.getElementById('shipModalClose')?.addEventListener('click', () => closeModal(shipModal));
  document.getElementById('shipModalCancel')?.addEventListener('click', () => closeModal(shipModal));

  document.getElementById('shipForm')?.addEventListener('submit', function (e) {
    const inputs = Array.from(shipSerialFields?.querySelectorAll('input[data-kit-item-id]') || []);
    const unverified = inputs.filter(inp => {
      const val = inp.value.trim();
      return val && serialVerifyState[inp.dataset.kitItemId] !== true;
    });
    if (unverified.length > 0) {
      e.preventDefault();
      unverified.forEach(inp => setSerialStatus(inp, false, 'Please verify before dispatching'));
      return;
    }
    const serialMap = {};
    inputs.forEach(inp => {
      const val = inp.value.trim();
      if (val) serialMap[inp.dataset.kitItemId] = val;
    });
    if (shipSerialHidden) shipSerialHidden.value = JSON.stringify(serialMap);
    if (dispatchBtn) { dispatchBtn.disabled = true; dispatchBtn.textContent = 'Dispatching…'; }
  });

  // ── Add item modal ───────────────────────────────────────────────────────
  const addItemModal    = document.getElementById('addItemModal');
  const itemSearch      = document.getElementById('itemSearch');
  const searchResults   = document.getElementById('itemSearchResults');
  const selectedSummary = document.getElementById('selectedItemSummary');
  const selectedLabel   = document.getElementById('selectedItemLabel');
  const itemQtySection  = document.getElementById('itemQtySection');
  const confirmAddBtn   = document.getElementById('confirmAddItemBtn');
  const itemQtyInput    = document.getElementById('itemQty');
  const itemNotesInput  = document.getElementById('itemNotes');

  let selectedItem = null;
  let searchTimer  = null;

  function resetAddItemModal() {
    if (itemSearch) itemSearch.value = '';
    if (searchResults) { searchResults.innerHTML = ''; searchResults.classList.remove('has-results'); }
    if (selectedSummary) selectedSummary.style.display = 'none';
    if (itemQtySection) itemQtySection.style.display = 'none';
    if (confirmAddBtn) confirmAddBtn.disabled = true;
    if (itemQtyInput) itemQtyInput.value = 1;
    if (itemNotesInput) itemNotesInput.value = '';
    selectedItem = null;
  }

  document.getElementById('addItemBtn')?.addEventListener('click', () => { resetAddItemModal(); openModal(addItemModal); });
  document.getElementById('addItemModalClose')?.addEventListener('click', () => closeModal(addItemModal));
  document.getElementById('addItemModalCancel')?.addEventListener('click', () => closeModal(addItemModal));
  document.getElementById('clearSelectedItem')?.addEventListener('click', () => {
    selectedItem = null;
    if (selectedSummary) selectedSummary.style.display = 'none';
    if (itemQtySection) itemQtySection.style.display = 'none';
    if (confirmAddBtn) confirmAddBtn.disabled = true;
    if (itemSearch) { itemSearch.value = ''; itemSearch.focus(); }
  });

  itemSearch?.addEventListener('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) {
      if (searchResults) { searchResults.innerHTML = ''; searchResults.classList.remove('has-results'); }
      return;
    }
    searchTimer = setTimeout(() => doSearch(q), 280);
  });

  async function doSearch(q) {
    try {
      const res = await fetch('/api/kit-templates.php?action=search_items&q=' + encodeURIComponent(q), {
        headers: { 'X-CSRF-Token': csrfToken }
      });
      const data = await res.json();
      renderSearchResults(data.results ?? []);
    } catch {
      if (searchResults) {
        searchResults.innerHTML = '<div class="kit-result-item"><span class="kit-result-name">Search failed.</span></div>';
        searchResults.classList.add('has-results');
      }
    }
  }

  function renderSearchResults(results) {
    if (!searchResults) return;
    if (!results.length) {
      searchResults.innerHTML = '<div class="kit-result-item"><span class="kit-result-name kit-muted">No results found.</span></div>';
      searchResults.classList.add('has-results');
      return;
    }
    searchResults.innerHTML = results.map(r => `
      <div class="kit-result-item" data-item='${JSON.stringify(r).replace(/'/g, "&#39;")}'>
        <div>
          <div class="kit-result-name">${escHtml(r.name)}</div>
          <div class="kit-result-sub">${escHtml(r.sub ?? '')}</div>
        </div>
        <span class="kit-badge ${r.source === 'catalog' ? 'kit-badge--catalog' : 'kit-badge--snipe'}">
          ${r.source === 'catalog' ? 'Catalog' : 'Snipe-IT'}
        </span>
      </div>
    `).join('');
    searchResults.classList.add('has-results');

    searchResults.querySelectorAll('.kit-result-item').forEach(el => {
      el.addEventListener('click', function () {
        selectedItem = JSON.parse(this.dataset.item);
        if (selectedLabel) selectedLabel.textContent = selectedItem.name + (selectedItem.sub ? ' — ' + selectedItem.sub : '');
        if (selectedSummary) selectedSummary.style.display = 'flex';
        if (itemQtySection) itemQtySection.style.display = 'block';
        if (confirmAddBtn) confirmAddBtn.disabled = false;
        searchResults.innerHTML = '';
        searchResults.classList.remove('has-results');
        itemQtyInput?.focus();
      });
    });
  }

  confirmAddBtn?.addEventListener('click', async function () {
    if (!selectedItem || !kitTemplateId) return;
    this.disabled = true;
    this.textContent = 'Adding…';

    const body = new FormData();
    body.append('action', 'add_item');
    body.append('csrf', csrfToken);
    body.append('kit_template_id', kitTemplateId);
    body.append('item_type', selectedItem.source === 'catalog' ? 'product' : 'snipe_model');
    body.append('product_id', selectedItem.source === 'catalog' ? selectedItem.id : '');
    body.append('snipe_model_id', selectedItem.source === 'snipe' ? (selectedItem.snipe_model_id || selectedItem.id) : '');
    body.append('snipe_model_name', selectedItem.source === 'snipe' ? (selectedItem.snipe_model_name || selectedItem.name) : '');
    body.append('quantity', itemQtyInput?.value || '1');
    body.append('notes', itemNotesInput?.value || '');

    try {
      const res = await fetch('/api/kit-templates.php', { method: 'POST', body });
      const data = await res.json();
      if (data.success) {
        closeModal(addItemModal);
        window.location.reload();
      } else {
        QuackNotify.error(data.error ?? 'Failed to add item.');
        this.disabled = false;
        this.textContent = 'Add to Kit';
      }
    } catch {
      QuackNotify.error('Request failed. Please try again.');
      this.disabled = false;
      this.textContent = 'Add to Kit';
    }
  });

  // ── Remove item ──────────────────────────────────────────────────────────
  document.querySelectorAll('.remove-item-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
      const confirmed = await QuackNotify.confirm('Remove this item from the kit?', {
        title: 'Remove Item',
        confirmText: 'Remove',
        confirmStyle: 'danger',
      });
      if (!confirmed) return;
      const itemId = this.dataset.itemId;
      const body = new FormData();
      body.append('action', 'remove_item');
      body.append('csrf', csrfToken);
      body.append('item_id', itemId);

      try {
        const res = await fetch('/api/kit-templates.php', { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
          this.closest('tr').remove();
        } else {
          QuackNotify.error(data.error ?? 'Failed to remove item.');
        }
      } catch {
        QuackNotify.error('Request failed. Please try again.');
      }
    });
  });

  // ── Close modals on backdrop click ───────────────────────────────────────
  [kitModal, addItemModal, shipModal].forEach(modal => {
    modal?.addEventListener('click', e => { if (e.target === modal) closeModal(modal); });
  });

  function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
