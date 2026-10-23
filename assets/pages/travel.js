/* global handleAPIResponse, createSecureFormData */
(function() {
    'use strict';

    const page = document.querySelector('.travel-page');
    if (!page) {
        return;
    }

    const apiUrl = '/api/travel.php';
    const canManageRegions = page.dataset.canManageRegions === '1';
    const elements = {
        message: document.getElementById('travelMessage'),
        refreshBtn: document.getElementById('travelRefreshBtn'),
        searchInput: document.getElementById('travelSearchInput'),
        regionFilter: document.getElementById('travelRegionFilter'),
        typeFilter: document.getElementById('travelTypeFilter'),
        tagFilter: document.getElementById('travelTagFilter'),
        visitedFilter: document.getElementById('travelVisitedFilter'),
        regionNotes: document.getElementById('travelRegionNotes'),
        saveRegionNotes: document.getElementById('travelSaveRegionNotes'),
        placeForm: document.getElementById('travelPlaceForm'),
        placeFormTitle: document.getElementById('travelPlaceFormTitle'),
        cancelEdit: document.getElementById('travelCancelEdit'),
        placeId: document.getElementById('travelPlaceId'),
        placeName: document.getElementById('travelPlaceName'),
        placeRegion: document.getElementById('travelPlaceRegion'),
        placeType: document.getElementById('travelPlaceType'),
        safetyGroup: document.getElementById('travelSafetyGroup'),
        safetyLevel: document.getElementById('travelSafetyLevel'),
        visitedCheckbox: document.getElementById('travelVisited'),
        placeDescription: document.getElementById('travelPlaceDescription'),
        placeTags: document.getElementById('travelPlaceTags'),
        placePhoto: document.getElementById('travelPlacePhoto'),
        photoPreview: document.getElementById('travelPhotoPreview'),
        removePhotoWrap: document.getElementById('travelRemovePhotoWrap'),
        removePhoto: document.getElementById('travelRemovePhoto'),
        placeSubmit: document.getElementById('travelPlaceSubmit'),
        resultsMeta: document.getElementById('travelResultsMeta'),
        places: document.getElementById('travelPlaces'),
        emptyState: document.getElementById('travelEmptyState')
    };

    const state = {
        regions: [],
        places: [],
        tagPool: [],
        comments: {},
        filters: {
            regionId: 0,
            type: 'all',
            tag: '',
            query: '',
            visited: 'all'
        },
        searchTimer: null
    };

    function showMessage(message, type = 'success') {
        if (!elements.message) {
            return;
        }
        elements.message.textContent = message;
        elements.message.classList.toggle('is-success', type === 'success');
        elements.message.classList.toggle('is-error', type === 'error');
    }

    function clearMessage() {
        if (elements.message) {
            elements.message.textContent = '';
            elements.message.classList.remove('is-success', 'is-error');
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    async function apiGet(params) {
        const url = `${apiUrl}?${params.toString()}`;
        const response = await secureFetch(url, { method: 'GET' });
        const payload = await handleAPIResponse(response);
        return payload.data;
    }

    async function apiPost(formData) {
        const response = await secureFetch(apiUrl, {
            method: 'POST',
            body: formData
        });
        const payload = await handleAPIResponse(response);
        return payload.data;
    }

    function setLoading(isLoading) {
        if (elements.placeSubmit) {
            elements.placeSubmit.disabled = isLoading;
            elements.placeSubmit.textContent = isLoading ? 'Saving...' : 'Save Place';
        }
    }

    function updateSafetyVisibility() {
        const isAvoid = elements.placeType.value === 'avoid';
        elements.safetyGroup.style.display = isAvoid ? 'block' : 'none';
        if (!isAvoid) {
            elements.safetyLevel.value = '';
        }
    }

    function resetPlaceForm() {
        elements.placeForm.reset();
        elements.placeId.value = '';
        elements.placeFormTitle.textContent = 'Add a place';
        elements.cancelEdit.hidden = true;
        elements.photoPreview.hidden = true;
        elements.photoPreview.innerHTML = '';
        elements.removePhotoWrap.hidden = true;
        elements.removePhoto.checked = false;
        if (elements.placePhoto) {
            elements.placePhoto.value = '';
        }
        const regionId = state.filters.regionId;
        if (regionId > 0) {
            elements.placeRegion.value = String(regionId);
        }
        updateSafetyVisibility();
    }

    function populatePlaceForm(place) {
        elements.placeId.value = place.id;
        elements.placeName.value = place.name;
        elements.placeRegion.value = String(place.region_id);
        elements.placeType.value = place.type;
        elements.safetyLevel.value = place.safety_level || '';
        elements.visitedCheckbox.checked = !!place.visited;
        elements.placeDescription.value = place.description || '';
        elements.placeTags.value = (place.tags || []).join(', ');
        elements.placeFormTitle.textContent = 'Edit place';
        elements.cancelEdit.hidden = false;
        updateSafetyVisibility();

        if (place.photo_path) {
            const imgSrc = window.imageUrl ? window.imageUrl(place.photo_path) : place.photo_path;
            elements.photoPreview.innerHTML = `<img src="${escapeHtml(imgSrc)}" alt="${escapeHtml(place.name)}">`;
            elements.photoPreview.hidden = false;
            elements.removePhotoWrap.hidden = false;
        } else {
            elements.photoPreview.hidden = true;
            elements.photoPreview.innerHTML = '';
            elements.removePhotoWrap.hidden = true;
            elements.removePhoto.checked = false;
        }

        if (elements.placePhoto) {
            elements.placePhoto.value = '';
        }
    }

    function renderRegionOptions() {
        const filterValue = elements.regionFilter.value || '0';
        const placeValue = elements.placeRegion.value || '';

        const regionOptions = state.regions
            .map(region => `<option value="${region.id}">${escapeHtml(region.name)}</option>`)
            .join('');

        elements.regionFilter.innerHTML = `<option value="0">All regions</option>${regionOptions}`;
        elements.placeRegion.innerHTML = `<option value="">Select region</option>${regionOptions}`;

        if (filterValue) {
            elements.regionFilter.value = filterValue;
        }
        if (placeValue) {
            elements.placeRegion.value = placeValue;
        }
    }

    function renderTagOptions() {
        const current = elements.tagFilter.value || '';
        const options = state.tagPool
            .map(tag => `<option value="${escapeHtml(tag)}">${escapeHtml(tag)}</option>`)
            .join('');
        elements.tagFilter.innerHTML = `<option value="">All tags</option>${options}`;
        elements.tagFilter.value = current;
    }

    function updateRegionNotes() {
        const regionId = parseInt(elements.regionFilter.value || '0', 10);
        const region = state.regions.find(item => item.id === regionId);
        if (!region) {
            elements.regionNotes.value = '';
            elements.regionNotes.disabled = true;
            elements.saveRegionNotes.disabled = true;
            return;
        }
        elements.regionNotes.disabled = false;
        elements.regionNotes.readOnly = !canManageRegions;
        elements.saveRegionNotes.disabled = !canManageRegions;
        elements.regionNotes.value = region.notes || '';
    }

    function lockRegionControls() {
        if (canManageRegions) {
            return;
        }
        elements.regionNotes.readOnly = true;
        elements.saveRegionNotes.disabled = true;
    }

    function renderPlaces() {
        if (!elements.places) {
            return;
        }

        if (!state.places.length) {
            elements.places.innerHTML = '';
            elements.emptyState.hidden = false;
            elements.resultsMeta.textContent = '0 places';
            return;
        }

        elements.emptyState.hidden = true;
        elements.resultsMeta.textContent = `${state.places.length} place${state.places.length === 1 ? '' : 's'}`;

        elements.places.innerHTML = state.places.map(place => buildPlaceCard(place)).join('');
    }

    function buildPlaceCard(place) {
        const badgeClass = place.type === 'avoid' ? 'avoid' : 'recommended';
        const ratingText = place.rating_count > 0
            ? `${place.avg_rating.toFixed(1)} / 5 (${place.rating_count} rating${place.rating_count === 1 ? '' : 's'})`
            : 'No ratings yet';
        const visitedBadge = place.visited ? '<span class="travel-badge visited">Visited</span>' : '';
        const safety = place.type === 'avoid' && place.safety_level
            ? `<span class="travel-badge avoid">Safety: ${escapeHtml(place.safety_level)}</span>`
            : '';
        const tags = (place.tags || []).map(tag => `<span class="travel-tag">${escapeHtml(tag)}</span>`).join('');
        const photo = place.photo_path
            ? `<img src="${escapeHtml(window.imageUrl ? window.imageUrl(place.photo_path) : place.photo_path)}" alt="${escapeHtml(place.name)}">`
            : '<div class="travel-photo-fallback">No photo</div>';
        const actions = [
            place.can_edit ? `<button class="btn btn-secondary btn-sm" type="button" data-action="edit-place" data-place-id="${place.id}">Edit</button>` : '',
            place.can_delete ? `<button class="btn btn-danger btn-sm" type="button" data-action="delete-place" data-place-id="${place.id}">Delete</button>` : ''
        ].join('');

        return `
      <article class="travel-card" data-place-id="${place.id}">
        <div class="travel-card-main">
          <div class="travel-card-photo">${photo}</div>
          <div>
            <div class="travel-card-header">
              <h3>${escapeHtml(place.name)}</h3>
              <span class="travel-badge ${badgeClass}">${escapeHtml(place.type)}</span>
              ${visitedBadge}
              ${safety}
            </div>
            <div class="travel-meta">
              <div>Region: ${escapeHtml(place.region_name)}</div>
              <div>Added by: ${escapeHtml(place.added_by_name || 'Unknown')}</div>
              <div class="travel-rating-pill">Rating: ${escapeHtml(ratingText)}</div>
            </div>
            ${place.description ? `<p>${escapeHtml(place.description)}</p>` : ''}
            ${tags ? `<div class="travel-tags">${tags}</div>` : ''}
            <div class="travel-card-actions">
              <button class="btn btn-secondary btn-sm" type="button" data-action="toggle-comments" data-place-id="${place.id}">
                Comments (${place.comment_count})
              </button>
              ${actions}
            </div>
          </div>
        </div>
        <div class="travel-comments" data-place-id="${place.id}" hidden>
          <div class="travel-comments-header">
            <strong>Comments</strong>
            <span class="travel-region-notes-preview">${place.region_notes ? `Region notes: ${escapeHtml(place.region_notes)}` : ''}</span>
          </div>
          <div class="travel-comment-list" id="travelCommentsList-${place.id}">
            <div class="travel-empty">Loading comments...</div>
          </div>
          <form class="travel-comment-form no-loading no-validation" data-place-id="${place.id}" method="post">
            <label>
              Rating (optional)
              <select name="rating" class="form-control">
                <option value="">No rating</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
              </select>
            </label>
            <label>
              Comment
              <textarea name="comment_text" class="form-control travel-comment-text" rows="3" maxlength="1000" required></textarea>
            </label>
            <div class="travel-character-count" data-max="1000">1000 characters remaining</div>
            <button class="btn btn-primary" type="submit">Add Comment</button>
          </form>
        </div>
      </article>
    `;
    }

    function renderComments(placeId) {
        const list = document.getElementById(`travelCommentsList-${placeId}`);
        if (!list) {
            return;
        }
        const comments = state.comments[placeId] || [];
        if (!comments.length) {
            list.innerHTML = '<div class="travel-empty">No comments yet.</div>';
            return;
        }

        list.innerHTML = comments.map(comment => {
            const rating = comment.rating ? `Rating: ${comment.rating} / 5` : 'No rating';
            const actions = [
                comment.can_edit ? `<button class="btn btn-secondary btn-sm" type="button" data-action="edit-comment" data-comment-id="${comment.id}" data-place-id="${placeId}">Edit</button>` : '',
                comment.can_delete ? `<button class="btn btn-danger btn-sm" type="button" data-action="delete-comment" data-comment-id="${comment.id}" data-place-id="${placeId}">Delete</button>` : ''
            ].join('');

            return `
        <div class="travel-comment" data-comment-id="${comment.id}" data-place-id="${placeId}">
          <div class="travel-comment-meta">
            <span>${escapeHtml(comment.user_name || 'Unknown')}</span>
            <span>${escapeHtml(comment.created_at || '')}</span>
          </div>
          <div class="travel-rating-pill">${escapeHtml(rating)}</div>
          <div class="travel-comment-body">${escapeHtml(comment.comment_text)}</div>
          ${actions ? `<div class="travel-comment-actions">${actions}</div>` : ''}
          <form class="travel-comment-edit no-loading no-validation" data-comment-id="${comment.id}" data-place-id="${placeId}" method="post" hidden>
            <label>
              Rating (optional)
              <select name="rating" class="form-control">
                <option value="">No rating</option>
                <option value="1" ${comment.rating === 1 ? 'selected' : ''}>1</option>
                <option value="2" ${comment.rating === 2 ? 'selected' : ''}>2</option>
                <option value="3" ${comment.rating === 3 ? 'selected' : ''}>3</option>
                <option value="4" ${comment.rating === 4 ? 'selected' : ''}>4</option>
                <option value="5" ${comment.rating === 5 ? 'selected' : ''}>5</option>
              </select>
            </label>
            <label>
              Comment
              <textarea name="comment_text" class="form-control travel-comment-text" rows="3" maxlength="1000" required>${escapeHtml(comment.comment_text)}</textarea>
            </label>
            <div class="travel-character-count" data-max="1000">1000 characters remaining</div>
            <div class="travel-comment-actions">
              <button class="btn btn-primary btn-sm" type="submit">Save</button>
              <button class="btn btn-secondary btn-sm" type="button" data-action="cancel-edit-comment" data-comment-id="${comment.id}" data-place-id="${placeId}">Cancel</button>
            </div>
          </form>
        </div>
      `;
        }).join('');

        list.querySelectorAll('.travel-comment-text').forEach(textarea => updateCharCount(textarea));
    }

    function updateCharCount(textarea) {
        const counter = textarea.closest('form')?.querySelector('.travel-character-count');
        if (!counter) {
            return;
        }
        const max = parseInt(counter.dataset.max || textarea.maxLength || '0', 10);
        if (!max) {
            return;
        }
        const remaining = Math.max(0, max - textarea.value.length);
        counter.textContent = `${remaining} characters remaining`;
    }

    async function loadRegions() {
        const params = new URLSearchParams({ action: 'regions' });
        const data = await apiGet(params);
        state.regions = data.regions || [];
        renderRegionOptions();
        updateRegionNotes();
    }

    async function loadPlaces() {
        clearMessage();
        const params = new URLSearchParams({ action: 'places' });
        if (state.filters.regionId > 0) {
            params.set('region_id', String(state.filters.regionId));
        }
        if (state.filters.type !== 'all') {
            params.set('type', state.filters.type);
        }
        if (state.filters.tag) {
            params.set('tag', state.filters.tag);
        }
        if (state.filters.query) {
            params.set('q', state.filters.query);
        }
        if (state.filters.visited !== 'all') {
            params.set('visited', state.filters.visited);
        }
        const data = await apiGet(params);
        state.places = data.places || [];
        state.tagPool = data.tag_pool || [];
        renderTagOptions();
        renderPlaces();
    }

    async function loadComments(placeId) {
        const params = new URLSearchParams({ action: 'comments', place_id: String(placeId) });
        const data = await apiGet(params);
        state.comments[placeId] = data.comments || [];
        renderComments(placeId);
    }

    async function handleRegionNotesSave() {
        if (!canManageRegions) {
            showMessage('You do not have permission to manage regions.', 'error');
            return;
        }
        const regionId = parseInt(elements.regionFilter.value || '0', 10);
        const region = state.regions.find(item => item.id === regionId);
        if (!region) {
            showMessage('Select a region to update notes.', 'error');
            return;
        }
        const formData = createSecureFormData({
            action: 'save_region',
            id: regionId,
            name: region.name,
            notes: elements.regionNotes.value
        });
        try {
            await apiPost(formData);
            await loadRegions();
            showMessage('Region notes updated.', 'success');
        } catch (error) {
            displayAPIError(error, 'Region update failed');
            showMessage('Region update failed.', 'error');
        }
    }

    async function handlePlaceSubmit(event) {
        event.preventDefault();
        setLoading(true);
        const formData = new FormData(elements.placeForm);
        formData.append('action', 'save_place');
        try {
            await apiPost(formData);
            resetPlaceForm();
            await loadPlaces();
            showMessage('Place saved.', 'success');
        } catch (error) {
            displayAPIError(error, 'Place save failed');
            showMessage('Place save failed.', 'error');
        } finally {
            setLoading(false);
        }
    }

    async function handlePlaceDelete(placeId) {
        const confirmed = await QuackNotify.confirm('Delete this place? This cannot be undone.', {
            title: 'Delete Place',
            confirmText: 'Delete',
            confirmStyle: 'danger'
        });
        if (!confirmed) {
            return;
        }
        const formData = createSecureFormData({ action: 'delete_place', id: placeId });
        try {
            await apiPost(formData);
            await loadPlaces();
            showMessage('Place deleted.', 'success');
        } catch (error) {
            displayAPIError(error, 'Place delete failed');
            showMessage('Place delete failed.', 'error');
        }
    }

    async function handleCommentSubmit(form, placeId, commentId = null) {
        const formData = new FormData(form);
        formData.append('action', 'save_comment');
        formData.append('place_id', String(placeId));
        if (commentId) {
            formData.append('id', String(commentId));
        }
        try {
            await apiPost(formData);
            form.reset();
            await loadComments(placeId);
            await loadPlaces();
            showMessage(commentId ? 'Comment updated.' : 'Comment added.', 'success');
        } catch (error) {
            displayAPIError(error, 'Comment save failed');
            showMessage('Comment save failed.', 'error');
        }
    }

    async function handleCommentDelete(placeId, commentId) {
        const confirmed = await QuackNotify.confirm('Delete this comment? This cannot be undone.', {
            title: 'Delete Comment',
            confirmText: 'Delete',
            confirmStyle: 'danger'
        });
        if (!confirmed) {
            return;
        }
        const formData = createSecureFormData({ action: 'delete_comment', id: commentId });
        try {
            await apiPost(formData);
            await loadComments(placeId);
            await loadPlaces();
            showMessage('Comment deleted.', 'success');
        } catch (error) {
            displayAPIError(error, 'Comment delete failed');
            showMessage('Comment delete failed.', 'error');
        }
    }

    function handlePlaceListClick(event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const action = target.dataset.action;
        if (!action) {
            return;
        }

        const placeId = parseInt(target.dataset.placeId || '0', 10);
        if (action === 'edit-place' && placeId) {
            const place = state.places.find(item => item.id === placeId);
            if (place) {
                populatePlaceForm(place);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            return;
        }

        if (action === 'delete-place' && placeId) {
            handlePlaceDelete(placeId);
            return;
        }

        if (action === 'toggle-comments' && placeId) {
            const commentsSection = elements.places.querySelector(`.travel-comments[data-place-id="${placeId}"]`);
            if (!commentsSection) {
                return;
            }
            const isHidden = commentsSection.hasAttribute('hidden');
            if (isHidden) {
                commentsSection.removeAttribute('hidden');
                if (!state.comments[placeId]) {
                    loadComments(placeId);
                }
            } else {
                commentsSection.setAttribute('hidden', 'hidden');
            }
            return;
        }

        if (action === 'edit-comment') {
            const commentCard = target.closest('.travel-comment');
            const editForm = commentCard?.querySelector('.travel-comment-edit');
            if (editForm) {
                editForm.hidden = false;
            }
            return;
        }

        if (action === 'cancel-edit-comment') {
            const commentCard = target.closest('.travel-comment');
            const editForm = commentCard?.querySelector('.travel-comment-edit');
            if (editForm) {
                editForm.hidden = true;
            }
            return;
        }

        if (action === 'delete-comment') {
            const commentId = parseInt(target.dataset.commentId || '0', 10);
            if (placeId && commentId) {
                handleCommentDelete(placeId, commentId);
            }
        }
    }

    function handlePlaceListSubmit(event) {
        const target = event.target;
        if (!(target instanceof HTMLFormElement)) {
            return;
        }

        if (target.classList.contains('travel-comment-form')) {
            event.preventDefault();
            const placeId = parseInt(target.dataset.placeId || '0', 10);
            if (placeId) {
                handleCommentSubmit(target, placeId);
            }
        }

        if (target.classList.contains('travel-comment-edit')) {
            event.preventDefault();
            const placeId = parseInt(target.dataset.placeId || '0', 10);
            const commentId = parseInt(target.dataset.commentId || '0', 10);
            if (placeId && commentId) {
                handleCommentSubmit(target, placeId, commentId);
            }
        }
    }

    function handleInputEvent(event) {
        const target = event.target;
        if (target instanceof HTMLTextAreaElement && target.classList.contains('travel-comment-text')) {
            updateCharCount(target);
        }
    }

    function bindEvents() {
        elements.refreshBtn?.addEventListener('click', () => loadPlaces());
        elements.placeType?.addEventListener('change', updateSafetyVisibility);
        elements.cancelEdit?.addEventListener('click', resetPlaceForm);
        elements.regionFilter?.addEventListener('change', () => {
            state.filters.regionId = parseInt(elements.regionFilter.value || '0', 10);
            updateRegionNotes();
            if (!elements.placeId.value) {
                elements.placeRegion.value = state.filters.regionId > 0 ? String(state.filters.regionId) : '';
            }
            loadPlaces();
        });
        elements.typeFilter?.addEventListener('change', () => {
            state.filters.type = elements.typeFilter.value;
            loadPlaces();
        });
        elements.tagFilter?.addEventListener('change', () => {
            state.filters.tag = elements.tagFilter.value;
            loadPlaces();
        });
        elements.visitedFilter?.addEventListener('change', () => {
            state.filters.visited = elements.visitedFilter.value;
            loadPlaces();
        });
        elements.searchInput?.addEventListener('input', () => {
            if (state.searchTimer) {
                window.clearTimeout(state.searchTimer);
            }
            state.searchTimer = window.setTimeout(() => {
                state.filters.query = elements.searchInput.value.trim();
                loadPlaces();
            }, 300);
        });
        elements.saveRegionNotes?.addEventListener('click', handleRegionNotesSave);
        elements.placeForm?.addEventListener('submit', handlePlaceSubmit);
        elements.places?.addEventListener('click', handlePlaceListClick);
        elements.places?.addEventListener('submit', handlePlaceListSubmit, true);
        elements.places?.addEventListener('input', handleInputEvent);
    }

    async function init() {
        try {
            lockRegionControls();
            updateSafetyVisibility();
            await loadRegions();
            await loadPlaces();
        } catch (error) {
            displayAPIError(error, 'Travel data load failed');
            showMessage('Travel data load failed.', 'error');
        }
    }

    bindEvents();
    init();
})();
