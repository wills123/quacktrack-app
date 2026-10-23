/*
 * asset-templates.js — Asset Templates page.
 * A catalog-style grid of tracked products; clicking one opens a pop-out whose
 * single form is filled with that product's template. Search and the
 * Configured / Not configured chips filter the grid in the browser.
 */
(function() {
    'use strict';

    const grid = document.getElementById('assetGrid');
    const modal = document.getElementById('assetTemplateModal');
    const dataEl = document.getElementById('assetTemplateData');
    if (!grid || !modal || !dataEl) {
        return;
    }

    const templates = JSON.parse(dataEl.textContent || '{}');
    const els = {
        title: document.getElementById('assetModalTitle'),
        badge: document.getElementById('assetModalBadge'),
        product: document.getElementById('assetModalProduct'),
        id: document.getElementById('assetProductId'),
        name: document.getElementById('assetTemplateName'),
        model: document.getElementById('assetModelId'),
        status: document.getElementById('assetStatusId'),
        prefix: document.getElementById('assetTagPrefix'),
        pattern: document.getElementById('assetNamePattern'),
        notes: document.getElementById('assetNotes'),
        active: document.getElementById('assetActive'),
        remove: document.getElementById('assetRemoveBtn')
    };
    let opener = null;

    function openTemplate(tile) {
        const id = tile.dataset.productId;
        const t = templates[id];
        if (!t) {
            return;
        }
        opener = tile;
        els.title.textContent = t.name;
        els.product.textContent = 'SKU ' + t.sku;
        els.badge.className = 'qt-status-badge qt-status-badge-' + (t.configured ? 'received' : 'partial');
        els.badge.textContent = t.configured ? 'Configured' : 'Not configured';
        els.id.value = id;
        els.name.value = t.template_name || (t.name + ' default');
        els.model.value = t.model_id ? String(t.model_id) : '';
        els.status.value = t.status_id ? String(t.status_id) : '';
        els.prefix.value = t.asset_tag_prefix || '';
        els.pattern.value = t.asset_name_template || '{{product_name}} {{serial}}';
        els.notes.value = t.default_notes || '';
        els.active.checked = Boolean(t.is_active);
        els.remove.hidden = !t.configured;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        els.name.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (opener) {
            opener.focus();
        }
    }

    grid.addEventListener('click', (event) => {
        const tile = event.target.closest('.asset-tile');
        if (tile) {
            openTemplate(tile);
        }
    });
    document.getElementById('assetModalClose').addEventListener('click', closeModal);
    document.getElementById('assetModalCancel').addEventListener('click', closeModal);
    modal.querySelector('.orders-modal-backdrop').addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    /* ---------- Search and filter ---------- */

    const search = document.getElementById('assetSearch');
    const noResults = document.getElementById('assetNoResults');
    const chips = document.querySelectorAll('.asset-chip');
    let filter = 'all';

    function applyFilter() {
        const query = (search.value || '').trim().toLowerCase();
        let visible = 0;
        grid.querySelectorAll('.asset-tile').forEach((tile) => {
            const matchesText = !query || tile.dataset.search.includes(query);
            const configured = tile.dataset.configured === '1';
            const matchesChip = filter === 'all' || (filter === 'done' ? configured : !configured);
            const show = matchesText && matchesChip;
            tile.hidden = !show;
            if (show) {
                visible++;
            }
        });
        noResults.hidden = visible > 0;
    }

    function setActiveChip(active) {
        chips.forEach((chip) => {
            chip.classList.toggle('is-active', chip === active);
            chip.setAttribute('aria-pressed', chip === active ? 'true' : 'false');
        });
    }

    search.addEventListener('input', applyFilter);
    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            filter = chip.dataset.filter;
            setActiveChip(chip);
            applyFilter();
        });
    });
}());
