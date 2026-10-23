(function() {
    'use strict';

    if (window.__quackTrackDeploymentShipmentsInitialized) {
        return;
    }
    window.__quackTrackDeploymentShipmentsInitialized = true;

    const config = window.DEPLOYMENT_PAGE || {};
    const endpoint = '/api/deployment-shipments.php';
    const state = { deployment: config.selectedDeployment || null, shipments: [], shipment: null, filter: '', request: null, returnFocus: null, keepDialogReturnFocus: false };
    const elements = {};
    const statuses = ['all', 'pending', 'preparing', 'dispatching', 'dispatched', 'partially_received', 'received', 'declined', 'cancelled'];

    const esc = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const label = (value) => String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    const qty = (value) => Math.max(0, Number.parseInt(value, 10) || 0);
    const notification = (message, type = 'error') => window.QuackNotify ? window.QuackNotify.show(message, type) : window.alert(message);

    function init() {
        elements.questsTab = document.getElementById('deploymentQuestsTab');
        elements.shipmentsTab = document.getElementById('deploymentShipmentsTab');
        elements.questsWorkspace = document.getElementById('deploymentQuestsWorkspace');
        elements.workspace = document.getElementById('deploymentShipmentsWorkspace');
        elements.context = document.getElementById('deploymentShipmentsContext');
        elements.filters = document.getElementById('deploymentShipmentStatusFilters');
        elements.list = document.getElementById('deploymentShipmentsList');
        elements.detail = document.getElementById('deploymentShipmentDetail');
        elements.layout = elements.workspace?.querySelector('.deployment-shipments__layout');
        elements.requestButtons = [document.getElementById('deploymentShipmentsRequestBtn')].filter(Boolean);
        if (!elements.workspace) {
            return;
        }
        elements.questsTab?.addEventListener('click', () => switchWorkspace('quests'));
        elements.shipmentsTab?.addEventListener('click', () => switchWorkspace('shipments'));
        elements.requestButtons.forEach((button) => button.addEventListener('click', openRequest));
        elements.filters.addEventListener('click', handleFilterClick);
        elements.list.addEventListener('click', handleShipmentClick);
        elements.detail.addEventListener('click', handleDetailClick);
        document.addEventListener('quacktrack:deployment-selected', (event) => {
            state.deployment = event.detail?.deployment || null;
            state.shipment = null;
            renderContext();
            if (!elements.workspace.hidden) {
                loadShipments();
            }
        });
        renderFilters();
        renderContext();
    }

    function switchWorkspace(which) {
        const shipments = which === 'shipments';
        elements.workspace.hidden = !shipments;
        elements.questsWorkspace.hidden = shipments;
        elements.shipmentsTab?.classList.toggle('is-active', shipments);
        elements.questsTab?.classList.toggle('is-active', !shipments);
        elements.shipmentsTab?.setAttribute('aria-selected', String(shipments));
        elements.questsTab?.setAttribute('aria-selected', String(!shipments));
        if (shipments) {
            loadShipments();
        }
    }

    function renderContext() {
        const deployment = state.deployment;
        elements.context.textContent = deployment ? `Shipments for ${deployment.site_name || deployment.project_code || 'selected campaign'}.` : 'Select a campaign to view its shipments.';
    }

    function renderFilters() {
        elements.filters.innerHTML = statuses.map((status) => `<button type="button" class="deployment-shipment-filter${state.filter === (status === 'all' ? '' : status) ? ' is-active' : ''}" data-shipment-filter="${status === 'all' ? '' : status}">${esc(label(status))}</button>`).join('');
    }

    function handleFilterClick(event) {
        const button = event.target.closest('[data-shipment-filter]');
        if (!button) {
            return;
        }
        state.filter = button.dataset.shipmentFilter || '';
        renderFilters();
        loadShipments();
    }

    async function api(action, formData, method = 'POST') {
        const payload = formData || new FormData();
        if (method === 'POST') {
            payload.set('action', action);
            payload.set('csrf', config.csrf || '');
        }
        const url = method === 'GET' ? `${endpoint}?${new URLSearchParams(Object.fromEntries(payload.entries())).toString()}` : endpoint;
        const response = await fetch(url, method === 'GET' ? { credentials: 'same-origin' } : { method: 'POST', body: payload, credentials: 'same-origin' });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.success === false) {
            throw new Error(data.error || data.message || 'Unable to complete shipment action.');
        }
        return data.data || {};
    }

    async function loadShipments() {
        if (!state.deployment?.id) {
            elements.list.innerHTML = '<p class="text-muted">Select a campaign to view shipments.</p>';
            return;
        }
        elements.list.innerHTML = '<p class="text-muted">Loading shipments…</p>';
        try {
            const form = new FormData();
            form.set('action', 'list'); form.set('deployment_id', String(state.deployment.id)); form.set('limit', '100');
            if (state.filter) {
                form.set('status', state.filter);
            }
            const data = await api('list', form, 'GET');
            state.shipments = Array.isArray(data.shipments) ? data.shipments : [];
            renderShipmentList();
        } catch (error) {
            elements.list.innerHTML = `<p class="text-danger">${esc(error.message)}</p>`;
        }
    }

    function renderShipmentList() {
        if (!state.shipments.length) {
            elements.list.innerHTML = '<p class="text-muted">No shipments match this view. Create a request when this campaign needs equipment.</p>';
            return;
        }
        elements.list.innerHTML = state.shipments.map((shipment) => {
            const selected = Number(state.shipment?.id) === Number(shipment.id);
            const issueCount = qty(shipment.issue_count);
            return `<button type="button" class="deployment-shipment-card${selected ? ' is-selected' : ''}" data-shipment-id="${esc(shipment.id)}" aria-pressed="${selected}">
        <span class="deployment-shipment-card__top"><span class="deployment-shipment-card__kit">${esc(shipment.kit_name_snapshot || shipment.kit_template_name || 'Shipment')}</span><span class="deployment-shipment-badge${['received', 'approved'].includes(shipment.status) ? ' deployment-shipment-badge--complete' : ''}">${esc(label(shipment.status))}</span></span>
        <span class="deployment-shipment-card__meta"><span>Needed ${esc(shipment.needed_by || 'Not set')}</span><span>${esc(qty(shipment.quantity_fulfilled_total))}/${esc(qty(shipment.quantity_planned_total))} fulfilled</span>${issueCount ? `<span class="deployment-shipment-badge deployment-shipment-badge--issue">${issueCount} issue${issueCount === 1 ? '' : 's'}</span>` : ''}</span>
      </button>`;
        }).join('');
    }

    async function handleShipmentClick(event) {
        const card = event.target.closest('[data-shipment-id]');
        if (!card) {
            return;
        }
        elements.detail.innerHTML = '<p class="text-muted">Loading shipment…</p>';
        try {
            const form = new FormData(); form.set('action', 'get'); form.set('shipment_id', card.dataset.shipmentId);
            const data = await api('get', form, 'GET');
            state.shipment = data.shipment || null;
            renderShipmentList(); renderShipmentDetail();
            elements.layout.classList.add('has-detail');
            elements.detail.focus({ preventScroll: true });
        } catch (error) {
            elements.detail.innerHTML = `<p class="text-danger">${esc(error.message)}</p>`;
        }
    }

    function renderShipmentDetail() {
        const shipment = state.shipment;
        if (!shipment) {
            elements.detail.innerHTML = '<p class="text-muted">Choose a shipment to view its details.</p>'; return;
        }
        const issues = (shipment.items || []).filter((item) => ['partial', 'unavailable'].includes(item.line_status));
        const events = (shipment.events || []).map((event) => `<li><strong>${esc(label(event.event_type))}</strong><div class="deployment-shipment-activity__meta">${esc(event.actor_display || 'System')} · ${esc(event.created_at || '')}</div><div>${esc(event.message || '')}</div></li>`).join('') || '<li class="text-muted">No activity recorded yet.</li>';
        const items = (shipment.items || []).map((item) => `<li class="deployment-shipment-line"><strong>${esc(item.product_name || item.snipe_model_name || 'Kit item')}</strong><div class="deployment-shipment-line__counts">Planned ${qty(item.quantity_planned)} · Fulfilled ${qty(item.quantity_fulfilled)} · Received ${qty(item.quantity_received)}</div>${item.line_notes ? `<div class="deployment-shipment-line__issue">${esc(item.line_notes)}</div>` : ''}</li>`).join('') || '<li class="text-muted">No items were recorded.</li>';
        const actionButtons = `${config.canApproveShipment && shipment.status === 'pending' && shipment.approval_status === 'pending' ? '<button type="button" class="btn-primary" data-shipment-action="approve">Approve</button><button type="button" class="btn-secondary" data-shipment-action="decline">Decline</button>' : ''}${config.canReceiveShipment && ['dispatched', 'partially_received'].includes(shipment.status) ? '<button type="button" class="btn-primary" data-shipment-action="receive">Confirm receipt</button>' : ''}`;
        elements.detail.innerHTML = `<div class="deployment-shipment-detail__header"><button type="button" class="btn-link deployment-shipment-detail__back" data-shipment-action="back">Back to shipments</button><p class="deployment-shipment-detail__eyebrow">${esc(label(shipment.status))}</p><h2 class="deployment-shipment-detail__heading">${esc(shipment.kit_name_snapshot || shipment.kit_template_name || 'Shipment')}</h2><div class="deployment-shipment-detail__actions">${actionButtons}</div></div>
      <dl class="deployment-shipment-detail__summary"><div><dt>Destination</dt><dd>${esc(shipment.destination_name || shipment.site_name || 'Not set')}<br>${esc(shipment.destination_address || 'Address not set')}</dd></div><div><dt>Needed by</dt><dd>${esc(shipment.needed_by || 'Not set')}</dd></div><div><dt>Request</dt><dd>${esc(label(shipment.approval_status || 'not_required'))} · ${esc(shipment.requested_by_display || 'Unknown')}</dd></div><div><dt>Tracking</dt><dd>${esc([shipment.carrier, shipment.tracking_number].filter(Boolean).join(' · ') || 'Not available')}</dd></div></dl>
      ${shipment.approval_reason ? `<section class="deployment-shipment-detail__section"><h3>Approval note</h3><p>${esc(shipment.approval_reason)}</p></section>` : ''}
      <section class="deployment-shipment-detail__section"><h3>Cargo Hold</h3><ul class="deployment-shipment-lines">${items}</ul></section>
      ${issues.length ? `<section class="deployment-shipment-detail__section"><h3>Issues</h3><p>${issues.length} item${issues.length === 1 ? '' : 's'} need attention. Review the item notes above.</p></section>` : ''}
      <section class="deployment-shipment-detail__section"><h3>Captain's Log</h3><ul class="deployment-shipment-activity">${events}</ul></section>`;
    }

    function handleDetailClick(event) {
        const action = event.target.closest('[data-shipment-action]')?.dataset.shipmentAction;
        if (!action) {
            return;
        }
        if (action === 'back') {
            state.shipment = null; elements.layout.classList.remove('has-detail'); renderShipmentList(); elements.list.focus?.(); return;
        }
        if (action === 'approve') {
            openReasonDialog('Approve shipment', 'Optional approval note', false, 'approve');
        }
        if (action === 'decline') {
            openReasonDialog('Decline shipment', 'Decline reason', true, 'decline');
        }
        if (action === 'receive') {
            openReceiptDialog();
        }
    }

    function uuid() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }
        const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        return [...bytes].map((byte, index) => (index === 4 || index === 6 || index === 8 || index === 10 ? '-' : '') + byte.toString(16).padStart(2, '0')).join('');
    }

    function draftStorageKey() {
        return state.deployment?.id ? `quacktrack:deployment-shipment-request:${state.deployment.id}` : '';
    }

    function loadRequestDraft() {
        const key = draftStorageKey();
        if (!key) {
            return null;
        }
        try {
            const draft = JSON.parse(window.sessionStorage.getItem(key) || 'null');
            if (!draft || Number(draft.deploymentId) !== Number(state.deployment.id) || !draft.requestKey) {
                return null;
            }
            if (Date.now() - Number(draft.savedAt || 0) > 24 * 60 * 60 * 1000) {
                window.sessionStorage.removeItem(key);
                return null;
            }
            return draft;
        } catch (_error) {
            window.sessionStorage.removeItem(key);
            return null;
        }
    }

    function saveRequestDraft() {
        const key = draftStorageKey();
        if (!key || !state.request) {
            return;
        }
        const request = state.request;
        window.sessionStorage.setItem(key, JSON.stringify({
            deploymentId: state.deployment.id,
            requestKey: request.key,
            templateId: request.templateId,
            quantities: request.quantities,
            destinationName: request.destinationName,
            destinationAddress: request.destinationAddress,
            neededBy: request.neededBy,
            notes: request.notes,
            step: request.step,
            savedAt: Date.now()
        }));
    }

    function clearRequestDraft() {
        const key = draftStorageKey();
        if (key) {
            window.sessionStorage.removeItem(key);
        }
    }

    async function openRequest() {
        if (!state.deployment?.id) {
            notification('Select a campaign before requesting a shipment.'); return;
        }
        try {
            const form = new FormData(); form.set('action', 'templates');
            const data = await api('templates', form, 'GET');
            const templates = Array.isArray(data.templates) ? data.templates : [];
            if (!templates.length) {
                notification('No active kit templates are available.'); return;
            }
            const draft = loadRequestDraft();
            const draftTemplate = draft && templates.find((template) => String(template.id) === String(draft.templateId));
            const selectedTemplate = draftTemplate || templates[0];
            state.request = {
                key: draft?.requestKey || uuid(),
                templates,
                templateId: String(selectedTemplate.id),
                quantities: draftTemplate && draft.quantities && typeof draft.quantities === 'object' ? draft.quantities : {},
                destinationName: draft?.destinationName || state.deployment.site_name || state.deployment.store_name || '',
                destinationAddress: draft?.destinationAddress || deploymentAddress(state.deployment),
                neededBy: draft?.neededBy || '',
                notes: draft?.notes || '',
                step: Math.max(1, Math.min(4, qty(draft?.step) || 1))
            };
            if (!draftTemplate) {
                selectedTemplate.items.forEach((item) => {
                    state.request.quantities[item.id] = Math.min(1000, qty(item.quantity));
                });
            }
            saveRequestDraft();
            openRequestDialog();
        } catch (error) {
            notification(error.message);
        }
    }

    function deploymentAddress(deployment) {
        return [deployment.address_line1, deployment.address_line2, [deployment.city, deployment.state].filter(Boolean).join(' '), deployment.postal_code].filter(Boolean).join(', ');
    }

    function activeTemplate() {
        return state.request.templates.find((template) => String(template.id) === String(state.request.templateId));
    }

    function openRequestDialog() {
        const request = state.request; const template = activeTemplate();
        const kitOptions = request.templates.map((kit) => `<option value="${esc(kit.id)}"${String(kit.id) === String(request.templateId) ? ' selected' : ''}>${esc(kit.name)} (${qty(kit.item_count)} items)</option>`).join('');
        const itemRows = (template.items || []).map((item) => `<li class="deployment-shipment-dialog__item"><label for="shipmentQty${esc(item.id)}">${esc(item.product_name || item.snipe_model_name || 'Kit item')}</label><span>${esc(item.notes || item.item_type || '')}</span><input id="shipmentQty${esc(item.id)}" class="deployment-shipment-dialog__quantity" data-quantity-id="${esc(item.id)}" type="number" min="0" max="1000" value="${esc(request.quantities[item.id] ?? qty(item.quantity))}"></li>`).join('');
        let body = '';
        if (request.step === 1) {
            body = `<div class="form-field"><label for="shipmentKit">Active kit</label><select id="shipmentKit">${kitOptions}</select></div>`;
        }
        if (request.step === 2) {
            body = `<p>Adjust requested quantities. Set an item to 0 to exclude it.</p><ul class="deployment-shipment-dialog__items">${itemRows}</ul>`;
        }
        if (request.step === 3) {
            body = `<div class="form-field"><label for="shipmentDestinationName">Destination name</label><input id="shipmentDestinationName" maxlength="190" required value="${esc(request.destinationName)}"></div><div class="form-field"><label for="shipmentDestinationAddress">Delivery address</label><textarea id="shipmentDestinationAddress" maxlength="2000">${esc(request.destinationAddress)}</textarea></div><div class="form-field"><label for="shipmentNeededBy">Needed by</label><input id="shipmentNeededBy" type="date" value="${esc(request.neededBy)}"></div><div class="form-field"><label for="shipmentNotes">Request notes</label><textarea id="shipmentNotes" maxlength="2000">${esc(request.notes)}</textarea></div>`;
        }
        if (request.step === 4) {
            body = `<dl class="deployment-shipment-detail__summary"><div><dt>Kit</dt><dd>${esc(template.name)}</dd></div><div><dt>Destination</dt><dd>${esc(request.destinationName)}<br>${esc(request.destinationAddress || 'Address not set')}</dd></div><div><dt>Needed by</dt><dd>${esc(request.neededBy || 'Not set')}</dd></div><div><dt>Items requested</dt><dd>${Object.values(request.quantities).filter((value) => qty(value) > 0).length}</dd></div></dl><p>${esc(request.notes || 'No request notes.')}</p>`;
        }
        const primary = request.step === 4 ? 'Submit request' : 'Continue';
        const dialog = openDialog('Request shipment', `<p class="deployment-shipment-dialog__step">Step ${request.step} of 4</p>${body}`, `<button type="button" class="btn-link" data-dialog-action="cancel">Cancel</button>${request.step > 1 ? '<button type="button" class="btn-secondary" data-dialog-action="back">Back</button>' : ''}<button type="button" class="btn-primary" data-dialog-action="next">${primary}</button>`, handleRequestDialogAction);
        dialog.addEventListener('input', () => captureRequestStep(dialog));
        dialog.addEventListener('change', () => captureRequestStep(dialog));
    }

    function captureRequestStep(dialog) {
        const request = state.request;
        if (!request) {
            return;
        }
        if (request.step === 1) {
            const selectedId = dialog.querySelector('#shipmentKit')?.value;
            if (selectedId && selectedId !== request.templateId) {
                request.templateId = selectedId;
                request.quantities = {};
                (activeTemplate()?.items || []).forEach((item) => {
                    request.quantities[item.id] = Math.min(1000, qty(item.quantity));
                });
            }
        } else if (request.step === 2) {
            dialog.querySelectorAll('[data-quantity-id]').forEach((input) => {
                request.quantities[input.dataset.quantityId] = Math.min(1000, Math.max(0, qty(input.value)));
            });
        } else if (request.step === 3) {
            request.destinationName = dialog.querySelector('#shipmentDestinationName')?.value.trim() || '';
            request.destinationAddress = dialog.querySelector('#shipmentDestinationAddress')?.value.trim() || '';
            request.neededBy = dialog.querySelector('#shipmentNeededBy')?.value || '';
            request.notes = dialog.querySelector('#shipmentNotes')?.value.trim() || '';
        }
        saveRequestDraft();
    }

    function handleRequestDialogAction(action, dialog) {
        if (action === 'cancel') {
            return closeDialog(dialog);
        }
        const request = state.request;
        if (action === 'back') {
            request.step -= 1; saveRequestDraft(); state.keepDialogReturnFocus = true; closeDialog(dialog, false); openRequestDialog(); return;
        }
        if (request.step === 1) {
            const previous = activeTemplate(); request.templateId = dialog.querySelector('#shipmentKit').value;
            const next = activeTemplate(); if (previous.id !== next.id) {
                request.quantities = {}; next.items.forEach((item) => {
                    request.quantities[item.id] = Math.min(1000, qty(item.quantity));
                });
            }
        } else if (request.step === 2) {
            dialog.querySelectorAll('[data-quantity-id]').forEach((input) => {
                request.quantities[input.dataset.quantityId] = Math.min(1000, Math.max(0, qty(input.value)));
            });
            if (!Object.values(request.quantities).some((value) => qty(value) > 0)) {
                notification('Include at least one item.'); return;
            }
        } else if (request.step === 3) {
            request.destinationName = dialog.querySelector('#shipmentDestinationName').value.trim(); request.destinationAddress = dialog.querySelector('#shipmentDestinationAddress').value.trim(); request.neededBy = dialog.querySelector('#shipmentNeededBy').value; request.notes = dialog.querySelector('#shipmentNotes').value.trim();
            if (!request.destinationName) {
                notification('Destination name is required.'); return;
            }
        } else {
            submitRequest(dialog); return;
        }
        request.step += 1; saveRequestDraft(); state.keepDialogReturnFocus = true; closeDialog(dialog, false); openRequestDialog();
    }

    async function submitRequest(dialog) {
        const request = state.request; const form = new FormData();
        form.set('deployment_id', state.deployment.id); form.set('kit_template_id', request.templateId); form.set('request_key', request.key); form.set('destination_name', request.destinationName); form.set('destination_address', request.destinationAddress); form.set('needed_by', request.neededBy); form.set('request_notes', request.notes); form.set('quantities', JSON.stringify(request.quantities));
        const submit = dialog.querySelector('[data-dialog-action="next"]'); submit.disabled = true;
        try {
            const data = await api('request', form); state.shipment = data.shipment || null; clearRequestDraft(); state.request = null; closeDialog(dialog); notification('Shipment request submitted.', 'success'); await loadShipments(); renderShipmentDetail(); elements.layout.classList.add('has-detail');
        } catch (error) {
            saveRequestDraft(); submit.disabled = false; notification(error.message);
        }
    }

    function openReasonDialog(title, prompt, required, actionName) {
        openDialog(title, `<div class="form-field"><label for="shipmentActionReason">${esc(prompt)}${required ? ' *' : ''}</label><textarea id="shipmentActionReason" maxlength="500"${required ? ' required' : ''}></textarea></div>`, '<button type="button" class="btn-link" data-dialog-action="cancel">Cancel</button><button type="button" class="btn-primary" data-dialog-action="submit">Confirm</button>', async (action, dialog) => {
            if (action === 'cancel') {
                return closeDialog(dialog);
            }
            const reason = dialog.querySelector('#shipmentActionReason').value.trim(); if (required && !reason) {
                notification('A decline reason is required.'); return;
            }
            const form = new FormData(); form.set('shipment_id', state.shipment.id); form.set('reason', reason);
            try {
                const data = await api(actionName, form); state.shipment = data.shipment || null; closeDialog(dialog); notification(`Shipment ${actionName}d.`, 'success'); await loadShipments(); renderShipmentDetail();
            } catch (error) {
                notification(error.message);
            }
        });
    }

    function openReceiptDialog() {
        const rows = (state.shipment.items || []).map((item) => `<li class="deployment-shipment-dialog__item"><label for="receiptQty${esc(item.id)}">${esc(item.product_name || item.snipe_model_name || 'Kit item')}</label><span>Received ${qty(item.quantity_received)} of ${qty(item.quantity_fulfilled)}</span><input id="receiptQty${esc(item.id)}" data-receipt-id="${esc(item.id)}" class="deployment-shipment-dialog__quantity" type="number" min="${qty(item.quantity_received)}" max="${qty(item.quantity_fulfilled)}" value="${qty(item.quantity_fulfilled)}"></li>`).join('');
        openDialog('Confirm receipt', `<p>Enter what arrived. Lower quantities record a partial receipt.</p><ul class="deployment-shipment-dialog__items">${rows}</ul><div class="form-field"><label for="receiptNotes">Receipt notes</label><textarea id="receiptNotes" maxlength="500"></textarea></div>`, '<button type="button" class="btn-link" data-dialog-action="cancel">Cancel</button><button type="button" class="btn-primary" data-dialog-action="submit">Save receipt</button>', async (action, dialog) => {
            if (action === 'cancel') {
                return closeDialog(dialog);
            }
            const quantities = {}; let valid = true;
            dialog.querySelectorAll('[data-receipt-id]').forEach((input) => {
                const minimum = qty(input.min); const maximum = qty(input.max); const value = qty(input.value); if (value < minimum || value > maximum) {
                    valid = false;
                } quantities[input.dataset.receiptId] = value;
            });
            if (!valid) {
                notification('Received quantities cannot decrease or exceed fulfilled quantities.'); return;
            }
            const form = new FormData(); form.set('shipment_id', state.shipment.id); form.set('received_quantities', JSON.stringify(quantities)); form.set('notes', dialog.querySelector('#receiptNotes').value.trim());
            try {
                const data = await api('receive', form); state.shipment = data.shipment || null; closeDialog(dialog); notification('Shipment receipt recorded.', 'success'); await loadShipments(); renderShipmentDetail();
            } catch (error) {
                notification(error.message);
            }
        });
    }

    function openDialog(title, body, footer, handler) {
        if (!state.keepDialogReturnFocus) {
            state.returnFocus = document.activeElement;
        }
        state.keepDialogReturnFocus = false;
        const layer = document.createElement('div'); layer.className = 'deployment-shipment-dialog-layer'; layer.innerHTML = `<section class="deployment-shipment-dialog" role="dialog" aria-modal="true" aria-labelledby="deploymentShipmentDialogTitle"><header class="deployment-shipment-dialog__header"><div><p class="deployment-shipment-dialog__eyebrow">Deployment shipment</p><h2 id="deploymentShipmentDialogTitle" class="deployment-shipment-dialog__title">${esc(title)}</h2></div><button type="button" class="close-button" data-dialog-action="cancel" aria-label="Close dialog">&times;</button></header><div>${body}</div><footer class="deployment-shipment-dialog__footer">${footer}</footer></section>`;
        document.body.appendChild(layer);
        document.body.classList.add('deployment-modal-open');
        const dialog = layer.querySelector('.deployment-shipment-dialog');
        const focusable = () => [...dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled])')];
        layer.addEventListener('click', (event) => {
            if (event.target === layer) {
                closeDialog(dialog);
            } const action = event.target.closest('[data-dialog-action]')?.dataset.dialogAction; if (action) {
                handler(action, dialog);
            }
        });
        layer.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault(); closeDialog(dialog);
            } if (event.key === 'Tab') {
                const nodes = focusable(); if (!nodes.length) {
                    return;
                } const first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first.focus();
                }
            }
        });
        (focusable()[0] || dialog).focus();
        return dialog;
    }

    function closeDialog(dialog, restoreFocus = true) {
        const layer = dialog.closest('.deployment-shipment-dialog-layer');
        layer?.remove();
        if (!document.querySelector('.deployment-shipment-dialog-layer, .deployment-modal-shell.show')) {
            document.body.classList.remove('deployment-modal-open');
        }
        if (restoreFocus) {
            state.returnFocus?.focus?.();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
