<?php
// app/views/header.php

// Which page is this? The router sets QT_PAGE/QT_ROUTE from the route it resolved, so
// /stock and /stock.php (or /admin and /admin.php) are the same page. Fall back to the
// script name when a page is not served through the router.
$nav_page = $_SERVER['QT_PAGE'] ?? basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$nav_route = $_SERVER['QT_ROUTE'] ?? ('/' . $nav_page . '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<?php if ($nav_page === 'public_form') : ?>
<meta name="referrer" content="no-referrer">
<?php endif; ?>
<meta name="view-transition" content="same-origin">
<title><?= h($title ?? 'Quack Track') ?></title>
<link rel="icon" type="image/png" href="/assets/logo2.png">
<script>
// Dark mode: apply theme before first paint to avoid flash
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
}());
</script>
<!-- Large monolithic file - keeping for sidebar/nav/header styles -->
<link rel="stylesheet" href="<?= h(asset_href('/assets/style.css')) ?>">

<!-- Utility and fix files -->
<link rel="stylesheet" href="<?= h(asset_href('/assets/form-validation.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_href('/assets/media-fix.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_href('/assets/edit-product-modal.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_href('/assets/notifications.css')) ?>">

<!-- NEW MODULAR CSS STRUCTURE (loads after legacy, wins conflicts) -->
<?php foreach (['core/02-reset', 'core/03-base', 'core/04-layout', 'core/05-utilities', 'components/navigation', 'components/shell', 'components/status-badges', 'components/buttons', 'components/cards', 'components/forms', 'components/modals', 'components/tables', 'components/notifications', 'components/modal-theme'] as $mainCss) : ?>
<link rel="stylesheet" href="<?= h(asset_href('/assets/' . $mainCss . '.css')) ?>">
<?php endforeach; ?>

<!-- Canonical token contract. It follows the core/component sheets so the shared and page
     layers resolve their custom properties from one source of truth. -->
<link rel="stylesheet" href="<?= h(asset_href('/assets/tokens.css')) ?>">

<!-- Page-specific CSS (conditionally loaded from pages directory) -->
<?php
$current_page = $nav_page;
$page_css_map = [
  'index' => 'dashboard.css',
  'stock' => 'stock.css',
  'stock_item_detail' => 'stock.css',
  // Page sheets that build on stock.css list it first. They used @import for
  // this, but an @import can't carry a cache-busting version.
  'cart' => ['stock.css', 'cart.css'],
  'products' => 'products.css',
  'orders' => ['stock.css', 'orders.css'],
  'receiving' => ['stock.css', 'orders.css', 'asset-shared.css', 'receiving.css'],
  'admin' => 'admin.css',
  'admin_dashboard' => 'admin.css',  // Also map old name
  'api_keys' => 'api-keys.css',
  'api-keys' => 'api-keys.css',  // Also map hyphenated version
  'region_storage' => ['stock.css', 'region-storage.css'],
  'region-storage' => ['stock.css', 'region-storage.css'],  // Also map hyphenated version
  'travel' => ['stock.css', 'travel.css'],
  'forms' => 'forms.css',
  'form_submit' => 'form-submit.css',
  'assets' => ['stock.css', 'assets.css'],
];

foreach ((array)($page_css_map[$current_page] ?? []) as $pageCss) {
    echo '<link rel="stylesheet" href="' . h(asset_href('/assets/pages/' . $pageCss)) . '">' . "\n";
}
?>

<!-- Transitional shared component: remains after page CSS until every page
     family has migrated its legacy stat selectors. -->
<link rel="stylesheet" href="<?= h(asset_href('/assets/components/stat-rail.css')) ?>">

<!-- Responsive overrides (must load last) -->
<link rel="stylesheet" href="/assets/responsive.css?v=<?= (int)filemtime(__DIR__ . '/../../assets/responsive.css') ?>">
<?php if (!empty($extra_styles) && is_array($extra_styles)) : ?>
    <?php foreach ($extra_styles as $styleHref) : ?>
    <link rel="stylesheet" href="<?= h(asset_href($styleHref)) ?>">
    <?php endforeach; ?>
<?php endif; ?>
<script>
// Environment configuration - suppress console in production
(function() {
  'use strict';
  var isProduction = <?= json_encode(app_is_production()) ?>;
  var zxingLoading = null; // Track loading promise

  // Client-side logger that respects production mode
  window.AppLogger = {
    isProduction: isProduction,
    log: function() {
      if (!isProduction && typeof console !== 'undefined' && console.log) {
        console.log.apply(console, arguments);
      }
    },
    warn: function() {
      if (!isProduction && typeof console !== 'undefined' && console.warn) {
        console.warn.apply(console, arguments);
      }
    },
    error: function() {
      // Errors are always logged for debugging critical issues
      if (typeof console !== 'undefined' && console.error) {
        console.error.apply(console, arguments);
      }
    },
    debug: function() {
      if (!isProduction && typeof console !== 'undefined' && console.debug) {
        console.debug.apply(console, arguments);
      }
    },
    info: function() {
      if (!isProduction && typeof console !== 'undefined' && console.info) {
        console.info.apply(console, arguments);
      }
    }
  };

  // Optionally override console in production (uncomment to suppress all console output)
  // if (isProduction) {
  //   console.log = console.warn = console.debug = console.info = function() {};
  // }

  /**
   * Normalize image path to ensure it has the /uploads/ prefix.
   * Handles legacy data that may have just the filename.
   * @param {string|null} path - The image path from database
   * @returns {string} The normalized path with /uploads/ prefix, or empty string
   */
  window.imageUrl = function(path) {
    if (!path) return '';
    if (path.startsWith('/uploads/')) return path;
    if (path.startsWith('/')) return path;
    return '/uploads/' + path;
  };

  /**
   * Lazy-load ZXing barcode scanner library on demand
   * @returns {Promise} Resolves when ZXing is ready, rejects on error
   */
  window.loadScannerLibrary = function() {
    // Already loaded
    if (typeof ZXing !== 'undefined') {
      return Promise.resolve(ZXing);
    }

    // Already loading - return existing promise
    if (zxingLoading) {
      return zxingLoading;
    }

    // Start loading
    zxingLoading = new Promise(function(resolve, reject) {
      var script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.20.0/umd/index.min.js';
      script.async = true;

      script.onload = function() {
        if (typeof ZXing !== 'undefined') {
          AppLogger.log('ZXing scanner library loaded');
          resolve(ZXing);
        } else {
          reject(new Error('ZXing failed to initialize'));
        }
      };

      script.onerror = function() {
        zxingLoading = null; // Allow retry
        reject(new Error('Failed to load scanner library'));
      };

      document.head.appendChild(script);
    });

    return zxingLoading;
  };
})();
</script>
<script type="text/javascript" src="<?= h(asset_href('/assets/api-error-handler.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/csrf-api.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/csrf-fix.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/form-validation.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/form-enhancements.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/cart-count.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/notifications.js')) ?>"></script>
<script type="text/javascript" src="<?= h(asset_href('/assets/media-overflow-fix.js')) ?>"></script>
<script>
// Dark mode toggle
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('themeToggleBtn');
  if (!btn) return;

  function applyTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-kit-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-kit-theme');
    }
    localStorage.setItem('kit_theme', theme);
    btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
  }

  btn.addEventListener('click', function () {
    var current = document.documentElement.getAttribute('data-kit-theme');
    applyTheme(current === 'dark' ? 'light' : 'dark');
  });

  // Set correct initial aria-label
  var current = document.documentElement.getAttribute('data-kit-theme');
  btn.setAttribute('aria-label', current === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
});
</script>
</head>
<body class="layout-sidebar">
<div id="notification-container" class="notification-container" aria-live="polite" aria-label="Notifications"></div>
<?php
  // Every page in the admin area highlights "Admin Panel": /admin, /admin/<page>, /admin_logs, ...
  $is_admin_page = str_starts_with($nav_route, '/admin');
?>
<div class="app-shell">
  <aside class="app-sidebar" id="appSidebar" aria-label="Main navigation" aria-hidden="false">
    <div class="sidebar-inner">
      <div class="sidebar-brand">
        <a href="/" class="sidebar-logo" aria-label="Quack Track - Go to Dashboard">
          <img src="/assets/logo2.png" alt="Quack Track Logo" class="sidebar-logo-image">
          <span>Quack Track</span>
        </a>
      </div>
      <nav id="main-navigation" class="sidebar-nav" role="navigation" aria-label="Main navigation">

        <!-- Inventory group -->
        <div class="sidebar-group-label">Inventory</div>
        <a href="/stock" class="sidebar-link <?= ($nav_page === 'stock') ? 'active' : '' ?>" <?= ($nav_page === 'stock') ? 'aria-current="page"' : '' ?>>
          <span>Van Stock</span>
        </a>
        <a href="/products" class="sidebar-link <?= ($nav_page === 'products') ? 'active' : '' ?>" <?= ($nav_page === 'products') ? 'aria-current="page"' : '' ?>>
          <span>Products</span>
        </a>
        <a href="/region-storage" class="sidebar-link <?= ($nav_page === 'region_storage') ? 'active' : '' ?>" <?= ($nav_page === 'region_storage') ? 'aria-current="page"' : '' ?>>
          <span>Region Storage</span>
        </a>

        <!-- Operations group -->
        <div class="sidebar-group-label">Operations</div>
        <a href="/cart" class="sidebar-link <?= ($nav_page === 'cart') ? 'active' : '' ?>" <?= ($nav_page === 'cart') ? 'aria-current="page"' : '' ?>>
          <span>Cart</span>
          <span class="sidebar-pill" aria-label="Items in cart" id="sidebarCartCount">0</span>
        </a>
        <a href="/orders" class="sidebar-link <?= ($nav_page === 'orders') ? 'active' : '' ?>" <?= ($nav_page === 'orders') ? 'aria-current="page"' : '' ?>>
          <span>Orders</span>
        </a>
        <a href="/receiving" class="sidebar-link <?= ($nav_page === 'receiving') ? 'active' : '' ?>" <?= ($nav_page === 'receiving') ? 'aria-current="page"' : '' ?>>
          <span>Receiving</span>
        </a>
        <a href="/travel" class="sidebar-link <?= ($nav_page === 'travel') ? 'active' : '' ?>" <?= ($nav_page === 'travel') ? 'aria-current="page"' : '' ?>>
          <span>Travel</span>
        </a>
        <?php if (has_permission('kit.view')) : ?>
        <a href="/kits" class="sidebar-link <?= ($nav_page === 'kit_templates') ? 'active' : '' ?>" <?= ($nav_page === 'kit_templates') ? 'aria-current="page"' : '' ?>>
          <span>Kits</span>
        </a>
        <?php endif; ?>
        <?php if (has_permission('deployment.view')) : ?>
        <a href="/deployments" class="sidebar-link <?= ($nav_page === 'deployments') ? 'active' : '' ?>" <?= ($nav_page === 'deployments') ? 'aria-current="page"' : '' ?>>
          <span>Deployments</span>
        </a>
        <?php endif; ?>

        <!-- Reporting group -->
        <?php
          $hasReporting = has_permission('forms.manage') || has_permission('forms.campaigns.manage') || has_permission('forms.view_responses') || has_permission('surveys.submit') || has_permission('surveys.view_own');
        ?>
        <?php if ($hasReporting) : ?>
        <div class="sidebar-group-label">Reporting</div>
        <a href="/reports" class="sidebar-link <?= in_array($nav_page, ['reports', 'transactions'], true) ? 'active' : '' ?>" <?= in_array($nav_page, ['reports', 'transactions'], true) ? 'aria-current="page"' : '' ?>>
          <span>Reports</span>
        </a>
            <?php if (has_permission('surveys.submit') || has_permission('surveys.view_own')) : ?>
        <a href="/surveys" class="sidebar-link <?= ($nav_page === 'surveys') ? 'active' : '' ?>" <?= ($nav_page === 'surveys') ? 'aria-current="page"' : '' ?>>
          <span>Store Surveys</span>
        </a>
            <?php endif; ?>
            <?php if (has_permission('forms.manage') || has_permission('forms.campaigns.manage') || has_permission('forms.view_responses')) : ?>
        <a href="/forms" class="sidebar-link <?= ($nav_page === 'forms') ? 'active' : '' ?>" <?= ($nav_page === 'forms') ? 'aria-current="page"' : '' ?>>
          <span>Forms</span>
        </a>
            <?php endif; ?>
            <?php if (has_permission('assets.view') && env('SNIPE_IT_ENABLED', '0') === '1') :
                ?>
        <a href="/assets" class="sidebar-link <?= ($nav_page === 'assets') ? 'active' : '' ?>" <?= ($nav_page === 'assets') ? 'aria-current="page"' : '' ?>>
          <span>IT Assets</span>
        </a>
                <?php if (has_permission('assets.manage')) :
                    ?>
        <a href="/asset-review" class="sidebar-link <?= ($nav_page === 'asset_review') ? 'active' : '' ?>" <?= ($nav_page === 'asset_review') ? 'aria-current="page"' : '' ?>>
          <span>Asset Review</span>
        </a>
        <a href="/asset-templates" class="sidebar-link <?= ($nav_page === 'asset_templates') ? 'active' : '' ?>" <?= ($nav_page === 'asset_templates') ? 'aria-current="page"' : '' ?>>
          <span>Asset Templates</span>
        </a>
                    <?php
                endif; ?>
                <?php
            endif; ?>
            <?php
        else : ?>
        <!-- Reports still visible even without field ops permissions -->
        <div class="sidebar-group-label">Reporting</div>
        <a href="/reports" class="sidebar-link <?= in_array($nav_page, ['reports', 'transactions'], true) ? 'active' : '' ?>" <?= in_array($nav_page, ['reports', 'transactions'], true) ? 'aria-current="page"' : '' ?>>
          <span>Reports</span>
        </a>
        <?php endif; ?>

        <!-- Admin group (role-gated) -->
        <?php if (has_permission('admin.access')) : ?>
        <div class="sidebar-group-label">Admin</div>
        <a href="/admin.php" class="sidebar-link <?= $is_admin_page ? 'active' : '' ?>" <?= $is_admin_page ? 'aria-current="page"' : '' ?>>
          <span>Admin Panel</span>
        </a>
        <?php endif; ?>

      </nav>
      <div class="sidebar-footer" role="status" aria-live="polite">
        <?php
          $displayName = $_SESSION['display_name'] ?? $_SESSION['email'] ?? '';
          $firstName   = $_SESSION['first_name'] ?? '';
          $lastName    = $_SESSION['last_name'] ?? '';
          $initials    = '';
        if ($firstName || $lastName) {
            $initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
        } elseif ($displayName) {
            $parts = explode(' ', trim($displayName));
            $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
        }
        if (!$initials) {
            $initials = '??';
        }
        ?>
        <?php if ($displayName) : ?>
        <div class="sidebar-user">
          <div class="sidebar-user-avatar" aria-hidden="true"><?= h($initials) ?></div>
          <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= h($displayName) ?></div>
            <?php if (!empty($_SESSION['role'])) : ?>
            <div class="sidebar-user-role"><?= h($_SESSION['role']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="sidebar-actions">
          <button type="button" class="sidebar-action-link sidebar-theme-toggle" id="themeToggleBtn" aria-label="Toggle dark mode">
            <span class="theme-icon-light" aria-hidden="true">
              <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/></svg>
            </span>
            <span class="theme-icon-dark" aria-hidden="true">
              <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/></svg>
            </span>
            <span class="theme-label">Dark Mode</span>
          </button>
          <a href="/change_password" class="sidebar-action-link <?= ($nav_page === 'change_password') ? 'active' : '' ?>" <?= ($nav_page === 'change_password') ? 'aria-current="page"' : '' ?>>
            Change Password
          </a>
          <form method="post" action="/logout" class="sidebar-logout-form">
            <?= csrf_field() ?>
            <button type="submit" class="sidebar-action-link sidebar-theme-toggle logout-link">Logout</button>
          </form>
        </div>
      </div>
    </div>
  </aside>
  <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>
  <div class="app-main">
    <header class="topbar" role="banner">
      <button class="hamburger sidebar-toggle"
              aria-label="Toggle navigation menu"
              aria-expanded="false"
              aria-controls="main-navigation"
              type="button">
        <span></span>
      </button>
      <div class="topbar-title"><?= h($title ?? 'Quack Track') ?></div>
      <div class="topbar-actions">
      </div>
    </header>
    <main id="main-content" class="<?= h($main_class ?? 'container') ?>" tabindex="-1">
<?= render_notifications_js() ?>
