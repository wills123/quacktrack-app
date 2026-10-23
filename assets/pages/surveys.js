(function() {
    'use strict';

    // =========================================================================
    // Configuration & Constants
    // =========================================================================

    const API = '/api/surveys.php';
    const AUTOSAVE_INTERVAL = 60000;
    const SCORE_LABELS = {
        1: 'Critical',
        2: 'Below Standard',
        3: 'Meets Standard',
        4: 'Exceeds Standard'
    };
    const SCORE_COLORS = {
        1: 'score-critical',
        2: 'score-below',
        3: 'score-meets',
        4: 'score-exceeds'
    };

    const elements = {};
    const state = {
        // List view
        selectedRegionId: null,
        selectedStoreId: null,
        submissions: [],
        filters: {
            role: '',
            date_from: '',
            date_to: '',
            sort: 'newest'
        },
        currentPage: 1,
        totalCount: 0,
        perPage: 25, // the server's page size; each answer's pagination.per_page replaces it

        // Template view
        template: null,
        templateId: null,
        categories: [],

        // Submission view
        submission: null,
        answers: new Map(),
        autoSaveTimer: null,
        isDirty: false,

        // Detail view
        submissionDetail: null,

        // Compare view
        comparison: null,
        managerSubId: null,
        techSubId: null
    };

    document.addEventListener('DOMContentLoaded', init);

    // =========================================================================
    // 1. Initialization & Helpers
    // =========================================================================

    function init() {
        const page = document.querySelector('.survey-page');
        if (!page) {
            return;
        }

        const view = page.dataset.surveyView || 'list';

        cacheCommonElements(page);

        switch (view) {
        case 'list':
            initListView();
            break;
        case 'submit':
            initSubmitView();
            break;
        case 'detail':
            initDetailView();
            break;
        case 'compare':
            initCompareView();
            break;
        case 'template':
            initTemplateView();
            break;
        default:
            initListView();
            break;
        }
    }

    function cacheCommonElements(page) {
        elements.page = page;
        elements.content = page.querySelector('.survey-content');
    }

    function getCSRF() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    async function apiPost(action, data) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf', getCSRF());

        if (data instanceof FormData) {
            for (const [key, value] of data.entries()) {
                if (key !== 'action' && key !== 'csrf') {
                    formData.append(key, value);
                }
            }
        } else if (data && typeof data === 'object') {
            Object.keys(data).forEach((key) => {
                if (key !== 'action' && key !== 'csrf') {
                    formData.append(key, data[key]);
                }
            });
        }

        try {
            const response = await fetch(API, {
                method: 'POST',
                body: formData
            });
            const json = await response.json();
            if (!response.ok || json.success === false) {
                throw new Error(json.error || json.message || 'Request failed');
            }
            return json;
        } catch (err) {
            QuackNotify.error(err.message || 'An error occurred');
            throw err;
        }
    }

    async function apiGet(action, params) {
        const url = new URL(API, window.location.origin);
        url.searchParams.set('action', action);

        if (params && typeof params === 'object') {
            Object.keys(params).forEach((key) => {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    url.searchParams.set(key, params[key]);
                }
            });
        }

        try {
            const response = await fetch(url.toString());
            const json = await response.json();
            if (!response.ok || json.success === false) {
                throw new Error(json.error || json.message || 'Request failed');
            }
            return json;
        } catch (err) {
            QuackNotify.error(err.message || 'An error occurred');
            throw err;
        }
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getUrlParam(name) {
        const params = new URLSearchParams(window.location.search);
        return params.get(name);
    }

    function navigateTo(view, params) {
        let url = '/surveys?view=' + encodeURIComponent(view);
        if (params && typeof params === 'object') {
            Object.keys(params).forEach((key) => {
                if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                    url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
                }
            });
        } else if (typeof params === 'string' && params) {
            url += '&' + params;
        }
        window.location.href = url;
    }

    function getScoreColorClass(avgScore) {
        const score = parseFloat(avgScore);
        if (isNaN(score) || score < 1) {
            return 'score-none';
        }
        if (score < 2.0) {
            return 'score-critical';
        }
        if (score < 3.0) {
            return 'score-below';
        }
        if (score < 3.5) {
            return 'score-meets';
        }
        return 'score-exceeds';
    }

    function formatDate(dateStr) {
        if (!dateStr) {
            return '';
        }
        try {
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch (e) {
            return escapeHtml(dateStr);
        }
    }

    function formatDateTime(dateStr) {
        if (!dateStr) {
            return '';
        }
        try {
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return escapeHtml(dateStr);
        }
    }

    function getRoleBadgeHtml(role) {
        const cls = role === 'manager' ? 'role-badge role-manager' : 'role-badge role-tech';
        const label = role === 'manager' ? 'Manager' : 'Tech';
        return '<span class="' + cls + '">' + escapeHtml(label) + '</span>';
    }

    // =========================================================================
    // 2. List View (main page)
    // =========================================================================

    function initListView() {
        elements.regionSelect = document.getElementById('surveyRegionSelect');
        elements.storeSelect = document.getElementById('surveyStoreSelect');
        elements.storeContent = document.getElementById('surveyStoreContent');
        elements.storeTitle = document.getElementById('surveyStoreTitle');
        elements.newSurveyBtn = document.getElementById('surveyNewBtn');
        elements.historyTable = document.getElementById('surveyHistoryTable');
        elements.historyBody = document.getElementById('surveyHistoryBody');
        elements.filterRole = document.getElementById('surveyFilterRole');
        elements.filterDateFrom = document.getElementById('surveyFilterDateFrom');
        elements.filterDateTo = document.getElementById('surveyFilterDateTo');
        elements.pagination = document.getElementById('surveyPagination');
        elements.actionButtons = document.getElementById('surveyActionButtons');

        if (elements.regionSelect) {
            elements.regionSelect.addEventListener('change', function() {
                onRegionChange(this.value);
            });
        }

        if (elements.storeSelect) {
            elements.storeSelect.addEventListener('change', function() {
                onStoreChange(this.value);
            });
        }

        if (elements.newSurveyBtn) {
            elements.newSurveyBtn.addEventListener('click', () => {
                if (!state.selectedStoreId) {
                    QuackNotify.error('Please select a store first.');
                    return;
                }
                navigateTo('submit', { store_id: state.selectedStoreId });
            });
        }

        if (elements.templateBtn) {
            elements.templateBtn.addEventListener('click', () => {
                navigateTo('template');
            });
        }

        if (elements.filterRole) {
            elements.filterRole.addEventListener('change', onFilterChange);
        }
        if (elements.filterDateFrom) {
            elements.filterDateFrom.addEventListener('change', onFilterChange);
        }
        if (elements.filterDateTo) {
            elements.filterDateTo.addEventListener('change', onFilterChange);
        }
        if (elements.filterSort) {
            elements.filterSort.addEventListener('change', onFilterChange);
        }

        if (elements.historyBody) {
            elements.historyBody.addEventListener('click', (event) => {
                const viewBtn = event.target.closest('[data-action="view-submission"]');
                if (viewBtn) {
                    const subId = viewBtn.dataset.submissionId;
                    if (subId) {
                        navigateTo('detail', { submission_id: subId });
                    }
                }
            });
        }

        if (elements.pagination) {
            elements.pagination.addEventListener('click', (event) => {
                const pageBtn = event.target.closest('[data-page]');
                if (pageBtn) {
                    const page = parseInt(pageBtn.dataset.page, 10);
                    if (page && page !== state.currentPage) {
                        state.currentPage = page;
                        fetchSubmissions();
                    }
                }
            });
        }

        // Restore selections from URL params
        const regionParam = getUrlParam('region_id');
        const storeParam = getUrlParam('store_id');
        if (regionParam && elements.regionSelect) {
            elements.regionSelect.value = regionParam;
            onRegionChange(regionParam);
            if (storeParam && elements.storeSelect) {
                elements.storeSelect.value = storeParam;
                onStoreChange(storeParam);
            }
        }
    }

    function onRegionChange(regionId) {
        state.selectedRegionId = regionId ? parseInt(regionId, 10) : null;
        state.selectedStoreId = null;
        state.submissions = [];
        state.currentPage = 1;

        if (elements.storeSelect) {
            elements.storeSelect.disabled = false;
            const options = elements.storeSelect.querySelectorAll('option[data-region-id]');
            options.forEach((opt) => {
                if (!regionId || opt.dataset.regionId === String(regionId)) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
            elements.storeSelect.value = '';
        }

        if (elements.storeContent) {
            elements.storeContent.style.display = 'none';
        }
        if (elements.historyBody) {
            elements.historyBody.innerHTML = '<tr><td colspan="6" class="text-muted">Select a store to view survey history.</td></tr>';
        }
        if (elements.pagination) {
            elements.pagination.innerHTML = '';
        }
        toggleActionButtons(false);
    }

    function onStoreChange(storeId) {
        state.selectedStoreId = storeId ? parseInt(storeId, 10) : null;
        state.currentPage = 1;

        if (state.selectedStoreId) {
            if (elements.storeContent) {
                elements.storeContent.style.display = '';
            }
            // Update store title with selected store name
            if (elements.storeTitle && elements.storeSelect) {
                const selectedOption = elements.storeSelect.options[elements.storeSelect.selectedIndex];
                elements.storeTitle.textContent = (selectedOption ? selectedOption.textContent : 'Survey History');
            }
            toggleActionButtons(true);
            fetchSubmissions();
        } else {
            if (elements.storeContent) {
                elements.storeContent.style.display = 'none';
            }
            toggleActionButtons(false);
            if (elements.historyBody) {
                elements.historyBody.innerHTML = '<tr><td colspan="6" class="text-muted">Select a store to view survey history.</td></tr>';
            }
            if (elements.pagination) {
                elements.pagination.innerHTML = '';
            }
        }
    }

    function toggleActionButtons(show) {
        if (elements.actionButtons) {
            elements.actionButtons.style.display = show ? '' : 'none';
        }
    }

    function onFilterChange() {
        if (elements.filterRole) {
            state.filters.role = elements.filterRole.value;
        }
        if (elements.filterDateFrom) {
            state.filters.date_from = elements.filterDateFrom.value;
        }
        if (elements.filterDateTo) {
            state.filters.date_to = elements.filterDateTo.value;
        }
        if (elements.filterSort) {
            state.filters.sort = elements.filterSort.value;
        }
        state.currentPage = 1;
        fetchSubmissions();
    }

    async function fetchSubmissions() {
        if (!state.selectedStoreId) {
            return;
        }

        const params = {
            store_id: state.selectedStoreId,
            page: state.currentPage,
            submitter_role: state.filters.role,
            date_from: state.filters.date_from,
            date_to: state.filters.date_to,
            sort: state.filters.sort
        };

        try {
            const result = await apiGet('list_submissions', params);
            const data = result.data || {};
            state.submissions = data.submissions || [];
            state.totalCount = (data.pagination && data.pagination.total) || 0;
            state.perPage = (data.pagination && data.pagination.per_page) || state.perPage;
            renderHistoryTable(state.submissions, state.totalCount);
        } catch (err) {
            if (elements.historyBody) {
                elements.historyBody.innerHTML = '<tr><td colspan="6" class="text-danger">Failed to load submissions.</td></tr>';
            }
        }
    }

    function renderHistoryTable(submissions, totalCount) {
        if (!elements.historyBody) {
            return;
        }

        if (!submissions.length) {
            elements.historyBody.innerHTML = '<tr><td colspan="6" class="text-muted">No survey submissions found for this store.</td></tr>';
            if (elements.pagination) {
                elements.pagination.innerHTML = '';
            }
            return;
        }

        const rows = submissions.map((sub) => {
            const avgScore = parseFloat(sub.overall_avg_score) || 0;
            const scoreDisplay = avgScore > 0 ? avgScore.toFixed(2) : 'N/A';
            const scoreClass = getScoreColorClass(avgScore);
            const roleBadge = getRoleBadgeHtml(sub.submitter_role);
            const dateStr = formatDate(sub.submitted_at || sub.created_at);
            const versionLabel = sub.version_number ? 'v' + escapeHtml(sub.version_number) : '';

            return '<tr>' +
                '<td>' + escapeHtml(dateStr) + '</td>' +
                '<td>' + escapeHtml(sub.submitted_by_name || '') + '</td>' +
                '<td>' + roleBadge + '</td>' +
                '<td>' + escapeHtml(versionLabel) + '</td>' +
                '<td><span class="score-badge ' + scoreClass + '">' + escapeHtml(scoreDisplay) + '</span></td>' +
                '<td><button type="button" class="btn-secondary btn-sm" data-action="view-submission" data-submission-id="' + escapeHtml(sub.id) + '">View</button></td>' +
                '</tr>';
        }).join('');

        elements.historyBody.innerHTML = rows;
        renderPagination(totalCount);
    }

    function renderPagination(totalCount) {
        if (!elements.pagination) {
            return;
        }

        const perPage = state.perPage;
        const totalPages = Math.ceil(totalCount / perPage);

        if (totalPages <= 1) {
            elements.pagination.innerHTML = '';
            return;
        }

        let html = '';
        if (state.currentPage > 1) {
            html += '<button type="button" class="btn-link" data-page="' + (state.currentPage - 1) + '">Previous</button> ';
        }

        for (let i = 1; i <= totalPages; i++) {
            if (i === state.currentPage) {
                html += '<span class="pagination-current">' + i + '</span> ';
            } else if (i === 1 || i === totalPages || Math.abs(i - state.currentPage) <= 2) {
                html += '<button type="button" class="btn-link" data-page="' + i + '">' + i + '</button> ';
            } else if (Math.abs(i - state.currentPage) === 3) {
                html += '<span class="pagination-ellipsis">...</span> ';
            }
        }

        if (state.currentPage < totalPages) {
            html += '<button type="button" class="btn-link" data-page="' + (state.currentPage + 1) + '">Next</button>';
        }

        elements.pagination.innerHTML = html;
    }

    // =========================================================================
    // 3. Template Management View
    // =========================================================================

    function initTemplateView() {
        elements.templateEditor = document.getElementById('surveyTemplateEditor');
        elements.noTemplate = document.getElementById('surveyNoTemplate');
        elements.createTemplateBtn = document.getElementById('surveyCreateTemplate');
        elements.templateTitle = document.getElementById('surveyTemplateTitle');
        elements.templateDescription = document.getElementById('surveyTemplateDescription');
        elements.saveTemplateInfoBtn = document.getElementById('surveySaveTemplateInfo');
        elements.addCategoryBtn = document.getElementById('surveyAddCategory');
        elements.publishBtn = document.getElementById('surveyPublishVersion');
        elements.categoriesList = document.getElementById('surveyCategoriesList');
        elements.versionHistory = document.getElementById('surveyVersionHistory');
        elements.backToListBtn = document.getElementById('surveyBackToList');

        if (elements.saveTemplateInfoBtn) {
            elements.saveTemplateInfoBtn.addEventListener('click', saveTemplateInfo);
        }
        if (elements.addCategoryBtn) {
            elements.addCategoryBtn.addEventListener('click', addCategory);
        }
        if (elements.publishBtn) {
            elements.publishBtn.addEventListener('click', publishVersion);
        }
        if (elements.createTemplateBtn) {
            elements.createTemplateBtn.addEventListener('click', createTemplate);
        }

        if (elements.categoriesList) {
            elements.categoriesList.addEventListener('click', handleTemplateCategoryClick);
        }

        fetchTemplate();
    }

    async function createTemplate() {
        const title = prompt('Enter a title for the survey template:', 'IT Standards Survey');
        if (!title || !title.trim()) {
            return;
        }

        try {
            const result = await apiPost('create_template', { title: title.trim() });
            const data = result.data || {};
            state.template = data.template || {};
            state.categories = (state.template && state.template.categories) ? state.template.categories : [];
            state.templateId = state.template ? state.template.id : null;
            QuackNotify.success('Template created.');
            showTemplateEditor();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    async function fetchTemplate() {
        try {
            const result = await apiGet('get_active_template', {});
            const data = result.data || {};
            state.template = data.template || null;

            if (state.template) {
                state.templateId = state.template.id;
                state.categories = state.template.categories || [];
                showTemplateEditor();
            } else {
                // No template exists yet — show create prompt
                state.templateId = null;
                state.categories = [];
                if (elements.noTemplate) {
                    elements.noTemplate.style.display = '';
                }
                if (elements.templateEditor) {
                    elements.templateEditor.style.display = 'none';
                }
                if (elements.publishBtn) {
                    elements.publishBtn.hidden = true;
                }
            }
        } catch (err) {
            if (elements.templateEditor) {
                elements.templateEditor.innerHTML = '<p class="text-danger">Failed to load template data.</p>';
                elements.templateEditor.style.display = '';
            }
        }
    }

    function showTemplateEditor() {
        if (elements.noTemplate) {
            elements.noTemplate.style.display = 'none';
        }
        if (elements.templateEditor) {
            elements.templateEditor.style.display = '';
        }
        if (elements.publishBtn) {
            elements.publishBtn.hidden = false;
        }
        renderTemplateEditor();
    }

    function renderTemplateEditor() {
        if (!state.template) {
            return;
        }

        if (elements.templateTitle) {
            elements.templateTitle.value = state.template.title || '';
        }
        if (elements.templateDescription) {
            elements.templateDescription.value = state.template.description || '';
        }

        renderCategoriesList();

        if (state.template.versions) {
            renderVersionHistory(state.template.versions);
        }
    }

    function renderCategoriesList() {
        if (!elements.categoriesList) {
            return;
        }

        if (!state.categories.length) {
            elements.categoriesList.innerHTML = '<p class="text-muted">No categories yet. Add one to get started.</p>';
            return;
        }

        const html = state.categories.map((cat, _catIndex) => {
            let questionsHtml = '';
            const questions = cat.questions || [];

            if (questions.length) {
                questionsHtml = questions.map((q, qIndex) => {
                    return '<div class="template-question" data-question-id="' + escapeHtml(q.id) + '">' +
                        '<div class="template-question-header">' +
                        '<span class="question-number">' + (qIndex + 1) + '.</span>' +
                        '<span class="question-text">' + escapeHtml(q.question_text) + '</span>' +
                        '<div class="question-actions">' +
                        '<button type="button" class="btn-link btn-sm" data-action="edit-question" data-question-id="' + escapeHtml(q.id) + '" title="Edit question">Edit</button>' +
                        '<button type="button" class="btn-link btn-sm" data-action="move-question-up" data-question-id="' + escapeHtml(q.id) + '" data-category-id="' + escapeHtml(cat.id) + '" title="Move up">Up</button>' +
                        '<button type="button" class="btn-link btn-sm" data-action="move-question-down" data-question-id="' + escapeHtml(q.id) + '" data-category-id="' + escapeHtml(cat.id) + '" title="Move down">Down</button>' +
                        '<button type="button" class="btn-link btn-sm text-danger" data-action="delete-question" data-question-id="' + escapeHtml(q.id) + '" title="Delete question">Delete</button>' +
                        '</div>' +
                        '</div>' +
                        (q.scoring_guidance ? '<p class="question-guidance">' + escapeHtml(q.scoring_guidance) + '</p>' : '') +
                        '</div>';
                }).join('');
            } else {
                questionsHtml = '<p class="text-muted">No questions in this category.</p>';
            }

            return '<div class="template-category" data-category-id="' + escapeHtml(cat.id) + '">' +
                '<div class="template-category-header">' +
                '<div class="category-info">' +
                '<strong class="category-name">' + escapeHtml(cat.name) + '</strong>' +
                '<span class="category-count">' + questions.length + ' question' + (questions.length !== 1 ? 's' : '') + '</span>' +
                '</div>' +
                '<div class="category-actions">' +
                '<button type="button" class="btn-link btn-sm" data-action="move-category-up" data-category-id="' + escapeHtml(cat.id) + '" title="Move up">Up</button>' +
                '<button type="button" class="btn-link btn-sm" data-action="move-category-down" data-category-id="' + escapeHtml(cat.id) + '" title="Move down">Down</button>' +
                '<button type="button" class="btn-secondary btn-sm" data-action="edit-category" data-category-id="' + escapeHtml(cat.id) + '">Edit</button>' +
                '<button type="button" class="btn-secondary btn-sm" data-action="add-question" data-category-id="' + escapeHtml(cat.id) + '">Add Question</button>' +
                '<button type="button" class="btn-link btn-sm text-danger" data-action="delete-category" data-category-id="' + escapeHtml(cat.id) + '">Delete</button>' +
                '</div>' +
                '</div>' +
                '<div class="template-questions">' + questionsHtml + '</div>' +
                '</div>';
        }).join('');

        elements.categoriesList.innerHTML = html;
    }

    function handleTemplateCategoryClick(event) {
        const target = event.target.closest('[data-action]');
        if (!target) {
            return;
        }

        const action = target.dataset.action;
        const categoryId = target.dataset.categoryId;
        const questionId = target.dataset.questionId;

        switch (action) {
        case 'edit-category':
            editCategory(categoryId);
            break;
        case 'delete-category':
            deleteCategory(categoryId);
            break;
        case 'move-category-up':
            moveCategoryUp(categoryId);
            break;
        case 'move-category-down':
            moveCategoryDown(categoryId);
            break;
        case 'add-question':
            addQuestion(categoryId);
            break;
        case 'edit-question':
            editQuestion(questionId);
            break;
        case 'delete-question':
            deleteQuestion(questionId);
            break;
        case 'move-question-up':
            moveQuestion(questionId, categoryId, 'up');
            break;
        case 'move-question-down':
            moveQuestion(questionId, categoryId, 'down');
            break;
        case 'save-inline-category':
            saveInlineCategory(categoryId);
            break;
        case 'cancel-inline-category':
            renderCategoriesList();
            break;
        case 'save-inline-question':
            saveInlineQuestion(questionId, categoryId);
            break;
        case 'cancel-inline-question':
            renderCategoriesList();
            break;
        case 'save-new-question':
            saveNewQuestion(categoryId);
            break;
        case 'cancel-new-question':
            renderCategoriesList();
            break;
        default:
            break;
        }
    }

    async function saveTemplateInfo() {
        const title = elements.templateTitle ? elements.templateTitle.value.trim() : '';
        const description = elements.templateDescription ? elements.templateDescription.value.trim() : '';

        if (!title) {
            QuackNotify.error('Template title is required.');
            return;
        }

        if (!state.templateId) {
            QuackNotify.error('No template loaded.');
            return;
        }

        try {
            await apiPost('update_template', {
                template_id: state.templateId,
                title: title,
                description: description
            });
            QuackNotify.success('Template info saved.');
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    async function addCategory() {
        const name = prompt('Enter category name:');
        if (!name || !name.trim()) {
            return;
        }

        if (!state.templateId) {
            QuackNotify.error('No template loaded.');
            return;
        }

        try {
            await apiPost('add_category', { template_id: state.templateId, name: name.trim() });
            QuackNotify.success('Category added.');
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    function editCategory(categoryId) {
        const catEl = elements.categoriesList.querySelector('[data-category-id="' + categoryId + '"]');
        if (!catEl) {
            return;
        }

        const cat = state.categories.find((c) => {
            return String(c.id) === String(categoryId);
        });
        if (!cat) {
            return;
        }

        const header = catEl.querySelector('.template-category-header');
        if (!header) {
            return;
        }

        header.innerHTML =
            '<div class="inline-edit-form">' +
            '<input type="text" class="inline-edit-input" id="inlineCatName_' + escapeHtml(categoryId) + '" value="' + escapeHtml(cat.name) + '" placeholder="Category name">' +
            '<button type="button" class="btn-primary btn-sm" data-action="save-inline-category" data-category-id="' + escapeHtml(categoryId) + '">Save</button>' +
            '<button type="button" class="btn-link btn-sm" data-action="cancel-inline-category" data-category-id="' + escapeHtml(categoryId) + '">Cancel</button>' +
            '</div>';

        const input = document.getElementById('inlineCatName_' + categoryId);
        if (input) {
            input.focus();
            input.select();
        }
    }

    async function saveInlineCategory(categoryId) {
        const input = document.getElementById('inlineCatName_' + categoryId);
        if (!input) {
            return;
        }

        const name = input.value.trim();
        if (!name) {
            QuackNotify.error('Category name is required.');
            return;
        }

        try {
            await apiPost('update_category', {
                category_id: categoryId,
                name: name
            });
            QuackNotify.success('Category updated.');
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    async function deleteCategory(categoryId) {
        const cat = state.categories.find((c) => {
            return String(c.id) === String(categoryId);
        });
        const catName = cat ? cat.name : 'this category';

        QuackNotify.confirm('Delete "' + catName + '" and all its questions? This cannot be undone.', {
            title: 'Delete Category',
            confirmText: 'Delete',
            cancelText: 'Cancel',
            onConfirm: async function() {
                try {
                    await apiPost('delete_category', { category_id: categoryId });
                    QuackNotify.success('Category deleted.');
                    await fetchTemplate();
                } catch (err) {
                    // Error already shown by apiPost
                }
            }
        });
    }

    async function moveCategoryUp(categoryId) {
        try {
            await apiPost('reorder_category', {
                category_id: categoryId,
                direction: 'up'
            });
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    async function moveCategoryDown(categoryId) {
        try {
            await apiPost('reorder_category', {
                category_id: categoryId,
                direction: 'down'
            });
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    function addQuestion(categoryId) {
        const catEl = elements.categoriesList.querySelector('[data-category-id="' + categoryId + '"] .template-questions');
        if (!catEl) {
            return;
        }

        // Remove any existing add-question form in this category
        const existingForm = catEl.querySelector('.new-question-form');
        if (existingForm) {
            existingForm.remove();
        }

        const formHtml =
            '<div class="new-question-form">' +
            '<div class="form-field">' +
            '<label>Question Text</label>' +
            '<input type="text" class="new-question-text" id="newQText_' + escapeHtml(categoryId) + '" placeholder="Enter question text...">' +
            '</div>' +
            '<div class="form-field">' +
            '<label>Scoring Guidance</label>' +
            '<textarea class="new-question-guidance" id="newQGuidance_' + escapeHtml(categoryId) + '" rows="3" placeholder="Describe what each score (1-4) means for this question..."></textarea>' +
            '</div>' +
            '<div class="form-actions">' +
            '<button type="button" class="btn-primary btn-sm" data-action="save-new-question" data-category-id="' + escapeHtml(categoryId) + '">Add Question</button>' +
            '<button type="button" class="btn-link btn-sm" data-action="cancel-new-question" data-category-id="' + escapeHtml(categoryId) + '">Cancel</button>' +
            '</div>' +
            '</div>';

        catEl.insertAdjacentHTML('beforeend', formHtml);

        const textInput = document.getElementById('newQText_' + categoryId);
        if (textInput) {
            textInput.focus();
        }
    }

    async function saveNewQuestion(categoryId) {
        const textInput = document.getElementById('newQText_' + categoryId);
        const guidanceInput = document.getElementById('newQGuidance_' + categoryId);

        if (!textInput) {
            return;
        }

        const questionText = textInput.value.trim();
        if (!questionText) {
            QuackNotify.error('Question text is required.');
            return;
        }

        const scoringGuidance = guidanceInput ? guidanceInput.value.trim() : '';

        try {
            await apiPost('add_question', {
                category_id: categoryId,
                question_text: questionText,
                scoring_guidance: scoringGuidance
            });
            QuackNotify.success('Question added.');
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    function editQuestion(questionId) {
        const qEl = elements.categoriesList.querySelector('[data-question-id="' + questionId + '"]');
        if (!qEl) {
            return;
        }

        let question = null;
        for (let i = 0; i < state.categories.length; i++) {
            const cat = state.categories[i];
            const found = (cat.questions || []).find((q) => {
                return String(q.id) === String(questionId);
            });
            if (found) {
                question = found;
                break;
            }
        }

        if (!question) {
            return;
        }

        qEl.innerHTML =
            '<div class="inline-edit-form">' +
            '<div class="form-field">' +
            '<label>Question Text</label>' +
            '<input type="text" class="inline-edit-input" id="editQText_' + escapeHtml(questionId) + '" value="' + escapeHtml(question.question_text) + '">' +
            '</div>' +
            '<div class="form-field">' +
            '<label>Scoring Guidance</label>' +
            '<textarea class="inline-edit-input" id="editQGuidance_' + escapeHtml(questionId) + '" rows="3">' + escapeHtml(question.scoring_guidance || '') + '</textarea>' +
            '</div>' +
            '<div class="form-actions">' +
            '<button type="button" class="btn-primary btn-sm" data-action="save-inline-question" data-question-id="' + escapeHtml(questionId) + '">Save</button>' +
            '<button type="button" class="btn-link btn-sm" data-action="cancel-inline-question" data-question-id="' + escapeHtml(questionId) + '">Cancel</button>' +
            '</div>' +
            '</div>';

        const textInput = document.getElementById('editQText_' + questionId);
        if (textInput) {
            textInput.focus();
        }
    }

    async function saveInlineQuestion(questionId) {
        const textInput = document.getElementById('editQText_' + questionId);
        const guidanceInput = document.getElementById('editQGuidance_' + questionId);

        if (!textInput) {
            return;
        }

        const questionText = textInput.value.trim();
        if (!questionText) {
            QuackNotify.error('Question text is required.');
            return;
        }

        const scoringGuidance = guidanceInput ? guidanceInput.value.trim() : '';

        try {
            await apiPost('update_question', {
                question_id: questionId,
                question_text: questionText,
                scoring_guidance: scoringGuidance
            });
            QuackNotify.success('Question updated.');
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    async function deleteQuestion(questionId) {
        QuackNotify.confirm('Delete this question? This cannot be undone.', {
            title: 'Delete Question',
            confirmText: 'Delete',
            cancelText: 'Cancel',
            onConfirm: async function() {
                try {
                    await apiPost('delete_question', { question_id: questionId });
                    QuackNotify.success('Question deleted.');
                    await fetchTemplate();
                } catch (err) {
                    // Error already shown by apiPost
                }
            }
        });
    }

    async function moveQuestion(questionId, categoryId, direction) {
        try {
            await apiPost('reorder_question', {
                question_id: questionId,
                category_id: categoryId,
                direction: direction
            });
            await fetchTemplate();
        } catch (err) {
            // Error already shown by apiPost
        }
    }

    function publishVersion() {
        if (!state.templateId) {
            QuackNotify.error('No template loaded.');
            return;
        }

        const nextVersion = state.template && state.template.version_count
            ? (parseInt(state.template.version_count, 10) + 1)
            : 1;

        let totalQuestions = 0;
        state.categories.forEach((cat) => {
            totalQuestions += (cat.questions || []).length;
        });

        if (totalQuestions === 0) {
            QuackNotify.error('Add at least one question before publishing.');
            return;
        }

        QuackNotify.confirm('This creates Version ' + nextVersion + ' with ' + totalQuestions + ' question(s) across ' + state.categories.length + ' categories. Published versions are used for new survey submissions.', {
            title: 'Publish New Version',
            confirmText: 'Publish Version ' + nextVersion,
            cancelText: 'Cancel',
            onConfirm: async function() {
                try {
                    await apiPost('publish_version', { template_id: state.templateId });
                    QuackNotify.success('Version ' + nextVersion + ' published successfully.');
                    await fetchTemplate();
                } catch (err) {
                    // Error already shown by apiPost
                }
            }
        });
    }

    function renderVersionHistory(versions) {
        if (!elements.versionHistory) {
            return;
        }

        if (!versions || !versions.length) {
            elements.versionHistory.innerHTML = '<p class="text-muted">No published versions yet.</p>';
            return;
        }

        const rows = versions.map((v) => {
            return '<tr>' +
                '<td>v' + escapeHtml(v.version_number) + '</td>' +
                '<td>' + formatDate(v.published_at) + '</td>' +
                '<td>' + escapeHtml(v.publisher_name || 'Unknown') + '</td>' +
                '<td>' + escapeHtml(v.question_count || 0) + '</td>' +
                '</tr>';
        }).join('');

        elements.versionHistory.innerHTML =
            '<table class="data-table">' +
            '<thead><tr>' +
            '<th>Version</th>' +
            '<th>Published</th>' +
            '<th>Published By</th>' +
            '<th>Questions</th>' +
            '</tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
            '</table>';
    }

    // =========================================================================
    // 4. Submission View
    // =========================================================================

    function initSubmitView() {
        elements.submitContainer = document.getElementById('surveySubmitContainer');
        elements.loadingState = document.getElementById('surveyLoading');

        // Check for draft resume or new survey
        const submissionId = getUrlParam('submission_id');
        const storeId = getUrlParam('store_id');

        if (submissionId) {
            loadDraft(submissionId);
        } else if (storeId) {
            // If user can submit as manager, require an explicit choice (per spec).
            const allowRoleChoice = !!(window.SURVEY_PAGE && window.SURVEY_PAGE.allowRoleChoice);
            if (allowRoleChoice) {
                showRoleChoiceAndStart(storeId);
            } else {
                const role = (window.SURVEY_PAGE && window.SURVEY_PAGE.submitterRole) || 'tech';
                startSurvey(storeId, role);
            }
        } else {
            QuackNotify.error('No store selected.');
            navigateTo('list');
        }
    }

    function hideLoading() {
        if (elements.loadingState) {
            elements.loadingState.style.display = 'none';
        }
        if (elements.submitContainer) {
            elements.submitContainer.style.display = '';
        }
    }

    function setLoadingRoleLabel(role) {
        try {
            const el = document.getElementById('surveyLoadingRole');
            if (!el) {
                return;
            }
            el.classList.remove('survey-loading__role--tech', 'survey-loading__role--manager');
            el.classList.add('survey-loading__role--' + role);
            el.textContent = (role === 'manager') ? 'Manager Review' : 'Tech Review';
        } catch (e) {
            // no-op
        }
    }

    function showRoleChoiceAndStart(storeId) {
        const loadingCard = document.querySelector('#surveyLoading .survey-loading__card');
        if (!loadingCard) {
            // fallback
            startSurvey(storeId, 'tech');
            return;
        }

        setLoadingRoleLabel('tech');

        // Avoid duplicating UI if called twice
        if (document.getElementById('surveyRoleChoice')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.id = 'surveyRoleChoice';
        wrapper.style.marginTop = '14px';
        wrapper.innerHTML =
            '<div style="font-weight:600; margin-bottom:8px;">Select review type</div>' +
            '<div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">' +
              '<button type="button" class="btn btn-secondary" data-role="tech">Tech Review</button>' +
              '<button type="button" class="btn btn-primary" data-role="manager">Manager Review</button>' +
            '</div>' +
            '<div class="muted" style="margin-top:8px; font-size:12px;">Choose the role for this submission.</div>';

        loadingCard.appendChild(wrapper);

        wrapper.addEventListener('click', (ev) => {
            const btn = ev.target && ev.target.closest ? ev.target.closest('button[data-role]') : null;
            if (!btn) {
                return;
            }
            let role = btn.getAttribute('data-role') || 'tech';
            if (role !== 'tech' && role !== 'manager') {
                role = 'tech';
            }

            // persist last choice for convenience
            try {
                localStorage.setItem('qt_survey_submitter_role', role);
            } catch (e) { /* ignore localStorage errors */ }

            // remove UI and start
            wrapper.remove();
            setLoadingRoleLabel(role);
            startSurvey(storeId, role);
        });

        // If user previously chose a role, visually highlight it but still require an explicit click.
        try {
            const saved = localStorage.getItem('qt_survey_submitter_role');
            if (saved === 'tech' || saved === 'manager') {
                const savedBtn = wrapper.querySelector('button[data-role="' + saved + '"]');
                if (savedBtn) {
                    savedBtn.style.outline = '2px solid rgba(0,0,0,0.15)';
                }
            }
        } catch (e) { /* ignore localStorage errors */ }
    }

    async function startSurvey(storeId, submitterRole) {
        try {
            const result = await apiPost('start_survey', {
                store_id: storeId,
                submitter_role: submitterRole
            });
            const data = result.data || {};
            state.submission = data.submission || {};
            const versionQuestions = data.categories || [];
            state.answers = new Map();
            hideLoading();
            renderSurveyForm(versionQuestions, null);
            startAutoSave();
        } catch (err) {
            hideLoading();
            if (elements.submitContainer) {
                elements.submitContainer.innerHTML =
                    '<div class="survey-error-state">' +
                    '<h2>Unable to start survey</h2>' +
                    '<p>' + escapeHtml(err.message || 'An error occurred.') + '</p>' +
                    '<a href="/surveys" class="btn btn-secondary">Back to Surveys</a>' +
                    '</div>';
            }
        }
    }

    async function loadDraft(submissionId) {
        try {
            const result = await apiGet('get_draft', { submission_id: submissionId });
            const data = result.data || {};
            state.submission = data.submission || {};
            const versionQuestions = data.categories || [];
            const existingAnswers = data.answers || [];

            // Populate answers map
            state.answers = new Map();
            existingAnswers.forEach((a) => {
                state.answers.set(String(a.question_id), {
                    score: a.score ? parseInt(a.score, 10) : null,
                    notes: a.notes || '',
                    photos: a.photos || []
                });
            });

            hideLoading();
            renderSurveyForm(versionQuestions, existingAnswers);
            startAutoSave();
        } catch (err) {
            hideLoading();
            QuackNotify.error('Failed to load draft. Returning to list.');
            navigateTo('list');
        }
    }

    function renderSurveyForm(versionQuestions, _existingAnswers) {
        if (!elements.submitContainer) {
            return;
        }

        let totalQuestions = 0;
        versionQuestions.forEach((cat) => {
            totalQuestions += (cat.questions || []).length;
        });

        const headerHtml =
            '<div class="survey-form-header">' +
            '<div class="survey-form-meta">' +
            '<span>Store: <strong>' + escapeHtml(state.submission.store_name || '') + '</strong></span>' +
            '<span>Role: ' + getRoleBadgeHtml(state.submission.submitter_role || '') + '</span>' +
            '<span id="surveySaveIndicator" class="save-indicator"></span>' +
            '</div>' +
            '<div class="survey-progress">' +
            '<div class="progress-bar-container">' +
            '<div class="progress-bar" id="surveyProgressBar" style="width: 0%"></div>' +
            '</div>' +
            '<span class="progress-text" id="surveyProgressText">0 / ' + totalQuestions + '</span>' +
            '</div>' +
            '</div>';

        const categoriesHtml = versionQuestions.map((cat) => {
            const questionsHtml = (cat.questions || []).map((q) => {
                const answer = state.answers.get(String(q.id));
                const currentScore = answer ? answer.score : null;
                const currentNotes = answer ? answer.notes : '';
                const currentPhotos = answer && answer.photos ? answer.photos : [];
                const notesVisible = currentNotes ? ' is-visible' : '';

                const scoreTilesHtml = [1, 2, 3, 4].map((s) => {
                    const selected = currentScore === s ? ' is-selected' : '';
                    const colorClass = SCORE_COLORS[s] || '';
                    return '<button type="button" class="score-tile ' + colorClass + selected + '" ' +
                        'data-action="select-score" data-question-id="' + escapeHtml(q.id) + '" data-score="' + s + '">' +
                        '<span class="score-value">' + s + '</span>' +
                        '<span class="score-label">' + escapeHtml(SCORE_LABELS[s]) + '</span>' +
                        '</button>';
                }).join('');

                const guidanceHtml = q.scoring_guidance
                    ? '<button type="button" class="guidance-trigger" data-action="toggle-guidance" data-question-id="' + escapeHtml(q.id) + '" title="View scoring guidance">?</button>' +
                      '<div class="guidance-popover" id="guidance_' + escapeHtml(q.id) + '" style="display: none;">' +
                      '<p>' + escapeHtml(q.scoring_guidance) + '</p>' +
                      '</div>'
                    : '';

                const photoThumbnailsHtml = currentPhotos.map((photo) => {
                    return '<div class="photo-thumbnail" data-photo-id="' + escapeHtml(photo.id) + '">' +
                        '<img src="' + escapeHtml(photo.url) + '" alt="Survey photo">' +
                        '<button type="button" class="photo-remove" data-action="remove-photo" data-photo-id="' + escapeHtml(photo.id) + '" title="Remove photo">x</button>' +
                        '</div>';
                }).join('');

                return '<div class="survey-question" data-question-id="' + escapeHtml(q.id) + '">' +
                    '<div class="question-header">' +
                    '<p class="question-text">' + escapeHtml(q.question_text) + '</p>' +
                    guidanceHtml +
                    '</div>' +
                    '<div class="score-tiles">' + scoreTilesHtml + '</div>' +
                    '<div class="question-extras">' +
                    '<button type="button" class="btn-link btn-sm" data-action="toggle-notes" data-question-id="' + escapeHtml(q.id) + '">Notes</button>' +
                    '<div class="question-notes' + notesVisible + '" id="notes_' + escapeHtml(q.id) + '">' +
                    '<textarea class="notes-textarea" data-question-id="' + escapeHtml(q.id) + '" placeholder="Add notes...">' + escapeHtml(currentNotes) + '</textarea>' +
                    '</div>' +
                    '<div class="question-photos">' +
                    '<div class="photo-thumbnails" id="photos_' + escapeHtml(q.id) + '">' + photoThumbnailsHtml + '</div>' +
                    '<label class="photo-upload-label">' +
                    '<input type="file" class="photo-upload-input" data-question-id="' + escapeHtml(q.id) + '" accept="image/jpeg,image/png" style="display:none;">' +
                    '<span class="btn-link btn-sm">Upload Photo</span>' +
                    '</label>' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            }).join('');

            return '<details class="survey-category" open>' +
                '<summary class="category-summary">' +
                '<strong>' + escapeHtml(cat.name) + '</strong>' +
                '<span class="category-question-count">' + (cat.questions || []).length + ' questions</span>' +
                '</summary>' +
                '<div class="category-questions">' + questionsHtml + '</div>' +
                '</details>';
        }).join('');

        const actionsHtml =
            '<div class="survey-form-actions">' +
            '<button type="button" class="btn-primary" id="surveySubmitBtn">Submit Survey</button>' +
            '<button type="button" class="btn-link text-danger" id="surveyDeleteDraftBtn">Delete Draft</button>' +
            '<button type="button" class="btn-link" id="surveyBackBtn">Back to List</button>' +
            '</div>';

        elements.submitContainer.innerHTML = headerHtml + categoriesHtml + actionsHtml;

        // Re-cache dynamic elements
        elements.progressBar = document.getElementById('surveyProgressBar');
        elements.progressText = document.getElementById('surveyProgressText');
        elements.saveIndicator = document.getElementById('surveySaveIndicator');
        elements.submitBtn = document.getElementById('surveySubmitBtn');
        elements.deleteDraftBtn = document.getElementById('surveyDeleteDraftBtn');
        elements.backBtn = document.getElementById('surveyBackBtn');

        // Bind events via delegation
        elements.submitContainer.addEventListener('click', handleSurveyFormClick);
        elements.submitContainer.addEventListener('change', handleSurveyFormChange);
        elements.submitContainer.addEventListener('input', handleSurveyFormInput);

        if (elements.submitBtn) {
            elements.submitBtn.addEventListener('click', submitSurvey);
        }
        if (elements.deleteDraftBtn) {
            elements.deleteDraftBtn.addEventListener('click', deleteDraft);
        }
        if (elements.backBtn) {
            elements.backBtn.addEventListener('click', () => {
                navigateTo('list', { store_id: state.submission.store_id });
            });
        }

        updateProgress();
    }

    function handleSurveyFormClick(event) {
        const target = event.target.closest('[data-action]');
        if (!target) {
            return;
        }

        const action = target.dataset.action;

        switch (action) {
        case 'select-score':
            onScoreSelect(target.dataset.questionId, parseInt(target.dataset.score, 10));
            break;
        case 'toggle-notes':
            toggleNotes(target.dataset.questionId);
            break;
        case 'toggle-guidance':
            toggleGuidance(target.dataset.questionId);
            break;
        case 'remove-photo':
            removePhoto(target.dataset.photoId);
            break;
        default:
            break;
        }
    }

    function handleSurveyFormChange(event) {
        const fileInput = event.target.closest('.photo-upload-input');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            const questionId = fileInput.dataset.questionId;
            handlePhotoUpload(questionId, fileInput);
        }
    }

    function handleSurveyFormInput(event) {
        const textarea = event.target.closest('.notes-textarea');
        if (textarea) {
            onNotesChange(textarea.dataset.questionId, textarea.value);
        }
    }

    function onScoreSelect(questionId, score) {
        const qId = String(questionId);
        const answer = state.answers.get(qId) || { score: null, notes: '', photos: [] };
        answer.score = score;
        state.answers.set(qId, answer);
        state.isDirty = true;

        // Update tile selection UI
        const questionEl = elements.submitContainer.querySelector('[data-question-id="' + questionId + '"].survey-question');
        if (questionEl) {
            const tiles = questionEl.querySelectorAll('.score-tile');
            tiles.forEach((tile) => {
                const tileScore = parseInt(tile.dataset.score, 10);
                if (tileScore === score) {
                    tile.classList.add('is-selected');
                } else {
                    tile.classList.remove('is-selected');
                }
            });
        }

        updateProgress();
    }

    function toggleNotes(questionId) {
        const notesEl = document.getElementById('notes_' + questionId);
        if (notesEl) {
            notesEl.classList.toggle('is-visible');
            if (notesEl.classList.contains('is-visible')) {
                const textarea = notesEl.querySelector('textarea');
                if (textarea) {
                    textarea.focus();
                }
            }
        }
    }

    function toggleGuidance(questionId) {
        const guidanceEl = document.getElementById('guidance_' + questionId);
        if (guidanceEl) {
            const isVisible = guidanceEl.style.display !== 'none';
            guidanceEl.style.display = isVisible ? 'none' : 'block';
        }
    }

    function onNotesChange(questionId, text) {
        const qId = String(questionId);
        const answer = state.answers.get(qId) || { score: null, notes: '', photos: [] };
        answer.notes = text;
        state.answers.set(qId, answer);
        state.isDirty = true;
    }

    async function handlePhotoUpload(questionId, fileInput) {
        if (!state.submission || !state.submission.id) {
            QuackNotify.error('Survey must be started before uploading photos.');
            return;
        }

        const file = fileInput.files[0];
        if (!file) {
            return;
        }

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png'];
        if (allowedTypes.indexOf(file.type) === -1) {
            QuackNotify.error('Only JPG and PNG files are allowed.');
            fileInput.value = '';
            return;
        }

        const uploadData = new FormData();
        uploadData.append('photo', file);
        uploadData.append('submission_id', state.submission.id);
        uploadData.append('question_id', questionId);

        try {
            const result = await apiPost('upload_photo', uploadData);
            const photo = result.data ? result.data.photo : null;

            if (photo) {
                // Update state
                const qId = String(questionId);
                const answer = state.answers.get(qId) || { score: null, notes: '', photos: [] };
                if (!answer.photos) {
                    answer.photos = [];
                }
                answer.photos.push(photo);
                state.answers.set(qId, answer);

                // Append thumbnail to UI
                const photosContainer = document.getElementById('photos_' + questionId);
                if (photosContainer) {
                    const thumbHtml =
                        '<div class="photo-thumbnail" data-photo-id="' + escapeHtml(photo.id) + '">' +
                        '<img src="' + escapeHtml(photo.url) + '" alt="Survey photo">' +
                        '<button type="button" class="photo-remove" data-action="remove-photo" data-photo-id="' + escapeHtml(photo.id) + '" title="Remove photo">x</button>' +
                        '</div>';
                    photosContainer.insertAdjacentHTML('beforeend', thumbHtml);
                }
            }

            QuackNotify.success('Photo uploaded.');
        } catch (err) {
            // Error already shown by apiPost
        } finally {
            fileInput.value = '';
        }
    }

    async function removePhoto(photoId) {
        QuackNotify.confirm('Remove this photo?', {
            title: 'Remove Photo',
            confirmText: 'Remove',
            cancelText: 'Cancel',
            onConfirm: async function() {
                try {
                    await apiPost('delete_photo', { photo_id: photoId });

                    // Remove from state
                    state.answers.forEach((answer) => {
                        if (answer.photos) {
                            answer.photos = answer.photos.filter((p) => {
                                return String(p.id) !== String(photoId);
                            });
                        }
                    });

                    // Remove from DOM
                    const thumbEl = elements.submitContainer.querySelector('[data-photo-id="' + photoId + '"]');
                    if (thumbEl) {
                        thumbEl.remove();
                    }

                    QuackNotify.success('Photo removed.');
                } catch (err) {
                    // Error already shown by apiPost
                }
            }
        });
    }

    function updateProgress() {
        let totalQuestions = 0;
        let scoredQuestions = 0;

        const allQuestionEls = elements.submitContainer
            ? elements.submitContainer.querySelectorAll('.survey-question')
            : [];

        totalQuestions = allQuestionEls.length;

        state.answers.forEach((answer) => {
            if (answer.score !== null && answer.score !== undefined) {
                scoredQuestions++;
            }
        });

        const pct = totalQuestions > 0 ? Math.round((scoredQuestions / totalQuestions) * 100) : 0;

        if (elements.progressBar) {
            elements.progressBar.style.width = pct + '%';
        }
        if (elements.progressText) {
            elements.progressText.textContent = scoredQuestions + ' / ' + totalQuestions;
        }
    }

    async function autoSaveDraft() {
        if (!state.isDirty || !state.submission || !state.submission.id) {
            return;
        }

        const answersArray = [];
        state.answers.forEach((answer, questionId) => {
            answersArray.push({
                version_question_id: questionId,
                score: answer.score,
                notes: answer.notes || ''
            });
        });

        try {
            await apiPost('save_draft', {
                submission_id: state.submission.id,
                answers: JSON.stringify(answersArray)
            });
            state.isDirty = false;

            if (elements.saveIndicator) {
                const now = new Date();
                const timeStr = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
                elements.saveIndicator.textContent = 'Saved at ' + timeStr;
            }
        } catch (err) {
            if (elements.saveIndicator) {
                elements.saveIndicator.textContent = 'Save failed';
            }
        }
    }

    function startAutoSave() {
        stopAutoSave();
        state.autoSaveTimer = setInterval(autoSaveDraft, AUTOSAVE_INTERVAL);
    }

    function stopAutoSave() {
        if (state.autoSaveTimer) {
            clearInterval(state.autoSaveTimer);
            state.autoSaveTimer = null;
        }
    }

    function submitSurvey() {
        if (!state.submission || !state.submission.id) {
            QuackNotify.error('No active survey to submit.');
            return;
        }

        // Check if all questions are answered
        const totalQuestions = elements.submitContainer
            ? elements.submitContainer.querySelectorAll('.survey-question').length
            : 0;
        let scoredQuestions = 0;
        state.answers.forEach((answer) => {
            if (answer.score !== null && answer.score !== undefined) {
                scoredQuestions++;
            }
        });

        const message = scoredQuestions < totalQuestions
            ? 'You have answered ' + scoredQuestions + ' of ' + totalQuestions + ' questions. Submit anyway?'
            : 'Submit this survey? This action cannot be undone.';

        QuackNotify.confirm(message, {
            title: 'Submit Survey',
            confirmText: 'Submit',
            cancelText: 'Cancel',
            onConfirm: async function() {
                try {
                    // Save latest answers first
                    const answersArray = [];
                    state.answers.forEach((answer, questionId) => {
                        answersArray.push({
                            version_question_id: questionId,
                            score: answer.score,
                            notes: answer.notes || ''
                        });
                    });

                    await apiPost('save_draft', {
                        submission_id: state.submission.id,
                        answers: JSON.stringify(answersArray)
                    });

                    // Then finalize submission
                    await apiPost('submit_survey', {
                        submission_id: state.submission.id
                    });
                    stopAutoSave();
                    QuackNotify.success('Survey submitted successfully.');
                    const submissionId = state.submission.id;
                    navigateTo('detail', { submission_id: submissionId });
                } catch (err) {
                    // Error already shown by apiPost
                }
            }
        });
    }

    function deleteDraft() {
        if (!state.submission || !state.submission.id) {
            return;
        }

        QuackNotify.confirm('Delete this draft? All answers will be lost.', {
            title: 'Delete Draft',
            confirmText: 'Delete',
            cancelText: 'Cancel',
            onConfirm: async function() {
                try {
                    await apiPost('delete_draft', {
                        submission_id: state.submission.id
                    });
                    stopAutoSave();
                    QuackNotify.success('Draft deleted.');
                    navigateTo('list', { store_id: state.submission.store_id });
                } catch (err) {
                    // Error already shown by apiPost
                }
            }
        });
    }

    // =========================================================================
    // 5. Detail View
    // =========================================================================

    function initDetailView() {
        elements.detailContainer = document.getElementById('surveyDetailContainer');
        elements.detailBackBtn = document.getElementById('surveyDetailBackBtn');

        if (elements.detailBackBtn) {
            elements.detailBackBtn.addEventListener('click', () => {
                const storeId = state.submissionDetail ? state.submissionDetail.store_id : '';
                navigateTo('list', { store_id: storeId });
            });
        }

        const submissionId = getUrlParam('submission_id');
        if (!submissionId) {
            QuackNotify.error('No submission specified.');
            navigateTo('list');
            return;
        }

        fetchSubmissionDetail(submissionId);
    }

    async function fetchSubmissionDetail(submissionId) {
        try {
            const result = await apiGet('get_submission', { submission_id: submissionId });
            const data = result.data || {};
            state.submissionDetail = data.submission || {};
            state.submissionDetail.categories = data.categories || [];
            renderSubmissionDetail(state.submissionDetail);
        } catch (err) {
            if (elements.detailContainer) {
                elements.detailContainer.innerHTML = '<p class="text-danger">Failed to load submission.</p>';
            }
        }
    }

    function renderSubmissionDetail(submission) {
        if (!elements.detailContainer) {
            return;
        }

        const overallScore = parseFloat(submission.overall_avg_score) || 0;
        const overallScoreClass = getScoreColorClass(overallScore);
        const overallDisplay = overallScore > 0 ? overallScore.toFixed(2) : 'N/A';

        // Metadata header
        const headerHtml =
            '<div class="sd-header">' +
            '<div class="sd-score-hero sd-score-hero--' + overallScoreClass + '">' +
            '<span class="sd-score-hero__value">' + escapeHtml(overallDisplay) + '</span>' +
            '<span class="sd-score-hero__label">Overall Score</span>' +
            '</div>' +
            '<div class="sd-meta-grid">' +
            '<div class="sd-meta-item">' +
            '<span class="sd-meta-item__label">Store</span>' +
            '<span class="sd-meta-item__value">' + escapeHtml(submission.store_name || '') + '</span>' +
            '</div>' +
            '<div class="sd-meta-item">' +
            '<span class="sd-meta-item__label">Region</span>' +
            '<span class="sd-meta-item__value">' + escapeHtml(submission.region_name || '') + '</span>' +
            '</div>' +
            '<div class="sd-meta-item">' +
            '<span class="sd-meta-item__label">Date</span>' +
            '<span class="sd-meta-item__value">' + formatDateTime(submission.submitted_at || submission.created_at) + '</span>' +
            '</div>' +
            '<div class="sd-meta-item">' +
            '<span class="sd-meta-item__label">Submitter</span>' +
            '<span class="sd-meta-item__value">' + escapeHtml(submission.submitted_by_name || '') + ' ' + getRoleBadgeHtml(submission.submitter_role || '') + '</span>' +
            '</div>' +
            '<div class="sd-meta-item">' +
            '<span class="sd-meta-item__label">Version</span>' +
            '<span class="sd-meta-item__value">v' + escapeHtml(submission.version_number || '') + '</span>' +
            '</div>' +
            '</div>' +
            '<div class="sd-header-actions">' +
            (window.SURVEY_PAGE && window.SURVEY_PAGE.canCompare && submission.submitter_role === 'manager'
                ? '<button type="button" class="btn btn-secondary" id="detailCompareBtn">Compare with Tech</button>'
                : '') +
            '<button type="button" class="btn btn-link" id="surveyDetailBackBtn2">Back to History</button>' +
            '</div>' +
            '</div>';

        // Categories + answers
        const categories = submission.categories || [];
        const categoriesHtml = categories.map((cat) => {
            // Compute category average from question scores
            const catScores = [];
            (cat.questions || []).forEach((q) => {
                const s = q.answer ? parseInt(q.answer.score, 10) : NaN;
                if (!isNaN(s) && s >= 1 && s <= 4) {
                    catScores.push(s);
                }
            });
            const catAvg = catScores.length > 0
                ? catScores.reduce((a, b) => {
                    return a + b;
                }, 0) / catScores.length
                : 0;
            const catAvgDisplay = catAvg > 0 ? catAvg.toFixed(2) : 'N/A';
            const catScoreClass = getScoreColorClass(catAvg);

            const questionsHtml = (cat.questions || []).map((q) => {
                const answer = q.answer || {};
                const score = answer.score ? parseInt(answer.score, 10) : null;
                const scoreLabel = score ? (score + ' - ' + (SCORE_LABELS[score] || '')) : 'Not scored';
                const scoreClass = score ? SCORE_COLORS[score] : 'score-none';
                const answerNotes = answer.notes || '';

                const notesHtml = answerNotes
                    ? '<div class="sd-answer-notes">' + escapeHtml(answerNotes) + '</div>'
                    : '';

                let photosHtml = '';
                if (q.photos && q.photos.length) {
                    photosHtml = '<div class="sd-answer-photos">' +
                        q.photos.map((photo) => {
                            return '<div class="sd-photo-thumb" data-action="view-photo" data-photo-url="' + escapeHtml(photo.file_path || photo.url || '') + '">' +
                                '<img src="' + escapeHtml(photo.file_path || photo.url || '') + '" alt="Survey photo">' +
                                '</div>';
                        }).join('') +
                        '</div>';
                }

                return '<div class="sd-question">' +
                    '<div class="sd-question__row">' +
                    '<span class="sd-question__text">' + escapeHtml(q.question_text) + '</span>' +
                    '<span class="sd-score-pill ' + scoreClass + '">' + escapeHtml(scoreLabel) + '</span>' +
                    '</div>' +
                    notesHtml +
                    photosHtml +
                    '</div>';
            }).join('');

            return '<div class="sd-category">' +
                '<div class="sd-category__header">' +
                '<strong>' + escapeHtml(cat.name) + '</strong>' +
                '<span class="sd-score-pill ' + catScoreClass + '">' + escapeHtml(catAvgDisplay) + '</span>' +
                '</div>' +
                '<div class="sd-category__questions">' + questionsHtml + '</div>' +
                '</div>';
        }).join('');

        elements.detailContainer.innerHTML = headerHtml + categoriesHtml;

        // Bind compare button
        const compareBtn = document.getElementById('detailCompareBtn');
        if (compareBtn) {
            compareBtn.addEventListener('click', () => {
                navigateTo('compare', { manager_submission_id: submission.id });
            });
        }

        // Bind second back button
        const backBtn2 = document.getElementById('surveyDetailBackBtn2');
        if (backBtn2) {
            backBtn2.addEventListener('click', () => {
                navigateTo('list', { store_id: submission.store_id });
            });
        }

        // Photo lightbox via delegation
        elements.detailContainer.addEventListener('click', (event) => {
            const photoThumb = event.target.closest('[data-action="view-photo"]');
            if (photoThumb) {
                openLightbox(photoThumb.dataset.photoUrl);
            }
        });
    }

    function openLightbox(imageUrl) {
        if (!imageUrl) {
            return;
        }

        const existing = document.getElementById('surveyLightbox');
        if (existing) {
            existing.remove();
        }

        const overlay = document.createElement('div');
        overlay.id = 'surveyLightbox';
        overlay.className = 'lightbox-overlay';
        overlay.innerHTML =
            '<div class="lightbox-content">' +
            '<img src="' + escapeHtml(imageUrl) + '" alt="Survey photo full size">' +
            '<button type="button" class="lightbox-close" title="Close">x</button>' +
            '</div>';

        overlay.addEventListener('click', (event) => {
            if (event.target === overlay || event.target.closest('.lightbox-close')) {
                overlay.remove();
            }
        });

        document.body.appendChild(overlay);
    }

    // =========================================================================
    // 6. Comparison View
    // =========================================================================

    function initCompareView() {
        elements.compareContainer = document.getElementById('surveyCompareContainer');
        elements.compareBackBtn = document.getElementById('surveyCompareBackBtn');

        if (elements.compareBackBtn) {
            elements.compareBackBtn.addEventListener('click', () => {
                const managerSubId = state.managerSubId || getUrlParam('manager_submission_id');
                if (managerSubId) {
                    navigateTo('detail', { submission_id: managerSubId });
                } else {
                    navigateTo('list');
                }
            });
        }

        state.managerSubId = getUrlParam('manager_submission_id');
        state.techSubId = getUrlParam('tech_submission_id');

        if (!state.managerSubId) {
            QuackNotify.error('No manager submission specified.');
            navigateTo('list');
            return;
        }

        fetchComparison();
    }

    async function fetchComparison() {
        const params = {
            manager_submission_id: state.managerSubId
        };
        if (state.techSubId) {
            params.tech_submission_id = state.techSubId;
        }

        try {
            const result = await apiGet('get_comparison', params);
            const data = result.data || {};
            state.comparison = data;
            renderComparison(data);
        } catch (err) {
            if (elements.compareContainer) {
                elements.compareContainer.innerHTML = '<p class="text-danger">Failed to load comparison.</p>';
            }
        }
    }

    function renderComparison(data) {
        if (!elements.compareContainer) {
            return;
        }

        const comparison = data.comparison || data;
        const manager = comparison.manager_submission || {};
        const tech = comparison.tech_submission || {};
        const categories = comparison.categories || [];
        const overall = comparison.overall || {};
        const crossVersionWarning = comparison.cross_version_warning || false;

        // Header with both submissions
        const mAvg = (manager.overall_avg_score !== null && manager.overall_avg_score !== undefined) ? (parseFloat(manager.overall_avg_score) || 0) : 0;
        const tHas = !!tech.id;
        const tAvg = tHas && (tech.overall_avg_score !== null && tech.overall_avg_score !== undefined) ? (parseFloat(tech.overall_avg_score) || 0) : null;
        const headerHtml =
            '<div class="compare-header">' +
            '<div class="compare-column compare-manager">' +
            '<h3>Manager Survey</h3>' +
            '<p>' + escapeHtml(manager.submitted_by_name || 'Unknown') + '</p>' +
            '<p>' + formatDate(manager.submitted_at) + '</p>' +
            '<p>Overall: <span class="score-badge ' + getScoreColorClass(mAvg) + '">' + mAvg.toFixed(2) + '</span></p>' +
            '</div>' +
            '<div class="compare-column compare-tech">' +
            (tech.id
                ? '<h3>Tech Survey</h3>' +
                  '<p>' + escapeHtml(tech.submitted_by_name || 'Unknown') + '</p>' +
                  '<p>' + formatDate(tech.submitted_at) + '</p>' +
                  '<p>Overall: <span class="score-badge ' + getScoreColorClass(tAvg) + '">' + (tAvg !== null ? tAvg.toFixed(2) : '-') + '</span></p>'
                : '<h3>Tech Survey</h3>' +
                  '<p class="text-muted">No tech survey found in the comparison window.</p>' +
                  '<p>Overall: <span class="score-badge score-none">-</span></p>') +
            '</div>' +
            '</div>';

        // Cross-version warning / notices
        let warningHtml = '';
        if (comparison.notice) {
            warningHtml +=
                '<div class="compare-warning">' +
                escapeHtml(String(comparison.notice)) +
                '</div>';
        }
        if (crossVersionWarning) {
            warningHtml +=
                '<div class="compare-warning">' +
                '<strong>Warning:</strong> These surveys use different template versions. Per-question comparison may not be accurate as questions may have changed between versions.' +
                '</div>';
        }

        // Category comparison rows
        let categoriesHtml = '';
        if (tech.id) {
            categoriesHtml = categories.map((cat) => {
                const managerAvg = parseFloat(cat.manager_avg) || 0;
                const techAvg = parseFloat(cat.tech_avg) || 0;
                const diff = managerAvg - techAvg;
                const diffDisplay = diff > 0 ? '+' + diff.toFixed(2) : diff.toFixed(2);
                const diffClass = getDiffClass(Math.round(Math.abs(diff)));

                // Per-question detail (only meaningful if same version)
                let questionsHtml = '';
                if (!crossVersionWarning && cat.questions && cat.questions.length) {
                    questionsHtml = '<div class="compare-questions">' +
                        cat.questions.map((q) => {
                            const mScore = q.manager_score !== null && q.manager_score !== undefined ? parseInt(q.manager_score, 10) : null;
                            const tScore = q.tech_score !== null && q.tech_score !== undefined ? parseInt(q.tech_score, 10) : null;
                            const qDiff = (mScore !== null && tScore !== null) ? (mScore - tScore) : null;
                            const qDiffDisplay = qDiff !== null ? (qDiff > 0 ? '+' + qDiff : String(qDiff)) : 'N/A';
                            const qDiffClass = qDiff !== null ? getDiffClass(Math.abs(qDiff)) : '';

                            return '<div class="compare-question-row">' +
                                '<span class="compare-question-text">' + escapeHtml(q.question_text) + '</span>' +
                                '<span class="score-badge ' + (mScore ? SCORE_COLORS[mScore] : 'score-none') + '">' + (mScore !== null ? mScore : '-') + '</span>' +
                                '<span class="score-badge ' + (tScore ? SCORE_COLORS[tScore] : 'score-none') + '">' + (tScore !== null ? tScore : '-') + '</span>' +
                                '<span class="diff-badge diff-' + qDiffClass + '">' + escapeHtml(qDiffDisplay) + '</span>' +
                                '</div>';
                        }).join('') +
                        '</div>';
                }

                return '<details class="compare-category">' +
                    '<summary class="compare-category-header">' +
                    '<span class="compare-category-name">' + escapeHtml(cat.name) + '</span>' +
                    '<span class="score-badge ' + getScoreColorClass(managerAvg) + '">' + managerAvg.toFixed(2) + '</span>' +
                    '<span class="score-badge ' + getScoreColorClass(techAvg) + '">' + techAvg.toFixed(2) + '</span>' +
                    '<span class="diff-badge diff-' + diffClass + '">' + escapeHtml(diffDisplay) + '</span>' +
                    '</summary>' +
                    questionsHtml +
                    '</details>';
            }).join('');
        }

        // Overall summary
        let overallHtml = '';
        if (tech.id) {
            const mOverall = parseFloat(overall.manager_avg) || 0;
            const tOverall = parseFloat(overall.tech_avg) || 0;
            const overallDiff = overall.diff !== null && overall.diff !== undefined ? parseFloat(overall.diff) : (mOverall - tOverall);
            const overallDiffDisplay = overallDiff > 0 ? '+' + overallDiff.toFixed(2) : overallDiff.toFixed(2);
            const overallDiffClass = getDiffClass(Math.round(Math.abs(overallDiff)));

            overallHtml =
                '<div class="compare-summary">' +
                '<div class="compare-summary-row">' +
                '<strong>Overall</strong>' +
                '<span class="score-badge ' + getScoreColorClass(mOverall) + '">' + mOverall.toFixed(2) + '</span>' +
                '<span class="score-badge ' + getScoreColorClass(tOverall) + '">' + tOverall.toFixed(2) + '</span>' +
                '<span class="diff-badge diff-' + overallDiffClass + '">' + escapeHtml(overallDiffDisplay) + '</span>' +
                '</div>' +
                '</div>';
        }

        // Change tech survey button
        const changeTechHtml =
            '<div class="compare-actions">' +
            '<button type="button" class="btn-secondary" id="compareChangeTechBtn">' +
            (tech.id ? 'Change Tech Survey' : 'Select Tech Survey') +
            '</button>' +
            '<button type="button" class="btn-link" id="compareBackToDetailBtn">Back to Manager Survey</button>' +
            '</div>';

        elements.compareContainer.innerHTML = headerHtml + warningHtml + categoriesHtml + overallHtml + changeTechHtml;

        // Bind change tech button
        const changeTechBtn = document.getElementById('compareChangeTechBtn');
        if (changeTechBtn) {
            changeTechBtn.addEventListener('click', changeComparisonTech);
        }

        const backToDetailBtn = document.getElementById('compareBackToDetailBtn');
        if (backToDetailBtn) {
            backToDetailBtn.addEventListener('click', () => {
                navigateTo('detail', { submission_id: state.managerSubId });
            });
        }
    }

    async function changeComparisonTech() {
        try {
            const comparison = state.comparison ? (state.comparison.comparison || state.comparison) : {};
            const managerSub = comparison.manager_submission || {};
            const params = { store_id: managerSub.store_id || state.selectedStoreId || '' };
            if (managerSub.version_id) {
                params.version_id = managerSub.version_id;
            }
            const result = await apiGet('list_tech_surveys', params);
            const techSurveys = (result.data || {}).submissions || [];

            showTechSelectionModal(techSurveys);
        } catch (err) {
            // Error already shown by apiGet
        }
    }

    function showTechSelectionModal(techSurveys) {
        // Remove existing modal
        const existing = document.getElementById('techSelectionModal');
        if (existing) {
            existing.remove();
        }

        let listHtml = '';
        if (!techSurveys.length) {
            listHtml = '<p class="text-muted">No tech surveys found for this store.</p>';
        } else {
            listHtml = '<div class="tech-survey-list">' +
                techSurveys.map((s) => {
                    const avgScore = parseFloat(s.overall_avg_score) || 0;
                    const scoreClass = getScoreColorClass(avgScore);
                    return '<button type="button" class="tech-survey-option" data-tech-submission-id="' + escapeHtml(s.id) + '">' +
                        '<span>' + escapeHtml(s.submitted_by_name || 'Unknown') + '</span>' +
                        '<span>' + formatDate(s.submitted_at) + '</span>' +
                        '<span>v' + escapeHtml(s.version_number || '') + '</span>' +
                        '<span class="score-badge ' + scoreClass + '">' + avgScore.toFixed(2) + '</span>' +
                        '</button>';
                }).join('') +
                '</div>';
        }

        const modal = document.createElement('div');
        modal.id = 'techSelectionModal';
        modal.className = 'modal-overlay';
        modal.innerHTML =
            '<div class="modal-dialog">' +
            '<div class="modal-header">' +
            '<h3>Select Tech Survey</h3>' +
            '<button type="button" class="modal-close" id="techModalClose" title="Close">x</button>' +
            '</div>' +
            '<div class="modal-body">' + listHtml + '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn-link" id="techModalCancel">Cancel</button>' +
            '</div>' +
            '</div>';

        document.body.appendChild(modal);

        // Bind close/cancel
        const closeBtn = document.getElementById('techModalClose');
        const cancelBtn = document.getElementById('techModalCancel');

        function closeModal() {
            modal.remove();
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Bind selection
        modal.addEventListener('click', (event) => {
            const option = event.target.closest('[data-tech-submission-id]');
            if (option) {
                const techSubId = option.dataset.techSubmissionId;
                closeModal();
                state.techSubId = techSubId;
                navigateTo('compare', {
                    manager_submission_id: state.managerSubId,
                    tech_submission_id: techSubId
                });
            }
        });
    }

    function getDiffClass(diff) {
        const absDiff = Math.abs(diff);
        if (absDiff === 0) {
            return 'match';
        }
        if (absDiff === 1) {
            return 'minor';
        }
        return 'major';
    }

})();
