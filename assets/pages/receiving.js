/* global createSecureFormData, imageUrl */
/*
 * receiving.js — Receiving page behaviour.
 * Each order opens in a pop-out where the user receives its supplies (van and/or
 * region stock), matches tracked assets by serial number or camera scan, and adds
 * assets Snipe-IT doesn't know yet. Relies on secureFetch/createSecureFormData
 * from csrf-api.js, imageUrl and window.loadScannerLibrary from the header.
 */
(function() {
    'use strict';

    const page = document.querySelector('.receiving-page');
    if (!page) {
        return;
    }

    const canManageAssets = page.dataset.canManageAssets === '1';
    const modal = document.getElementById('receivingModal');
    const titleEl = document.getElementById('receivingModalTitle');
    const badgeEl = document.getElementById('receivingModalBadge');
    const messageEl = document.getElementById('receivingModalMessage');
    const bodyEl = document.getElementById('receivingModalBody');
    const cancelOrderBtn = document.getElementById('receivingModalCancelOrder');
    const doneBtn = document.getElementById('receivingModalDone');
    const closeBtn = document.getElementById('receivingModalClose');
    const cancelDialog = document.getElementById('receivingCancelDialog');
    const cancelOrderLabel = document.getElementById('receivingCancelOrder');
    const scannerModal = document.getElementById('trackedAssetScannerModal');

    // card: the order card that opened the pop-out; dirty: something changed, so
    // the list behind it should refresh when the pop-out closes.
    const state = { card: null, data: null, dirty: false, opener: null };
    let scannerReader = null;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value === null || value === undefined ? '' : value);
        return div.innerHTML;
    }

    // Unlike the shared handleAPIResponse, keep the server's own message and
    // details (e.g. "No matching Snipe-IT asset was found...") instead of a generic one.
    async function post(url, fields) {
        const response = await secureFetch(url, { method: 'POST', body: createSecureFormData(fields) });
        let data = null;
        try {
            data = await response.json();
        } catch (_error) {
            data = null;
        }
        if (!response.ok || !data || data.success === false) {
            const error = new Error((data && (data.error || data.message)) || ('Request failed (' + response.status + ').'));
            error.status = response.status;
            error.details = (data && data.details) || {};
            throw error;
        }
        return data;
    }

    function setMessage(text, kind) {
        messageEl.textContent = text || '';
        messageEl.className = 'receiving-modal-message' + (kind ? ' receiving-modal-message--' + kind : '');
        messageEl.hidden = !text;
    }

    function setBadge(status) {
        const key = status === 'partially_received' ? 'partial' : status;
        badgeEl.className = 'qt-status-badge qt-status-badge-' + key;
        badgeEl.textContent = String(status || '').replace('_', ' ');
    }

    /* ---------- Open / close ---------- */

    function openOrder(card, opener) {
        state.card = card;
        state.data = null;
        state.dirty = false;
        state.opener = opener;
        titleEl.textContent = 'Order ' + card.dataset.orderNumber;
        const cardBadge = card.querySelector('.qt-status-badge');
        setBadge(cardBadge ? cardBadge.textContent.trim().replace(' ', '_') : '');
        cancelOrderBtn.hidden = card.dataset.canCancel !== '1';
        cancelOrderBtn.disabled = false;
        setMessage('');
        bodyEl.innerHTML = '<div class="orders-modal-loading">Loading order…</div>';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
        load();
    }

    function closeModal() {
        stopScanner();
        modal.hidden = true;
        document.body.style.overflow = '';
        if (state.dirty) {
            window.location.reload();
            return;
        }
        if (state.opener) {
            state.opener.focus();
        }
        state.card = null;
    }

    async function load() {
        const card = state.card;
        try {
            const response = await fetch('/api/order_details.php?order_id=' + encodeURIComponent(card.dataset.orderId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.error || data.message || 'Could not load this order.');
            }
            state.data = data;
            setBadge(data.order.status);
            render();
        } catch (error) {
            bodyEl.innerHTML = '<p class="orders-modal-error">' + escapeHtml(error.message) + '</p>';
        }
    }

    document.querySelectorAll('.receiving-open-btn').forEach((button) => {
        button.addEventListener('click', () => openOrder(button.closest('.receiving-card'), button));
    });
    closeBtn.addEventListener('click', closeModal);
    doneBtn.addEventListener('click', closeModal);
    modal.querySelector('.orders-modal-backdrop').addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (scannerModal && scannerModal.classList.contains('show')) {
            stopScanner();
        } else if (!modal.hidden && !cancelDialog.open) {
            closeModal();
        }
    });

    /* ---------- Rendering ---------- */

    function trackedReceiptHtml(receipt) {
        const label = escapeHtml(receipt.serial || receipt.asset_tag || ('Asset #' + receipt.snipe_asset_id));
        const disposition = String(receipt.disposition || 'active');
        if (canManageAssets && disposition === 'active') {
            return '<li><div class="tracked-receipt-asset">' + label + '</div>' +
                '<form class="tracked-disposition-form" data-receipt-id="' + Number(receipt.id) + '">' +
                '<select name="disposition" aria-label="Asset disposition">' +
                '<option value="returned">Return to stock</option><option value="repair">Send for repair</option>' +
                '<option value="retired">Retire</option><option value="lost">Mark lost</option></select>' +
                '<input name="note" maxlength="500" placeholder="Reason" aria-label="Disposition reason">' +
                '<button type="submit" class="orders-btn-secondary">Save</button>' +
                '<span class="tracked-disposition-message" role="status" aria-live="polite"></span></form></li>';
        }
        return '<li><div class="tracked-receipt-asset">' + label + '</div>' +
            '<span class="tracked-receipt-disposition">' + escapeHtml(disposition) + '</span></li>';
    }

    function trackedBoxHtml(item, canMatch) {
        const receipts = Array.isArray(item.tracked_receipts) ? item.tracked_receipts : [];
        const id = Number(item.id);
        const total = Number(item.quantity);
        const unit = receipts.length + 1;
        const form = canMatch && receipts.length < total ?
            '<p class="tracked-unit-hint">' + (total > 1 ? 'Unit ' + unit + ' of ' + total + ': e' : 'E') + 'nter its serial number or asset tag. Each unit is matched (or added to Snipe-IT) one at a time.</p>' +
            '<form class="tracked-receive-form" data-order-item-id="' + id + '">' +
            '<label class="sr-only" for="trackedIdentifier-' + id + '">Serial number or asset tag for unit ' + unit + ' of ' + total + '</label>' +
            '<input id="trackedIdentifier-' + id + '" name="identifier" class="orders-form-input" maxlength="255" placeholder="Serial number or asset tag" autocomplete="off">' +
            '<button type="submit" class="orders-btn-primary">Match asset</button>' +
            '<button type="button" class="orders-btn-secondary tracked-scan-btn" data-order-item-id="' + id + '">Scan</button></form>' : '';
        return '<div class="tracked-receive-box" data-order-item-id="' + id + '">' +
            '<div class="tracked-receive-summary"><strong>Tracked asset receiving</strong>' +
            '<span>' + receipts.length + ' of ' + total + ' matched</span></div>' +
            form +
            '<div class="tracked-receive-message" role="status" aria-live="polite"></div>' +
            (receipts.length ? '<ul class="tracked-receipt-list">' + receipts.map(trackedReceiptHtml).join('') + '</ul>' : '') +
            '</div>';
    }

    function itemHtml(item, extraHtml, splitText) {
        const image = item.image_path ?
            '<img class="orders-item-img" width="56" height="56" loading="lazy" src="' + escapeHtml(imageUrl(item.image_path)) + '" alt="' + escapeHtml(item.name) + '">' :
            '<div class="orders-item-img orders-item-no-img">No Image</div>';
        return '<div class="orders-item">' + image +
            '<div class="orders-item-info">' +
            '<p class="orders-item-name">' + escapeHtml(item.name) + '</p>' +
            '<p class="orders-item-sku">SKU: ' + escapeHtml(item.sku) + '</p>' +
            '<p class="orders-item-qty">Qty: ' + Number(item.quantity) + (splitText ? ' · ' + escapeHtml(splitText) : '') + '</p>' +
            (item.notes ? '<p class="orders-item-notes">' + escapeHtml(item.notes) + '</p>' : '') +
            (extraHtml || '') +
            '</div></div>';
    }

    function splitLabel(item, card) {
        if (card.dataset.van !== '1' || card.dataset.region !== '1') {
            return '';
        }
        return 'Van ' + Number(item.receive_to_van_qty || 0) + ' · Region ' + Number(item.receive_to_region_qty || 0);
    }

    function destinationLabel(card) {
        const van = card.dataset.van === '1';
        const region = card.dataset.region === '1';
        if (van && region) {
            return 'van and regional stock';
        }
        return region ? 'regional stock' : 'van stock';
    }

    function suppliesActionHtml(card, supplyCount) {
        if (supplyCount === 0) {
            return '';
        }
        if (Number(card.dataset.suppliesPending) === 0) {
            return '<p class="rm-done">✓ Supplies received</p>';
        }
        if (card.dataset.canReceive !== '1') {
            return '<p class="rm-hint">Supplies can be received by the person who ordered them, someone in the receiving region, or a manager.</p>';
        }
        const location = card.dataset.region === '1' ?
            '<label class="receiving-dialog-field">Region storage location (optional)' +
            '<input type="text" id="receivingLocation" maxlength="255" placeholder="e.g. Aisle 3, Shelf B"></label>' : '';
        return '<div class="rm-receive">' + location +
            '<button type="button" class="orders-btn-primary" id="receivingReceiveBtn">Receive supplies into ' + destinationLabel(card) + '</button></div>';
    }

    function render() {
        const card = state.card;
        const items = state.data.items || [];
        const supplies = items.filter((item) => Number(item.is_tracked_asset) !== 1);
        const tracked = items.filter((item) => Number(item.is_tracked_asset) === 1);
        const canMatch = card.dataset.canMatch === '1';

        let html = '<div class="order-card-meta rm-summary">' +
            ['Technician', 'Store', 'Placed', 'Receives into'].map((label, index) => {
                const value = [card.dataset.technician, card.dataset.store, card.dataset.placed, card.dataset.destination][index];
                return '<div class="order-meta-row"><span class="order-meta-label">' + label + '</span>' +
                    '<span class="order-meta-value">' + escapeHtml(value) + '</span></div>';
            }).join('') + '</div>';

        if (supplies.length) {
            html += '<section class="rm-section"><h3 class="rm-section-title">Supplies (' + supplies.length + ')</h3>' +
                '<div class="orders-items-list">' + supplies.map((item) => itemHtml(item, '', splitLabel(item, card))).join('') + '</div>' +
                suppliesActionHtml(card, supplies.length) + '</section>';
        }
        if (tracked.length) {
            html += '<section class="rm-section"><h3 class="rm-section-title">Tracked assets (' + tracked.length + ')</h3>' +
                '<div class="orders-items-list">' + tracked.map((item) => itemHtml(item, trackedBoxHtml(item, canMatch), '')).join('') + '</div></section>';
        }
        bodyEl.innerHTML = html;
        wireBody();
    }

    function wireBody() {
        const receiveBtn = document.getElementById('receivingReceiveBtn');
        if (receiveBtn) {
            receiveBtn.addEventListener('click', () => receiveSupplies(receiveBtn));
        }
        bodyEl.querySelectorAll('.tracked-receive-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const message = form.closest('.tracked-receive-box').querySelector('.tracked-receive-message');
                receiveTrackedAsset(Number(form.dataset.orderItemId), form.elements.identifier.value, message);
            });
        });
        bodyEl.querySelectorAll('.tracked-scan-btn').forEach((button) => {
            button.addEventListener('click', () => startScanner(Number(button.dataset.orderItemId)));
        });
        bodyEl.querySelectorAll('.tracked-disposition-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                disposeAsset(Number(form.dataset.receiptId), form, form.querySelector('.tracked-disposition-message'));
            });
        });
    }

    /* ---------- Receive supplies ---------- */

    async function receiveSupplies(button) {
        const card = state.card;
        const locationInput = document.getElementById('receivingLocation');
        setMessage('');
        button.disabled = true;
        try {
            const payload = await post('/api/orders.php', {
                action: 'update_status',
                order_id: card.dataset.orderId,
                status: 'received',
                receive_to_van: card.dataset.van === '1' ? 1 : 0,
                receive_to_region: card.dataset.region === '1' ? 1 : 0,
                receive_to_region_location: locationInput ? locationInput.value.trim() : ''
            });
            card.dataset.suppliesPending = '0';
            card.dataset.canReceive = '0';
            state.dirty = true;
            const done = payload.status === 'received';
            setMessage((payload.receipt_message || 'Supplies received.') + (done ? ' This order is complete.' : ''), 'success');
            await load();
        } catch (error) {
            setMessage(error.message || 'Receipt could not be recorded.', 'error');
            button.disabled = false;
        }
    }

    /* ---------- Cancel order ---------- */

    cancelOrderBtn.addEventListener('click', () => {
        cancelOrderLabel.textContent = state.card.dataset.orderNumber;
        cancelDialog.showModal();
    });

    cancelDialog.addEventListener('close', async () => {
        if (cancelDialog.returnValue !== 'confirm' || !state.card) {
            return;
        }
        setMessage('');
        cancelOrderBtn.disabled = true;
        try {
            await post('/api/orders.php', { action: 'cancel', order_id: state.card.dataset.orderId });
            state.dirty = true;
            closeModal();
        } catch (error) {
            setMessage(error.message || 'Order could not be cancelled.', 'error');
            cancelOrderBtn.disabled = false;
        }
    });

    /* ---------- Tracked assets ---------- */

    function messageForItem(orderItemId) {
        return bodyEl.querySelector('[data-order-item-id="' + orderItemId + '"] .tracked-receive-message');
    }

    // After a unit is matched, put the cursor in the field for the next one.
    function focusNextUnit(orderItemId) {
        const next = bodyEl.querySelector('[data-order-item-id="' + orderItemId + '"] input[name="identifier"]');
        if (next) {
            next.focus();
        }
    }

    async function receiveTrackedAsset(orderItemId, identifier, messageTarget) {
        const value = String(identifier || '').trim();
        if (!value) {
            messageTarget.textContent = 'Enter or scan a serial number or asset tag.';
            return;
        }
        messageTarget.textContent = 'Checking Snipe-IT…';
        messageTarget.className = 'tracked-receive-message';
        try {
            const payload = await post('/api/orders.php', {
                action: 'receive_tracked_asset',
                order_item_id: orderItemId,
                identifier: value
            });
            state.dirty = true;
            if (payload.order_status === 'received') {
                setMessage('Every item on this order has been received.', 'success');
            }
            await load();
            const fresh = messageForItem(orderItemId);
            if (fresh) {
                const sync = payload.snipe_sync && !payload.snipe_sync.synced ? ' ' + payload.snipe_sync.message : '';
                fresh.textContent = (payload.message || 'Asset matched successfully.') + sync;
                fresh.className = 'tracked-receive-message tracked-receive-message--success';
            }
            focusNextUnit(orderItemId);
        } catch (error) {
            messageTarget.textContent = error.message || 'Unable to match this asset.';
            messageTarget.className = 'tracked-receive-message tracked-receive-message--error';
            if (error.status === 404 && error.details && error.details.review_id) {
                showAddForm(orderItemId, Number(error.details.review_id), messageTarget);
            }
        }
    }

    async function disposeAsset(receiptId, form, messageTarget) {
        const note = String(form.elements.note.value || '').trim();
        if (!note) {
            messageTarget.textContent = 'Add a reason before saving.';
            return;
        }
        messageTarget.textContent = 'Updating Snipe-IT…';
        try {
            await post('/api/tracked-assets.php', {
                action: 'dispose',
                receipt_id: receiptId,
                disposition: form.elements.disposition.value,
                note: note
            });
            state.dirty = true;
            await load();
        } catch (error) {
            messageTarget.textContent = error.message || 'Unable to update asset.';
        }
    }

    /* ---------- Add an unknown asset to Snipe-IT ---------- */

    function optionsHtml(list, selected, placeholder) {
        return '<option value="">' + placeholder + '</option>' + list.map((entry) =>
            '<option value="' + Number(entry.id) + '"' + (Number(entry.id) === Number(selected) ? ' selected' : '') + '>' + escapeHtml(entry.name) + '</option>'
        ).join('');
    }

    async function showAddForm(orderItemId, reviewId, messageTarget) {
        const box = messageTarget.closest('.tracked-receive-box');
        const existing = box.querySelector('.asset-add-form');
        if (existing) {
            existing.remove();
        }
        const holder = document.createElement('div');
        holder.className = 'asset-add-form';
        holder.innerHTML = '<p class="asset-message">Loading Snipe-IT options…</p>';
        box.appendChild(holder);

        let options;
        try {
            const response = await fetch('/api/snipe-asset-review.php?action=options&review_id=' + encodeURIComponent(reviewId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin'
            });
            options = await response.json();
            if (!response.ok || options.success === false) {
                throw new Error(options.error || 'Could not load Snipe-IT options.');
            }
        } catch (error) {
            holder.innerHTML = '<p class="asset-message asset-message--error">' + escapeHtml(error.message) + '</p>';
            return;
        }

        const d = options.defaults;
        holder.innerHTML = '<form class="asset-add-form-fields" novalidate>' +
            '<p class="asset-add-title">Not in Snipe-IT yet. Add it now' + (options.has_template ? ' (defaults loaded from the product template)' : '') + ':</p>' +
            '<div class="asset-form">' +
            '<label>Asset name<input name="name" required maxlength="255" value="' + escapeHtml(d.name) + '"></label>' +
            '<label>Asset tag<input name="asset_tag" required maxlength="255" value="' + escapeHtml(d.asset_tag) + '"></label>' +
            '<label>Serial number<input name="serial" maxlength="255" value="' + escapeHtml(d.serial) + '"></label>' +
            '<label>Model<select name="model_id" required>' + optionsHtml(options.models, d.model_id, 'Select model…') + '</select></label>' +
            '<label>Status<select name="status_id" required>' + optionsHtml(options.statuses, d.status_id, 'Select status…') + '</select></label>' +
            '<label class="asset-form-wide">Note<textarea name="review_note" maxlength="500" placeholder="Optional context for the asset record">' + escapeHtml(d.note) + '</textarea></label>' +
            '</div>' +
            '<div class="asset-add-actions"><span class="asset-message" role="status" aria-live="polite"></span>' +
            '<button type="button" class="orders-btn-secondary asset-add-cancel">Not now</button>' +
            '<button type="submit" class="orders-btn-primary">Add to Snipe-IT</button></div></form>';

        const form = holder.querySelector('form');
        const note = holder.querySelector('.asset-add-actions .asset-message');
        holder.querySelector('.asset-add-cancel').addEventListener('click', () => holder.remove());
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const f = form.elements;
            if (!f.name.value.trim() || !f.asset_tag.value.trim() || !f.model_id.value || !f.status_id.value) {
                note.textContent = 'Asset name, tag, model and status are required.';
                note.className = 'asset-message asset-message--error';
                return;
            }
            note.textContent = 'Creating in Snipe-IT…';
            note.className = 'asset-message';
            try {
                const payload = await post('/api/snipe-asset-review.php', {
                    action: 'create_asset',
                    review_id: reviewId,
                    name: f.name.value.trim(),
                    asset_tag: f.asset_tag.value.trim(),
                    serial: f.serial.value.trim(),
                    model_id: f.model_id.value,
                    status_id: f.status_id.value,
                    review_note: f.review_note.value.trim()
                });
                state.dirty = true;
                if (payload.order_status === 'received') {
                    setMessage('Every item on this order has been received.', 'success');
                }
                await load();
                const fresh = messageForItem(orderItemId);
                if (fresh) {
                    const sync = payload.snipe_sync && !payload.snipe_sync.synced ? ' ' + payload.snipe_sync.message : '';
                    fresh.textContent = (payload.message || 'Asset added to Snipe-IT and matched.') + sync;
                    fresh.className = 'tracked-receive-message tracked-receive-message--success';
                }
                focusNextUnit(orderItemId);
            } catch (error) {
                note.textContent = error.message || 'Could not add the asset to Snipe-IT.';
                note.className = 'asset-message asset-message--error';
            }
        });
        form.elements.asset_tag.focus();
    }

    /* ---------- Camera scanner ---------- */

    function stopScanner() {
        try {
            if (scannerReader) {
                scannerReader.reset();
            }
        } catch (_error) {
            /* ignore scanner shutdown errors */
        }
        scannerReader = null;
        if (scannerModal) {
            scannerModal.classList.remove('show');
        }
    }

    async function startScanner(orderItemId) {
        const video = document.getElementById('trackedAssetScannerVideo');
        const result = document.getElementById('trackedAssetScannerResult');
        if (!scannerModal || !video || !result) {
            return;
        }
        // The shared .modal is display:none until it has the "show" class.
        scannerModal.classList.add('show');
        result.textContent = 'Starting scanner…';
        try {
            if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                throw new Error('Camera access requires HTTPS. Use the serial number field instead.');
            }
            if (typeof window.loadScannerLibrary !== 'function') {
                throw new Error('Scanner is unavailable. Use the serial number field instead.');
            }
            const ZXingLib = await window.loadScannerLibrary();
            scannerReader = new ZXingLib.BrowserMultiFormatReader();
            scannerReader.decodeFromVideoDevice(undefined, video, (scanResult) => {
                if (!scanResult) {
                    return;
                }
                const identifier = String(scanResult.text || '').trim();
                stopScanner();
                const target = messageForItem(orderItemId);
                if (target) {
                    receiveTrackedAsset(orderItemId, identifier, target);
                }
            });
            result.textContent = 'Point your camera at the asset tag or barcode.';
        } catch (error) {
            result.textContent = error.message || 'Unable to start scanner.';
        }
    }

    ['trackedAssetScannerClose', 'trackedAssetScannerCloseFooter', 'trackedAssetScannerStop']
        .map((id) => document.getElementById(id))
        .filter(Boolean)
        .forEach((button) => button.addEventListener('click', stopScanner));
}());
