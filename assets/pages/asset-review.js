/*
 * asset-review.js — Tracked Asset Review queue.
 * One tile per product; clicking it opens a pop-out holding that product's
 * pending scans (the real cards are moved in and back, so their handlers keep
 * working). Each card can create the asset in Snipe-IT, link an existing one, or
 * be dismissed with a reason.
 */
(function() {
    'use strict';

    const modal = document.getElementById('assetReviewModal');
    const bodyEl = document.getElementById('assetReviewModalBody');
    const grid = document.getElementById('assetGrid');
    const groupsEl = document.getElementById('assetReviewGroups');
    if (!modal || !grid || !groupsEl) {
        return;
    }

    const titleEl = document.getElementById('assetReviewModalTitle');
    const countEl = document.getElementById('assetReviewModalCount');
    const pendingEl = document.getElementById('assetPendingCount');
    const productsEl = document.getElementById('assetProductCount');
    const emptyEl = document.getElementById('assetReviewEmpty');
    const dialog = document.getElementById('assetDismissDialog');
    const noteField = document.getElementById('assetDismissNote');
    let openId = null;
    let opener = null;
    let dismissHandler = null;

    function groupFor(productId) {
        return groupsEl.querySelector('.asset-review-group[data-product-id="' + productId + '"]');
    }

    function tileFor(productId) {
        return grid.querySelector('.asset-tile[data-product-id="' + productId + '"]');
    }

    // Cards live in the pop-out while it is open for that product, otherwise in their hidden group.
    function cardsFor(productId) {
        const holder = openId === productId ? bodyEl : groupFor(productId);
        return holder ? holder.querySelectorAll('.asset-review-card') : [];
    }

    function openProduct(tile) {
        const productId = tile.dataset.productId;
        const group = groupFor(productId);
        if (!group) {
            return;
        }
        openId = productId;
        opener = tile;
        titleEl.textContent = tile.dataset.name;
        while (group.firstChild) {
            bodyEl.appendChild(group.firstChild);
        }
        updateCounts(productId);
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const first = bodyEl.querySelector('input[name="asset_tag"]');
        if (first) {
            first.focus();
        }
    }

    function closeModal() {
        if (openId === null) {
            return;
        }
        const group = groupFor(openId);
        if (group) {
            while (bodyEl.firstChild) {
                group.appendChild(bodyEl.firstChild);
            }
        }
        modal.hidden = true;
        document.body.style.overflow = '';
        if (opener && document.contains(opener)) {
            opener.focus();
        }
        openId = null;
        opener = null;
    }

    function updateCounts(productId) {
        const left = cardsFor(productId).length;
        const tile = tileFor(productId);
        if (tile) {
            tile.querySelector('[data-count]').textContent = left + ' pending';
        }
        countEl.textContent = left + ' pending';
    }

    function refreshTotals() {
        let total = 0;
        groupsEl.querySelectorAll('.asset-review-group').forEach((group) => {
            total += cardsFor(group.dataset.productId).length;
        });
        pendingEl.textContent = total;
        const tiles = grid.querySelectorAll('.asset-tile').length;
        productsEl.textContent = tiles;
        emptyEl.hidden = tiles > 0;
    }

    function onResolved(card) {
        const productId = card.closest('.asset-review-group') ? card.closest('.asset-review-group').dataset.productId : openId;
        card.remove();
        if (productId === null) {
            return;
        }
        if (cardsFor(productId).length === 0) {
            if (openId === productId) {
                closeModal();
            }
            const tile = tileFor(productId);
            if (tile) {
                tile.remove();
            }
            const group = groupFor(productId);
            if (group) {
                group.remove();
            }
        } else {
            updateCounts(productId);
        }
        refreshTotals();
    }

    grid.addEventListener('click', (event) => {
        const tile = event.target.closest('.asset-tile');
        if (tile) {
            openProduct(tile);
        }
    });
    document.getElementById('assetReviewModalClose').addEventListener('click', closeModal);
    document.getElementById('assetReviewModalDone').addEventListener('click', closeModal);
    modal.querySelector('.orders-modal-backdrop').addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden && !dialog.open) {
            closeModal();
        }
    });

    dialog.addEventListener('close', () => {
        const handler = dismissHandler;
        dismissHandler = null;
        if (handler && dialog.returnValue === 'confirm') {
            handler(noteField.value.trim());
        }
    });

    // Tell the user how it went (including a Snipe-IT assignment that needs a manual step).
    function notify(payload) {
        if (!window.QuackNotify) {
            return;
        }
        window.QuackNotify.success(payload.message || 'Review updated.');
        if (payload.snipe_sync && !payload.snipe_sync.synced && payload.snipe_sync.message) {
            window.QuackNotify.warning(payload.snipe_sync.message);
        }
    }

    function setMessage(el, text, state) {
        el.textContent = text;
        el.className = 'asset-message asset-review-message' + (state ? ' asset-message--' + state : '');
    }

    document.querySelectorAll('.asset-review-card').forEach((card) => {
        const form = card.querySelector('.asset-review-form');
        const message = card.querySelector('.asset-review-message');

        async function submitReview(action, extra = {}) {
            const data = new FormData(form);
            data.set('action', action);
            Object.entries(extra).forEach(([key, value]) => data.set(key, value));
            setMessage(message, 'Working…');
            try {
                const response = await secureFetch('/api/snipe-asset-review.php', { method: 'POST', body: data });
                const payload = await response.json();
                if (!response.ok || payload.success === false) {
                    throw new Error(payload.error || 'Request failed.');
                }
                notify(payload);
                onResolved(card);
            } catch (error) {
                setMessage(message, error.message || 'Unable to update review.', 'error');
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submitReview('create_asset');
        });

        card.querySelector('.review-link').addEventListener('click', () => {
            const identifier = form.elements.existing_identifier.value.trim();
            if (!identifier) {
                form.elements.existing_identifier.focus();
                setMessage(message, 'Enter the existing Snipe-IT serial or asset tag first.', 'error');
                return;
            }
            submitReview('link_existing', { identifier });
        });

        card.querySelector('.review-dismiss').addEventListener('click', () => {
            noteField.value = 'Not an inventory asset';
            dismissHandler = (note) => submitReview('dismiss', { review_note: note });
            dialog.showModal();
        });
    });
}());
