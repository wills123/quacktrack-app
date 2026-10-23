(function() {
    'use strict';

    // =========================================================================
    // Configuration
    // =========================================================================

    const API_URL = '/api/snipe-it-reports.php';

    // =========================================================================
    // State Management
    // =========================================================================

    const state = {
        activeTab: 'supply',
        startDate: '',
        endDate: '',
        supplyData: [],
        equipmentData: [],
        loading: false
    };

    // =========================================================================
    // DOM Elements
    // =========================================================================

    const elements = {
        startDateInput: document.getElementById('start-date'),
        endDateInput: document.getElementById('end-date'),
        supplyContainer: document.getElementById('supply-container'),
        equipmentContainer: document.getElementById('equipment-container'),
        supplyCount: document.getElementById('supply-count'),
        equipmentCount: document.getElementById('equipment-count'),
        totalCount: document.getElementById('total-count'),
        dateRangeDisplay: document.getElementById('date-range-display')
    };

    // =========================================================================
    // Initialization
    // =========================================================================

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        // Load initial reports if dates are set
        if (elements.startDateInput.value || elements.endDateInput.value) {
            state.startDate = elements.startDateInput.value;
            state.endDate = elements.endDateInput.value;
            loadReports();
        } else {
            // Load this month by default
            setDateRange('month');
        }
    }

    // =========================================================================
    // Tab Management
    // =========================================================================

    function switchTab(tabName) {
        // Remove active class from all tabs and buttons
        document.querySelectorAll('.rpt-panel').forEach(tab => {
            tab.classList.remove('active');
        });

        document.querySelectorAll('.rpt-tab').forEach(btn => {
            btn.classList.remove('active');
            btn.setAttribute('aria-selected', 'false');
        });

        // Add active class to selected tab and button
        const tabEl = document.getElementById(tabName + '-tab');
        if (tabEl) {
            tabEl.classList.add('active');
        }

        document.querySelectorAll('.rpt-tab').forEach(btn => {
            if (btn.textContent.includes(tabName === 'supply' ? 'Supply' : 'Equipment')) {
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');
            }
        });

        state.activeTab = tabName;
    }

    // =========================================================================
    // Date Range Management
    // =========================================================================

    function setDateRange(rangeType) {
        const today = new Date();
        let startDate, endDate;

        switch (rangeType) {
        case 'month': {
            // This month
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            endDate = today;
            break;
        }
        case 'last-month': {
            // Last month
            startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            endDate = new Date(today.getFullYear(), today.getMonth(), 0);
            break;
        }
        case 'quarter': {
            // Last 3 months
            startDate = new Date(today);
            startDate.setMonth(today.getMonth() - 3);
            endDate = today;
            break;
        }
        case 'year': {
            // This year
            startDate = new Date(today.getFullYear(), 0, 1);
            endDate = today;
            break;
        }
        case 'all': {
            // All time
            startDate = null;
            endDate = null;
            break;
        }
        default:
            return;
        }

        // Set date inputs
        if (startDate) {
            elements.startDateInput.value = formatDateForInput(startDate);
            state.startDate = formatDateForInput(startDate);
        } else {
            elements.startDateInput.value = '';
            state.startDate = '';
        }

        if (endDate) {
            elements.endDateInput.value = formatDateForInput(endDate);
            state.endDate = formatDateForInput(endDate);
        } else {
            elements.endDateInput.value = '';
            state.endDate = '';
        }

        // Update button active state
        document.querySelectorAll('.rpt-chip').forEach(btn => {
            btn.classList.remove('active');
        });

        const activeBtn = Array.from(document.querySelectorAll('.rpt-chip')).find(btn => {
            const text = btn.textContent.toLowerCase();
            return text.includes(
                rangeType === 'month' ? 'this month' :
                    rangeType === 'last-month' ? 'last month' :
                        rangeType === 'quarter' ? '3 months' :
                            rangeType === 'year' ? 'this year' :
                                'all'
            );
        });

        if (activeBtn) {
            activeBtn.classList.add('active');
        }

        // Load reports
        loadReports();
    }

    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function formatDateForDisplay(date) {
        return new Date(date + 'T00:00:00Z').toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // =========================================================================
    // Report Loading
    // =========================================================================

    async function loadReports() {
        if (state.loading) {
            return;
        }

        state.loading = true;
        showLoading();

        try {
            // Get date range from inputs
            state.startDate = elements.startDateInput.value;
            state.endDate = elements.endDateInput.value;

            // Fetch combined report
            const params = new URLSearchParams({
                action: 'combined_summary'
            });

            if (state.startDate) {
                params.append('start_date', state.startDate);
            }

            if (state.endDate) {
                params.append('end_date', state.endDate);
            }

            const response = await fetch(`${API_URL}?${params}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                let serverMessage = null;
                try {
                    const errBody = await response.json();
                    serverMessage = errBody.error || null;
                } catch (_) { /* ignore json parse error */ }

                if (response.status === 503) {
                    throw new Error(serverMessage || 'Snipe-IT is not configured on the server. Contact your administrator.');
                }
                throw new Error(serverMessage || `HTTP ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                showError(data.error || 'Failed to load reports');
                return;
            }

            state.supplyData = data.data.supply_usage || [];
            state.equipmentData = data.data.equipment_checkouts || [];

            // Update UI
            updateSummary();
            renderSupplyTable();
            renderEquipmentTable();

        } catch (error) {
            console.error('Report loading error:', error);
            showError(`Failed to load reports: ${error.message}`);
        } finally {
            state.loading = false;
        }
    }

    // =========================================================================
    // Summary Update
    // =========================================================================

    function updateSummary() {
        const supplyCount = state.supplyData.length;
        const equipmentCount = state.equipmentData.length;
        const totalCount = supplyCount + equipmentCount;

        elements.supplyCount.textContent = supplyCount.toString();
        elements.equipmentCount.textContent = equipmentCount.toString();
        elements.totalCount.textContent = totalCount.toString();

        // Update date range display
        let dateRangeText = '—';
        if (state.startDate && state.endDate) {
            dateRangeText = `${formatDateForDisplay(state.startDate)} - ${formatDateForDisplay(state.endDate)}`;
        } else if (state.startDate) {
            dateRangeText = `From ${formatDateForDisplay(state.startDate)}`;
        } else if (state.endDate) {
            dateRangeText = `Until ${formatDateForDisplay(state.endDate)}`;
        } else {
            dateRangeText = 'All Time';
        }

        elements.dateRangeDisplay.textContent = dateRangeText;
    }

    // =========================================================================
    // Table Rendering
    // =========================================================================

    function actionBadgeHtml(action) {
        if (!action || action === '—') {
            return '<span class="rpt-badge rpt-badge--default">—</span>';
        }
        const slug = action.toLowerCase();
        let cls = 'rpt-badge--default';
        if (slug.includes('checkout')) {
            cls = 'rpt-badge--checkout';
        } else if (slug.includes('checkin')) {
            cls = 'rpt-badge--checkin';
        } else if (slug.includes('create')) {
            cls = 'rpt-badge--create';
        } else if (slug.includes('update')) {
            cls = 'rpt-badge--update';
        }
        return `<span class="rpt-badge ${cls}">${htmlEscape(action)}</span>`;
    }

    function renderSupplyTable() {
        if (state.supplyData.length === 0) {
            elements.supplyContainer.innerHTML = `
                <div class="rpt-state">
                    <div class="rpt-state-eyebrow">Supply Usage</div>
                    <div class="rpt-state-msg">No supply usage found for this period</div>
                    <div class="rpt-state-sub">Try expanding the date range</div>
                </div>`;
            return;
        }

        const rows = state.supplyData.map(item => `
            <tr>
                <td class="rpt-col-product">${htmlEscape(item.product_name || 'Unknown')}</td>
                <td class="rpt-col-qty">${htmlEscape((item.quantity || 0).toString())}</td>
                <td class="rpt-col-type">${actionBadgeHtml(item.transaction_type || '—')}</td>
                <td class="rpt-col-user">${htmlEscape(formatUserName(item))}</td>
                <td class="rpt-col-date">${formatDate(item.created_at)}</td>
            </tr>
        `).join('');

        elements.supplyContainer.innerHTML = `
            <table class="rpt-table" data-rpt-upgraded="1">
                <thead>
                    <tr>
                        <th class="rpt-col-product">Product</th>
                        <th class="rpt-col-qty">Qty</th>
                        <th class="rpt-col-type">Type</th>
                        <th class="rpt-col-user">User</th>
                        <th class="rpt-col-date">Date</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    function renderEquipmentTable() {
        if (state.equipmentData.length === 0) {
            elements.equipmentContainer.innerHTML = `
                <div class="rpt-state">
                    <div class="rpt-state-eyebrow">Equipment Checkouts</div>
                    <div class="rpt-state-msg">No equipment activity found for this period</div>
                    <div class="rpt-state-sub">Try expanding the date range</div>
                </div>`;
            return;
        }

        const rows = state.equipmentData.map(item => `
            <tr>
                <td class="rpt-col-asset">${htmlEscape(item.item_name || item.asset_name || 'Unknown')}</td>
                <td class="rpt-col-action">${actionBadgeHtml(item.action_type || item.action || '—')}</td>
                <td class="rpt-col-user">${htmlEscape(item.user_name || '—')}</td>
                <td class="rpt-col-date">${formatDate(item.created_at)}</td>
            </tr>
        `).join('');

        elements.equipmentContainer.innerHTML = `
            <table class="rpt-table" data-rpt-upgraded="1">
                <thead>
                    <tr>
                        <th class="rpt-col-asset">Asset</th>
                        <th class="rpt-col-action">Action</th>
                        <th class="rpt-col-user">User</th>
                        <th class="rpt-col-date">Date</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    // =========================================================================
    // CSV Export
    // =========================================================================

    async function exportCsv() {
        if (state.loading) {
            return;
        }

        state.loading = true;

        try {
            const params = new URLSearchParams({
                action: 'export_csv'
            });

            if (state.startDate) {
                params.append('start_date', state.startDate);
            }

            if (state.endDate) {
                params.append('end_date', state.endDate);
            }

            // Create form and submit for download
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = API_URL;

            Object.entries({
                action: 'export_csv',
                start_date: state.startDate,
                end_date: state.endDate
            }).forEach(([key, value]) => {
                if (value) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = value;
                    form.appendChild(input);
                }
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);

        } catch (error) {
            console.error('Export error:', error);
            showError(`Failed to export: ${error.message}`);
        } finally {
            state.loading = false;
        }
    }

    // =========================================================================
    // Utility Functions
    // =========================================================================

    function formatDate(dateStr) {
        if (!dateStr) {
            return '—';
        }
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        } catch (e) {
            return dateStr;
        }
    }

    function formatUserName(item) {
        if (item.first_name || item.last_name) {
            return `${item.first_name || ''} ${item.last_name || ''}`.trim();
        }
        return item.user_name || 'Unknown';
    }

    function htmlEscape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showLoading() {
        const loadHTML = (label) => `<div class="rpt-state">
            <div class="rpt-state-eyebrow">${htmlEscape(label)}</div>
            <div class="rpt-state-msg">Loading data</div>
            <div class="rpt-loading-dots"><span></span><span></span><span></span></div>
        </div>`;
        elements.supplyContainer.innerHTML = loadHTML('Supply Usage');
        elements.equipmentContainer.innerHTML = loadHTML('Equipment Checkouts');
    }

    function showError(message) {
        const html = `<div class="rpt-error-bar"><strong>Error</strong> ${htmlEscape(message)}</div>`;
        elements.supplyContainer.innerHTML = html;
        elements.equipmentContainer.innerHTML = html;
    }

    // =========================================================================
    // Expose to Global Scope
    // =========================================================================

    window.reportsModule = {
        switchTab,
        setDateRange,
        loadReports,
        exportCsv
    };
})();
