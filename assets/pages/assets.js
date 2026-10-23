/* global handleAPIResponse */
(function() {
    'use strict';

    // =========================================================================
    // Config
    // =========================================================================
    const API_URL = '/api/snipe-it-assets.php';
    const SEARCH_DEBOUNCE = 500;
    const USER_DEBOUNCE = 300;
    const REQUEST_TIMEOUT_MS = 15000; // 15 s before we give up and unblock the UI

    // =========================================================================
    // Page permissions
    // =========================================================================
    const page = document.querySelector('.assets-page');
    if (!page) {
        return;
    }

    const can = {
        checkout:    page.dataset.canCheckout === '1',
        checkin:     page.dataset.canCheckin === '1',
        manage:      page.dataset.canManage === '1'
    };

    // =========================================================================
    // State
    // =========================================================================
    const state = {
        assets:        [],
        statuses:      [],
        models:        [],
        locations:     [],
        manufacturers: [],
        suppliers:     [],
        total:         0,
        offset:        0,
        limit:         50,
        currentPage:   1,
        totalPages:    1,
        filters:       { search: '', status_id: '', category_id: '', model_id: '', location_id: '', manufacturer_id: '' },
        searchTimer:   null,
        userTimer:     null,
        loading:       false,
        // Detail panel
        currentAsset:    null,
        currentActivity: [],
        openDrawerName:  null, // null | 'checkout' | 'checkin'
        manageMode:      false, // true when grids are in edit mode
        manageLookups:   false // true once manage dropdowns have been loaded
    };

    // =========================================================================
    // Grid element refs
    // =========================================================================
    const el = {
        searchInput:        document.getElementById('search-input'),
        statusFilter:       document.getElementById('status-filter'),
        categoryFilter:     document.getElementById('category-filter'),
        modelFilter:        document.getElementById('model-filter'),
        locationFilter:     document.getElementById('location-filter'),
        manufacturerFilter: document.getElementById('manufacturer-filter'),
        perPageSelect:      document.getElementById('per-page-select'),
        container:          document.getElementById('assets-container'),
        prevBtn:            document.getElementById('prev-page'),
        nextBtn:            document.getElementById('next-page'),
        currentPageEl:      document.getElementById('current-page'),
        totalPagesEl:       document.getElementById('total-pages'),
        totalAssetsEl:      document.getElementById('total-assets'),
        // Stat strip
        statTotal:      document.getElementById('stat-total'),
        statDeployed:   document.getElementById('stat-deployed'),
        statAvailable:  document.getElementById('stat-available'),
        statShowing:    document.getElementById('stat-showing'),
        mastheadSub:    document.getElementById('assets-masthead-sub')
    };

    // =========================================================================
    // Init
    // =========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        const requestedAssetId = Number.parseInt(
            new URLSearchParams(window.location.search).get('asset_id') || '',
            10
        );

        // Restore per-page preference
        try {
            const saved = parseInt(localStorage.getItem('assets_per_page'), 10);
            if ([25, 50, 100].includes(saved)) {
                state.limit = saved;
                if (el.perPageSelect) {
                    el.perPageSelect.value = String(saved);
                }
            }
        } catch { /* ignore */ }

        bindGridEvents();
        loadFilters().then(() => {
            // Mobile entry points can deep-link to the existing responsive
            // detail drawer without duplicating the asset workflow.
            if (requestedAssetId > 0) {
                openDetail(requestedAssetId);
            }
        });
        loadStats();
        bindPanelForms();
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeDetail();
            }
        });
    });

    // =========================================================================
    // Notifications
    // =========================================================================
    function notify(msg, type = 'info') {
        if (window.QuackNotify) {
            (QuackNotify[type] ?? QuackNotify.show).call(QuackNotify, msg);
        }
    }

    // =========================================================================
    // API helpers
    // =========================================================================
    /** Returns an AbortSignal that fires after REQUEST_TIMEOUT_MS */
    function timeoutSignal() {
        const ctrl = new AbortController();
        setTimeout(() => ctrl.abort(), REQUEST_TIMEOUT_MS);
        return ctrl.signal;
    }

    async function apiGet(params) {
        const url = new URL(API_URL, location.origin);
        url.searchParams.set('action', params.action || 'list');
        for (const [k, v] of Object.entries(params)) {
            if (k !== 'action' && v !== '' && v !== null && v !== undefined) {
                url.searchParams.set(k, v);
            }
        }
        try {
            const res = await secureFetch(url.toString(), { method: 'GET', signal: timeoutSignal() });
            const payload = await handleAPIResponse(res);
            return payload.data;
        } catch (err) {
            if (err?.name === 'AbortError') {
                throw new Error('Request timed out. Please try again.');
            }
            throw err;
        }
    }

    async function apiPost(data) {
        const fd = new FormData();
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf) {
            fd.append('csrf', csrf);
        }
        for (const [k, v] of Object.entries(data)) {
            fd.append(k, v);
        }
        try {
            const res = await fetch(API_URL, { method: 'POST', body: fd, signal: timeoutSignal() });
            const payload = await res.json();
            // Personal token expired mid-session — show re-auth banner instead of generic error
            if (res.status === 401 && payload?.snipe_auth_required) {
                showReauthBanner();
                throw new Error('snipe_auth_required');
            }
            if (!res.ok) {
                throw new Error(payload?.error || `Server error (${res.status}). Please try again.`);
            }
            return payload;
        } catch (err) {
            if (err?.name === 'AbortError') {
                throw new Error('Request timed out. Please try again.');
            }
            throw err;
        }
    }

    // =========================================================================
    // Re-auth banner (shown when personal token expires mid-session)
    // =========================================================================
    function showReauthBanner() {
        if (document.getElementById('snipe-reauth-banner')) {
            return;
        } // already showing

        const banner = document.createElement('div');
        banner.id = 'snipe-reauth-banner';
        banner.className = 'snipe-reauth-banner';
        banner.innerHTML =
            '<span class="snipe-reauth-msg">Your Snipe-IT session has expired — actions are paused.</span>' +
            '<a href="/api/snipe-link-start.php" class="snipe-reauth-btn">Reconnect Snipe-IT</a>' +
            '<button class="snipe-reauth-dismiss" onclick="this.closest(\'#snipe-reauth-banner\').remove()" ' +
            '        aria-label="Dismiss">×</button>';

        const grid = document.getElementById('assets-container');
        grid?.parentElement?.insertBefore(banner, grid);
    }

    // =========================================================================
    // Filters + initial load
    // =========================================================================
    async function loadFilters() {
        const assetsPromise = loadAssets();

        // All 5 filter lists fire in parallel — all are disk-cached on the server
        // after the first load (10-min TTL), so subsequent page loads are instant.
        const [statuses, categories, models, locations, manufacturers] = await Promise.allSettled([
            apiGet({ action: 'statuses' }),
            apiGet({ action: 'categories' }),
            apiGet({ action: 'models' }),
            apiGet({ action: 'locations' }),
            apiGet({ action: 'manufacturers' })
        ]);

        if (statuses.status === 'fulfilled') {
            state.statuses = statuses.value?.statuses ?? [];
            populateSelect(el.statusFilter, state.statuses, 'id', 'name');
        }
        if (categories.status === 'fulfilled') {
            populateSelect(el.categoryFilter, categories.value?.categories ?? [], 'id', 'name');
        }
        if (models.status === 'fulfilled') {
            state.models = models.value?.models ?? [];
            populateSelect(el.modelFilter, state.models, 'id', 'name');
        }
        if (locations.status === 'fulfilled') {
            state.locations = locations.value?.locations ?? [];
            populateSelect(el.locationFilter, state.locations, 'id', 'name');
        }
        if (manufacturers.status === 'fulfilled') {
            state.manufacturers = manufacturers.value?.manufacturers ?? [];
            populateSelect(el.manufacturerFilter, state.manufacturers, 'id', 'name');
        }

        await assetsPromise;
    }

    // =========================================================================
    // Stat strip
    // =========================================================================
    async function loadStats() {
        try {
            const data = await apiGet({ action: 'stats' });
            if (!data) {
                return;
            }

            const total = data.total ?? 0;
            const deployed = data.deployed ?? 0;
            const available = data.available ?? 0;

            if (el.statTotal) {
                el.statTotal.textContent = total.toLocaleString();
            }
            if (el.statDeployed) {
                el.statDeployed.textContent = deployed.toLocaleString();
            }
            if (el.statAvailable) {
                el.statAvailable.textContent = available.toLocaleString();
            }
            if (el.mastheadSub) {
                el.mastheadSub.textContent = `${total.toLocaleString()} assets tracked`;
            }
        } catch {
            // Non-fatal — stat strip just stays as —
        }
    }

    /** Load suppliers for the manage edit form (manufacturers already loaded by loadFilters) */
    async function ensureManageLookups() {
        if (state.manageLookups) {
            return;
        }
        try {
            const sup = await apiGet({ action: 'suppliers' });
            if (sup) {
                state.suppliers = sup.suppliers ?? [];
            }
        } catch { /* non-fatal — supplier dropdown stays empty */ }
        state.manageLookups = true;
    }

    function populateSelect(sel, items, id, name) {
        if (!sel) {
            return;
        }
        const prev = sel.value;
        while (sel.options.length > 1) {
            sel.remove(1);
        }
        items.forEach(item => {
            const o = new Option(item[name] || item[id], item[id]);
            sel.add(o);
        });
        sel.value = prev;
    }

    function bindGridEvents() {
        el.searchInput?.addEventListener('input', e => {
            clearTimeout(state.searchTimer);
            state.filters.search = e.target.value.trim();
            state.offset = 0;
            state.searchTimer = setTimeout(loadAssets, SEARCH_DEBOUNCE);
        });
        el.statusFilter?.addEventListener('change', applyFilter);
        el.categoryFilter?.addEventListener('change', applyFilter);
        el.modelFilter?.addEventListener('change', applyFilter);
        el.locationFilter?.addEventListener('change', applyFilter);
        el.manufacturerFilter?.addEventListener('change', applyFilter);
        el.perPageSelect?.addEventListener('change', e => {
            const v = parseInt(e.target.value, 10);
            if (v > 0) {
                state.limit = v;
                state.offset = 0;
                state.currentPage = 1;
                try {
                    localStorage.setItem('assets_per_page', String(v));
                } catch { /* ignore */ }
                loadAssets();
            }
        });
        el.prevBtn?.addEventListener('click', () => {
            state.offset -= state.limit; loadAssets(); scrollTo(0,0);
        });
        el.nextBtn?.addEventListener('click', () => {
            state.offset += state.limit; loadAssets(); scrollTo(0,0);
        });
    }

    function applyFilter(e) {
        const map = { 'status-filter': 'status_id', 'category-filter': 'category_id', 'model-filter': 'model_id', 'location-filter': 'location_id', 'manufacturer-filter': 'manufacturer_id' };
        const key = map[e.target.id];
        if (key) {
            state.filters[key] = e.target.value;
        }
        state.offset = 0; state.currentPage = 1;
        loadAssets();
    }

    // =========================================================================
    // Asset list
    // =========================================================================
    async function loadAssets() {
        try {
            state.loading = true;
            el.container.innerHTML = '<div class="loading">Loading assets…</div>';
            const data = await apiGet({ action: 'list', limit: state.limit, offset: state.offset, ...state.filters });
            state.assets = data?.assets ?? [];
            state.total = data?.total ?? 0;
            state.totalPages = Math.ceil(state.total / state.limit) || 1;
            state.currentPage = Math.floor(state.offset / state.limit) + 1;

            if (!state.assets.length) {
                el.container.innerHTML = `<div class="assets-empty"><p>${state.currentPage === 1 ? 'No assets found.' : 'No assets on this page.'}</p></div>`;
            } else {
                el.container.innerHTML = state.assets.map(renderCard).join('');
            }
            updatePagination();
            if (el.statShowing) {
                el.statShowing.textContent = state.assets.length.toLocaleString();
            }
        } catch {
            el.container.innerHTML = '<div class="assets-empty"><p>Failed to load assets. Please try again.</p></div>';
        } finally {
            state.loading = false;
        }
    }

    function renderCard(asset) {
        const label = asset.status_label?.name || 'Unknown';
        const statusMeta = getStatusMeta(asset.status_label);
        const isAssigned = asset.assigned_to?.id > 0;
        const assignedTo = isAssigned
            ? htmlEsc(`${asset.assigned_to.first_name || ''} ${asset.assigned_to.last_name || ''}`.trim() || asset.assigned_to.username || 'Unknown')
            : 'Unassigned';

        let actions = '';
        if (can.checkout && !isAssigned) {
            actions += `<button class="asset-btn" onclick="event.stopPropagation();assetsModule.openDetail(${asset.id},'checkout')">Check Out</button>`;
        }
        if (can.checkin && isAssigned) {
            actions += `<button class="asset-btn" onclick="event.stopPropagation();assetsModule.openDetail(${asset.id},'checkin')">Return</button>`;
        }
        if (can.manage) {
            actions += `<button class="asset-btn" onclick="event.stopPropagation();assetsModule.openDetail(${asset.id})">Manage</button>`;
        }

        return `
        <div class="asset-card" role="button" tabindex="0" style="cursor:pointer"
             onclick="assetsModule.openDetail(${asset.id})"
             onkeydown="if(event.key==='Enter'||event.key===' ')assetsModule.openDetail(${asset.id})">
            <div class="asset-card-header">
                <h4 class="asset-name">${htmlEsc(asset.name || 'Unknown Asset')}</h4>
                <div class="asset-tag">${htmlEsc(asset.asset_tag || asset.serial || 'N/A')}</div>
            </div>
            <div class="asset-status ${statusMeta.class}">${htmlEsc(label)}</div>
            <div class="asset-details">
                <div class="asset-detail-row"><span class="asset-detail-label">Model:</span><span class="asset-detail-value">${htmlEsc(asset.model?.name || 'N/A')}</span></div>
                <div class="asset-detail-row"><span class="asset-detail-label">Category:</span><span class="asset-detail-value">${htmlEsc(asset.category?.name || 'N/A')}</span></div>
                <div class="asset-detail-row"><span class="asset-detail-label">Location:</span><span class="asset-detail-value">${htmlEsc(asset.location?.name || 'N/A')}</span></div>
                <div class="asset-detail-row"><span class="asset-detail-label">Assigned To:</span><span class="asset-detail-value">${assignedTo}</span></div>
            </div>
            ${actions ? `<div class="asset-actions">${actions}</div>` : ''}
        </div>`;
    }

    function updatePagination() {
        el.currentPageEl.textContent = state.currentPage;
        el.totalPagesEl.textContent = state.totalPages;
        el.totalAssetsEl.textContent = state.total;
        el.prevBtn.disabled = state.currentPage <= 1;
        el.nextBtn.disabled = state.currentPage >= state.totalPages;
    }

    function getStatusMeta(label) {
        const t = (label?.status_type || label?.status_meta || '').toLowerCase();
        if (t === 'deployable' || t === 'rtd') {
            return { class: 'ready' };
        }
        if (t === 'deployed') {
            return { class: 'assigned' };
        }
        if (t === 'archived') {
            return { class: 'archived' };
        }
        if (t === 'undeployable' || t === 'pending') {
            return { class: 'pending' };
        }
        return { class: '' };
    }

    // =========================================================================
    // Detail Panel — open / close
    // =========================================================================
    const panel = document.getElementById('asset-detail-panel');
    const overlay = document.getElementById('asset-detail-overlay');

    function openDetail(assetId, initialDrawer = null) {
        if (!panel) {
            return;
        }

        // Show panel in loading state
        overlay?.removeAttribute('hidden');
        panel.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';

        // Reset all panel state cleanly
        document.getElementById('detail-loading').hidden = false;
        document.getElementById('adp-content').hidden = true;
        document.getElementById('adp-manage-bar').hidden = true;
        state.manageMode = false;
        state.openDrawerName = null;
        closeDrawer(/* silent */ true);

        // Fetch asset + activity in parallel
        Promise.allSettled([
            apiGet({ action: 'get', id: assetId }),
            apiGet({ action: 'activity', id: assetId })
        ]).then(([ar, act]) => {
            const asset = ar.status === 'fulfilled' ? ar.value?.asset : null;
            const activity = act.status === 'fulfilled' ? act.value?.activity : [];

            if (!asset) {
                notify('Could not load asset details.', 'error'); closeDetail(); return;
            }

            state.currentAsset = asset;
            state.currentActivity = activity || [];

            renderViewMode(asset, activity || []);

            // Ensure manage bar is always hidden when panel first renders
            document.getElementById('adp-manage-bar').hidden = true;

            document.getElementById('detail-loading').hidden = true;
            document.getElementById('adp-content').hidden = false;

            // Open the requested drawer immediately (e.g. from card "Check Out" button)
            if (initialDrawer) {
                openDrawer(initialDrawer);
            }
        });
    }

    function closeDetail() {
        overlay?.setAttribute('hidden', '');
        panel?.setAttribute('hidden', '');
        document.body.style.overflow = '';
        // Reset DOM so next openDetail() starts clean
        document.getElementById('adp-content').hidden = true;
        document.getElementById('adp-manage-bar').hidden = true;
        document.getElementById('detail-loading').hidden = false;
        closeDrawer(true);
        state.currentAsset = null;
        state.openDrawerName = null;
        state.manageMode = false;
    }

    // =========================================================================
    // Inline Action Drawer — open / close
    // =========================================================================
    const drawerEl = document.getElementById('adp-drawer');
    const drawerTitle = document.getElementById('adp-drawer-title');

    const DRAWER_CONFIG = {
        checkout: { title: 'Check Out Asset', formId: 'adp-checkout-form', prepare: prepareCheckout },
        checkin:  { title: 'Return Asset to Inventory', formId: 'adp-checkin-form', prepare: prepareCheckin }
    };

    function openDrawer(name) {
        const cfg = DRAWER_CONFIG[name];
        if (!cfg || !drawerEl) {
            return;
        }

        // If manage mode is on, cancel it first
        if (state.manageMode) {
            cancelManage();
        }

        state.openDrawerName = name;

        // Hide all drawer forms, show the right one
        drawerEl.querySelectorAll('.adp-drawer-form').forEach(f => {
            f.hidden = true;
        });
        document.getElementById(cfg.formId).hidden = false;

        drawerTitle.textContent = cfg.title;
        drawerEl.hidden = false;

        // Prepare form fields
        if (state.currentAsset) {
            cfg.prepare(state.currentAsset);
        }

        // Scroll drawer into view
        drawerEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Highlight the active action button (checkout/checkin only — manage is separate)
        document.querySelectorAll('#detail-actions .asset-btn[data-drawer="checkout"], #detail-actions .asset-btn[data-drawer="checkin"]')
            .forEach(b => b.classList.remove('active'));
        const activeBtn = document.querySelector(`#detail-actions .asset-btn[data-drawer="${name}"]`);
        if (activeBtn) {
            activeBtn.classList.add('active');
        }
    }

    function closeDrawer(_silent = false) {
        state.openDrawerName = null;
        if (drawerEl) {
            drawerEl.hidden = true;
        }
        drawerEl?.querySelectorAll('.adp-drawer-form').forEach(f => {
            f.hidden = true;
        });
        // Only clear checkout/checkin active state — manage button has its own state
        document.querySelectorAll('#detail-actions .asset-btn[data-drawer="checkout"], #detail-actions .asset-btn[data-drawer="checkin"]')
            .forEach(b => b.classList.remove('active'));
    }

    // =========================================================================
    // VIEW MODE — render info sections
    // =========================================================================
    function renderViewMode(asset, activity) {
        const isAssigned = asset.assigned_to?.id > 0;
        const statusMeta = getStatusMeta(asset.status_label);

        // Header
        document.getElementById('detail-asset-name').textContent = asset.name || 'Unknown Asset';
        document.getElementById('detail-asset-tag').textContent =
            asset.asset_tag ? `Tag: ${asset.asset_tag}` : (asset.serial ? `Serial: ${asset.serial}` : '');

        const badge = document.getElementById('detail-status-badge');
        badge.className = `asset-status ${statusMeta.class}`;
        badge.textContent = asset.status_label?.name || 'Unknown';

        // Action buttons — checkout/checkin open inline drawer; manage toggles in-place edit
        const actionsEl = document.getElementById('detail-actions');
        actionsEl.innerHTML = '';
        if (can.checkout && !isAssigned) {
            actionsEl.appendChild(makeDrawerBtn('Check Out', 'checkout'));
        }
        if (can.checkin && isAssigned) {
            actionsEl.appendChild(makeDrawerBtn('Return Asset', 'checkin'));
        }
        if (can.manage) {
            actionsEl.appendChild(makeManageBtn());
        }

        const assignedName = isAssigned
            ? `${asset.assigned_to.first_name || ''} ${asset.assigned_to.last_name || ''}`.trim()
              || asset.assigned_to.username || `User #${asset.assigned_to.id}`
            : 'Unassigned';

        buildGrid('detail-grid-identity', [
            {
                label: 'Asset Name',
                value: asset.name || '—',
                field: 'name',
                type:  'text'
            },
            {
                label: 'Asset Tag',
                value: asset.asset_tag || '—',
                field: 'asset_tag',
                type:  'text'
            },
            {
                label: 'Serial',
                value: asset.serial || '—',
                field: 'serial',
                type:  'text'
            },
            {
                label:   'Status',
                value:   { display: asset.status_label?.name || '—', rawId: asset.status_label?.id },
                field:   'status_id',
                type:    'select',
                options: state.statuses
            },
            {
                label:   'Model',
                value:   { display: asset.model?.name || '—', rawId: asset.model?.id },
                field:   'model_id',
                type:    'select',
                options: state.models
            },
            {
                label: 'Model No.',
                value: asset.model_number || '—'
            },
            {
                label:   'Manufacturer',
                value:   { display: asset.manufacturer?.name || '—', rawId: asset.manufacturer?.id },
                field:   'manufacturer_id',
                type:    'select',
                options: state.manufacturers
            },
            {
                label: 'Category',
                value: asset.category?.name || '—'
            }
        ]);

        buildGrid('detail-grid-assignment', [
            {
                label: 'Assigned To',
                value: assignedName
                // Assignment is handled via checkout/checkin, not direct edit
            },
            {
                label:   'Location',
                value:   { display: asset.location?.name || '—', rawId: asset.location?.id },
                field:   'location_id',
                type:    'select',
                options: state.locations
            },
            {
                label: 'Last Checkout',
                value: asset.last_checkout?.formatted || '—'
            },
            {
                label: 'Expected In',
                value: { display: asset.expected_checkin?.formatted || '—', raw: asset.expected_checkin?.date || '' },
                field: 'expected_checkin',
                type:  'date'
            },
            {
                label:      'Notes',
                value:      asset.notes || '—',
                field:      'notes',
                type:       'textarea',
                allowEmpty: true
            }
        ]);

        buildGrid('detail-grid-purchase', [
            {
                label: 'Purchase Date',
                value: { display: asset.purchase_date?.formatted || '—', raw: asset.purchase_date?.date || '' },
                field: 'purchase_date',
                type:  'date'
            },
            {
                label: 'Purchase Cost',
                value: { display: asset.purchase_cost ? `$${asset.purchase_cost}` : '—', raw: asset.purchase_cost || '' },
                field: 'purchase_cost',
                type:  'number'
            },
            {
                label: 'Order No.',
                value: asset.order_number || '—',
                field: 'order_number',
                type:  'text'
            },
            {
                label: 'Warranty (months)',
                value: { display: asset.warranty_months ? `${asset.warranty_months} mo` : '—', raw: asset.warranty_months || '' },
                field: 'warranty_months',
                type:  'number'
            },
            {
                label:   'Supplier',
                value:   { display: asset.supplier?.name || '—', rawId: asset.supplier?.id },
                field:   'supplier_id',
                type:    'select',
                options: state.suppliers
            }
        ]);

        // Custom fields
        const customs = asset.custom_fields ? Object.entries(asset.custom_fields) : [];
        const customSection = document.getElementById('detail-section-custom');
        if (customs.length) {
            buildGrid('detail-grid-custom', customs.map(([friendlyName, f]) => [friendlyName || f.field || '—', f.value || '—']));
            customSection.hidden = false;
        } else {
            customSection.hidden = true;
        }

        // Activity
        renderActivity(activity);
    }

    /**
     * Build an info grid.
     * rows: Array of { label, value, field?, type?, options?, allowEmpty? }
     *   field     - if set and manageMode is active, renders an edit control
     *   type      - 'text' | 'select' | 'date' | 'number' | 'textarea' (default 'text')
     *   options   - array of {id, name} for select fields
     *   allowEmpty - if true, sends empty string to API (allows clearing)
     */
    function buildGrid(gridId, rows) {
        const container = document.getElementById(gridId);
        if (!container) {
            return;
        }

        container.innerHTML = rows.map(row => {
            const label = row.label ?? row[0] ?? '';
            const value = row.value ?? row[1] ?? '';
            const field = row.field;
            const type = row.type ?? 'text';
            const options = row.options ?? [];
            const allowEmpty = row.allowEmpty ? 'true' : 'false';

            let valueCell;

            if (state.manageMode && field) {
                if (type === 'select') {
                    const opts = options.map(o =>
                        `<option value="${htmlEsc(String(o.id))}"${String(o.id) === String(value.rawId ?? '') ? ' selected' : ''}>${htmlEsc(o.name)}</option>`
                    ).join('');
                    valueCell = `<select class="adp-edit-select" name="${htmlEsc(field)}" data-allow-empty="${allowEmpty}">
                                    <option value="">Select…</option>${opts}
                                 </select>`;
                } else if (type === 'textarea') {
                    valueCell = `<textarea class="adp-edit-textarea" name="${htmlEsc(field)}" rows="2"
                                           data-allow-empty="${allowEmpty}" placeholder="${htmlEsc(String(value))}">${htmlEsc(String(value.display ?? value))}</textarea>`;
                } else {
                    const inputType = type === 'date' ? 'date' : (type === 'number' ? 'number' : 'text');
                    const inputVal = value.raw ?? value.display ?? value;
                    valueCell = `<input class="adp-edit-input" type="${inputType}" name="${htmlEsc(field)}"
                                        value="${htmlEsc(String(inputVal ?? ''))}"
                                        data-allow-empty="${allowEmpty}">`;
                }
            } else {
                const display = value.display ?? value;
                valueCell = `<span class="adp-grid-value">${htmlEsc(String(display))}</span>`;
            }

            return `<div class="adp-grid-row${state.manageMode && field ? ' adp-grid-row--edit' : ''}">
                        <span class="adp-grid-label">${htmlEsc(label)}</span>
                        ${valueCell}
                    </div>`;
        }).join('');
    }


    function makeDrawerBtn(text, drawerName) {
        const btn = document.createElement('button');
        btn.className = 'asset-btn';
        btn.dataset.drawer = drawerName;
        btn.textContent = text;
        btn.onclick = () => {
            // Toggle: click again to close
            if (state.openDrawerName === drawerName) {
                closeDrawer();
            } else {
                openDrawer(drawerName);
            }
        };
        return btn;
    }

    function makeManageBtn() {
        const btn = document.createElement('button');
        btn.className = 'asset-btn';
        btn.dataset.drawer = 'manage';
        btn.textContent = 'Manage';
        btn.onclick = () => {
            if (state.manageMode) {
                cancelManage();
            } else {
                openManage();
            }
        };
        return btn;
    }

    // =========================================================================
    // MANAGE — in-place edit mode
    // =========================================================================

    async function openManage() {
        if (!state.currentAsset) {
            return;
        }
        state.manageMode = true;

        // Highlight manage button
        document.querySelectorAll('#detail-actions .asset-btn[data-drawer="manage"]')
            .forEach(b => b.classList.add('active'));

        // Close any open checkout/checkin drawer
        closeDrawer(true);

        // Show save bar
        document.getElementById('adp-manage-bar').hidden = false;

        // Load lookup lists (manufacturers, suppliers) if not yet done
        await ensureManageLookups();

        // Re-render grids in edit mode
        renderViewMode(state.currentAsset, state.currentActivity);
    }

    function cancelManage() {
        state.manageMode = false;
        document.getElementById('adp-manage-bar').hidden = true;
        document.querySelectorAll('#detail-actions .asset-btn[data-drawer="manage"]')
            .forEach(b => b.classList.remove('active'));
        // Re-render grids in read mode
        renderViewMode(state.currentAsset, state.currentActivity);
    }

    async function saveManage() {
        if (!state.currentAsset) {
            return;
        }
        const saveBtn = document.getElementById('adp-save-btn');
        setBtn(saveBtn, true);

        const assetId = state.currentAsset.id;
        const data = { action: 'update', asset_id: assetId };

        // Collect all editable inputs from the grids
        document.querySelectorAll('.adp-grid .adp-edit-input, .adp-grid .adp-edit-select, .adp-grid .adp-edit-textarea')
            .forEach(input => {
                if (!input.name) {
                    return;
                }
                if (input.value !== '' || input.dataset.allowEmpty === 'true') {
                    data[input.name] = input.value;
                }
            });

        try {
            const res = await apiPost(data);
            if (res.success) {
                notify('Asset saved successfully.', 'success');
                state.manageMode = false;
                document.getElementById('adp-manage-bar').hidden = true;
                document.querySelectorAll('#detail-actions .asset-btn[data-drawer="manage"]')
                    .forEach(b => b.classList.remove('active'));
                await refreshCurrentAsset();
            } else {
                notify(res.error || 'Failed to save asset.', 'error');
            }
        } catch (err) {
            notify(err?.message || 'An error occurred while saving.', 'error');
        } finally {
            setBtn(saveBtn, false);
        }
    }

    function renderActivity(entries) {
        const container = document.getElementById('detail-activity');
        if (!entries?.length) {
            container.innerHTML = '<p class="asset-detail-activity-empty">No activity recorded.</p>';
            return;
        }
        const icons = {
            checkout: { cls: 'checkout', sym: '↗' },
            checkin:  { cls: 'checkin', sym: '↙' },
            update:   { cls: 'update', sym: '✎' },
            create:   { cls: 'create', sym: '+' }
        };
        container.innerHTML = entries.map(e => {
            const action = (e.action_type || e.action || '').toLowerCase();
            const icon = icons[action] || { cls: 'default', sym: '•' };
            const who = e.user?.name || e.admin?.name || 'System';
            const target = e.target?.name || '';
            const when = e.created_at?.formatted || e.created_at || '';
            const labels = { checkout: `Checked out${target ? ` to ${target}` : ''}`, checkin: 'Returned to inventory', update: 'Updated', create: 'Asset created' };
            const summary = labels[action] || action;
            return `
            <div class="activity-entry">
                <div class="activity-icon ${icon.cls}">${icon.sym}</div>
                <div class="activity-body">
                    <div class="activity-summary">${htmlEsc(summary)}</div>
                    <div class="activity-meta">${htmlEsc(who)}${when ? ` · ${htmlEsc(when)}` : ''}</div>
                </div>
            </div>`;
        }).join('');
    }

    // =========================================================================
    // CHECKOUT MODE
    // =========================================================================
    function prepareCheckout(asset) {
        document.getElementById('adp-checkout-asset-id').value = asset.id;
        document.getElementById('adp-checkout-note').value = '';
        buildUserSearchWidget('adp-checkout-user', 'adp-user-search-widget');
    }

    // =========================================================================
    // CHECKIN MODE
    // =========================================================================
    function prepareCheckin(asset) {
        document.getElementById('adp-checkin-asset-id').value = asset.id;
        document.getElementById('adp-checkin-note').value = '';
    }

    // =========================================================================
    // Panel form handlers (checkout + checkin only; manage uses saveManage())
    // =========================================================================
    function bindPanelForms() {
        document.getElementById('adp-checkout-form')?.addEventListener('submit', onCheckoutSubmit);
        document.getElementById('adp-checkin-form')?.addEventListener('submit', onCheckinSubmit);
    }

    async function onCheckoutSubmit(e) {
        e.preventDefault();
        const assetId = document.getElementById('adp-checkout-asset-id').value;
        const assignedTo = document.getElementById('adp-checkout-user').value;
        const note = document.getElementById('adp-checkout-note').value;

        if (!assignedTo) {
            notify('Please select a user to assign the asset to.', 'warning');
            return;
        }

        const btn = e.target.querySelector('[type="submit"]');
        setBtn(btn, true);
        try {
            const res = await apiPost({ action: 'checkout', asset_id: assetId, assigned_to: assignedTo, note });
            if (res.success) {
                notify('Asset checked out successfully.', 'success');
                closeDrawer();
                await refreshCurrentAsset();
            } else {
                notify(res.error || 'Failed to check out asset.', 'error');
            }
        } catch (err) {
            notify(err?.message || 'An error occurred during checkout.', 'error');
        } finally {
            setBtn(btn, false);
        }
    }

    async function onCheckinSubmit(e) {
        e.preventDefault();
        const assetId = document.getElementById('adp-checkin-asset-id').value;
        const note = document.getElementById('adp-checkin-note').value;

        const btn = e.target.querySelector('[type="submit"]');
        setBtn(btn, true);
        try {
            const res = await apiPost({ action: 'checkin', asset_id: assetId, note });
            if (res.success) {
                notify('Asset returned to inventory.', 'success');
                closeDrawer();
                await refreshCurrentAsset();
            } else {
                notify(res.error || 'Failed to return asset.', 'error');
            }
        } catch (err) {
            notify(err?.message || 'An error occurred during return.', 'error');
        } finally {
            setBtn(btn, false);
        }
    }

    /** Re-fetch the current asset + activity after a mutation, then refresh the grid card too */
    async function refreshCurrentAsset() {
        if (!state.currentAsset) {
            return;
        }
        const id = state.currentAsset.id;
        const [ar, act] = await Promise.allSettled([
            apiGet({ action: 'get', id }),
            apiGet({ action: 'activity', id })
        ]);
        if (ar.status === 'fulfilled' && ar.value?.asset) {
            state.currentAsset = ar.value.asset;
            state.currentActivity = act.status === 'fulfilled' ? act.value?.activity ?? [] : [];
            renderViewMode(state.currentAsset, state.currentActivity);
        }
        // Silently refresh the grid in background
        loadAssets();
    }

    // =========================================================================
    // User search widget (for checkout inside panel)
    // =========================================================================
    function buildUserSearchWidget(selectId, widgetId) {
        // Tear down any existing instance
        const existing = document.getElementById(widgetId);
        if (existing) {
            const fresh = document.createElement('select');
            fresh.id = selectId; fresh.name = 'assigned_to'; fresh.required = true;
            fresh.innerHTML = '<option value="">Select a user…</option>';
            existing.replaceWith(fresh);
        }

        const oldSelect = document.getElementById(selectId);
        if (!oldSelect) {
            return;
        }
        const formGroup = oldSelect.closest('.adp-form-group, .adp-compact-row') || oldSelect.parentElement;
        if (!formGroup) {
            return;
        }

        // Hidden value carrier
        const hidden = Object.assign(document.createElement('input'), { type: 'hidden', id: selectId, name: 'assigned_to', required: true });

        // Search input
        const searchBox = Object.assign(document.createElement('input'), {
            type: 'text', id: 'adp-user-search', placeholder: 'Type a name to search…', autocomplete: 'off'
        });

        // Results list
        const results = document.createElement('ul');
        results.id = `${widgetId}-results`;
        results.className = 'user-search-results';
        results.setAttribute('role', 'listbox');
        results.hidden = true;

        // Selected display + clear
        const display = Object.assign(document.createElement('div'), { className: 'user-selected-display' });
        display.hidden = true;
        const clearBtn = Object.assign(document.createElement('button'), { type: 'button', className: 'user-clear-btn', textContent: '×' });
        clearBtn.hidden = true;
        clearBtn.onclick = () => {
            hidden.value = ''; display.textContent = ''; display.hidden = true;
            clearBtn.hidden = true; searchBox.hidden = false; searchBox.value = ''; results.hidden = true;
            searchBox.focus();
        };

        searchBox.addEventListener('input', () => {
            clearTimeout(state.userTimer);
            const q = searchBox.value.trim();
            if (!q) {
                results.hidden = true; results.innerHTML = ''; return;
            }
            state.userTimer = setTimeout(() => fetchUsers(q, results, hidden, searchBox, display, clearBtn), USER_DEBOUNCE);
        });

        // Keyboard nav — Enter selects the highlighted item, or the first item if
        // none is highlighted, so typing + Enter always works naturally.
        searchBox.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                // If the dropdown is open, pick something instead of submitting the form.
                if (!results.hidden) {
                    e.preventDefault();
                    const items = [...results.querySelectorAll('li[role="option"]')];
                    const highlighted = items.find(i => i.getAttribute('aria-selected') === 'true');
                    // Use the highlighted item, or fall back to the first result.
                    const target = highlighted || items[0];
                    if (target) {
                        target.click(); return;
                    }
                }
                // If the search is still running (results loading), block form submit.
                if (!results.hidden) {
                    e.preventDefault(); return;
                }
                return; // results hidden → let the form's own validation handle it
            }

            if (results.hidden) {
                return;
            }
            const items = [...results.querySelectorAll('li[role="option"]')];
            let idx = items.findIndex(i => i.getAttribute('aria-selected') === 'true');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                idx = Math.min(idx + 1, items.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                idx = Math.max(idx - 1, 0);
            } else if (e.key === 'Escape') {
                results.hidden = true;
                return;
            } else {
                return;
            }
            items.forEach((i, n) => i.setAttribute('aria-selected', String(n === idx)));
            items[idx]?.scrollIntoView({ block: 'nearest' });
        });

        document.addEventListener('click', e => {
            if (!formGroup.contains(e.target)) {
                results.hidden = true;
            }
        }, { passive: true });

        const wrapper = document.createElement('div');
        wrapper.id = widgetId; wrapper.className = 'user-search-widget';
        wrapper.append(hidden, searchBox, display, clearBtn, results);
        oldSelect.replaceWith(wrapper);

        setTimeout(() => searchBox.focus(), 80);
    }

    async function fetchUsers(query, results, hidden, searchBox, display, clearBtn) {
        results.innerHTML = '<li class="user-search-loading">Searching…</li>';
        results.hidden = false;
        try {
            const data = await apiGet({ action: 'users', search: query });
            const users = data?.users ?? [];
            results.innerHTML = '';

            if (!users.length) {
                results.innerHTML = `<li class="user-search-empty">No users found for "<strong>${htmlEsc(query)}</strong>" — try a shorter name.</li>`;
                return;
            }

            // Build result items
            const selectUser = (u, name, sub) => {
                hidden.value = u.id;
                display.textContent = name + sub;
                display.hidden = false;
                clearBtn.hidden = false;
                searchBox.hidden = true;
                results.hidden = true;
                results.innerHTML = '';
            };

            users.forEach((u, idx) => {
                const name = u.name || u.username || `User #${u.id}`;
                const sub = u.username ? ` (${u.username})` : '';
                const li = document.createElement('li');
                li.role = 'option';
                li.className = 'user-search-option';
                // Auto-highlight the first result so Enter immediately works
                li.setAttribute('aria-selected', idx === 0 ? 'true' : 'false');
                li.innerHTML = `<span class="user-option-name">${htmlEsc(name)}</span><span class="user-option-sub">${htmlEsc(sub)}</span>`;
                li.onclick = () => selectUser(u, name, sub);
                results.appendChild(li);
            });

            // If exactly one result, select it automatically — no extra keypress needed
            if (users.length === 1) {
                const u = users[0];
                const name = u.name || u.username || `User #${u.id}`;
                const sub = u.username ? ` (${u.username})` : '';
                selectUser(u, name, sub);
            }
        } catch (err) {
            results.innerHTML = `<li class="user-search-empty">Couldn't load users — ${htmlEsc(err?.message || 'please try again')}.</li>`;
        }
    }

    // =========================================================================
    // Utilities
    // =========================================================================
    function htmlEsc(str) {
        const d = document.createElement('div');
        d.textContent = String(str ?? '');
        return d.innerHTML;
    }

    function setBtn(btn, loading) {
        if (!btn) {
            return;
        }
        if (loading) {
            btn.dataset.orig = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Processing…';
            // Safety-net: always re-enable after REQUEST_TIMEOUT_MS + 1 s so the
            // button can never stay stuck even if a catch block is somehow skipped.
            btn._safetyTimer = setTimeout(() => setBtn(btn, false), REQUEST_TIMEOUT_MS + 1000);
        } else {
            clearTimeout(btn._safetyTimer);
            btn.disabled = false;
            btn.textContent = btn.dataset.orig || btn.textContent;
            delete btn.dataset.orig;
        }
    }

    // =========================================================================
    // Public API
    // =========================================================================
    window.assetsModule = { openDetail, closeDetail, closeDrawer, cancelManage, saveManage };
})();
