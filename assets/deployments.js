(function() {
    'use strict';

    if (window.__quackTrackDeploymentsInitialized) {
        return;
    }
    window.__quackTrackDeploymentsInitialized = true;

    const config = window.DEPLOYMENT_PAGE || {};
    const API_ENDPOINT = '/api/deployments.php';
    const FORMS_API_ENDPOINT = '/api/forms.php';
    const QUEST_STATUSES = config.questStatuses || ['available','in_progress','blocked','ready_review','complete','archived'];
    const QUEST_LOG_TYPES = ['update','blocker','resolution','handoff','completion','comment'];

    const elements = {};
    const state = {
        deployments: [],
        currentDeploymentId: null,
        quests: [],
        questDetail: null,
        templates: [],
        templateFormMode: 'create',
        templateEditingId: null
    };
    let blockerPromptResolver = null;
    let questDetailReturnFocus = null;
    const modalReturnFocus = new WeakMap();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    function init() {
        cacheElements();
        bindEvents();
        fetchDeployments();
    }

    function cacheElements() {
        elements.boardDeployment = document.getElementById('boardDeployment');
        elements.deploymentLedger = document.getElementById('deploymentLedger');
        elements.questStatusFilter = document.getElementById('questStatusFilter');
        elements.questBoard = document.getElementById('questBoard');
        elements.questBoardTitle = document.getElementById('questBoardTitle');
        elements.questBoardContext = document.getElementById('questBoardContext');
        elements.questCampaignProgress = document.getElementById('questCampaignProgress');
        elements.questCampaignProgressValue = document.getElementById('questCampaignProgressValue');
        elements.questCampaignProgressBar = document.getElementById('questCampaignProgressBar');
        elements.questDetailPanel = document.getElementById('questDetailPanel');
        elements.questDetailScrim = document.getElementById('questDetailScrim');
        elements.questDetailBody = document.getElementById('questDetailBody');
        elements.questDetailClose = document.getElementById('questDetailClose');
        elements.questStatusLabel = document.getElementById('questStatusLabel');
        elements.questTitle = document.getElementById('questTitle');
        elements.questMeta = document.getElementById('questMeta');
        elements.questAssignment = document.getElementById('questAssignment');
        elements.questActionBar = document.getElementById('questActionBar');
        elements.questStatusBtn = document.getElementById('questStatusBtn');
        elements.questAddBtn = document.getElementById('questAddBtn');
        elements.deploymentCreateBtn = document.getElementById('deploymentCreateBtn');
        elements.deploymentCreateModal = document.getElementById('deploymentCreateModal');
        elements.deploymentCreateClose = document.getElementById('deploymentCreateClose');
        elements.deploymentCreateCancel = document.getElementById('deploymentCreateCancel');
        elements.deploymentCreateForm = document.getElementById('deploymentCreateForm');
        elements.localStoreResults = document.getElementById('localStoreResults');
        elements.cwProjectSearch = document.getElementById('cwProjectSearch');
        elements.cwProjectResults = document.getElementById('cwProjectResults');
        elements.createConnectwiseProjectId = document.getElementById('createConnectwiseProjectId');
        elements.createStoreId = document.getElementById('createStoreId');
        elements.createSiteNumber = document.getElementById('createSiteNumber');
        elements.createSiteName = document.getElementById('createSiteName');
        elements.createProjectCode = document.getElementById('createProjectCode');
        elements.createRegion = document.getElementById('createRegion');
        elements.createAddress1 = document.getElementById('createAddress1');
        elements.createAddress2 = document.getElementById('createAddress2');
        elements.createCity = document.getElementById('createCity');
        elements.createState = document.getElementById('createState');
        elements.createPostalCode = document.getElementById('createPostalCode');
        elements.questFormModal = document.getElementById('questFormModal');
        elements.questForm = document.getElementById('questForm');
        elements.questFormClose = document.getElementById('questFormClose');
        elements.questFormCancel = document.getElementById('questFormCancel');
        elements.questFormDeployment = document.getElementById('questFormDeployment');
        elements.templateLibraryModal = document.getElementById('templateLibraryModal');
        elements.templateLibrary = document.getElementById('templateLibrary');
        elements.openTemplateLibrary = document.getElementById('openTemplateLibrary');
        elements.openQuestForms = document.getElementById('openQuestForms');
        elements.openFormResponses = document.getElementById('openFormResponses');
        elements.questFormsModal = document.getElementById('questFormsModal');
        elements.questFormsClose = document.getElementById('questFormsClose');
        elements.questFormsCancel = document.getElementById('questFormsCancel');
        elements.questFormsHeading = document.getElementById('questFormsHeading');
        elements.questFormsIntro = document.getElementById('questFormsIntro');
        elements.questFormsSelect = document.getElementById('questFormsSelect');
        elements.questFormsOpenBtn = document.getElementById('questFormsOpenBtn');
        elements.templateLibraryClose = document.getElementById('templateLibraryClose');
        elements.templateLibraryCancel = document.getElementById('templateLibraryCancel');
        elements.templateCreateBtn = document.getElementById('templateCreateBtn');
        elements.templateFormModal = document.getElementById('templateFormModal');
        elements.templateForm = document.getElementById('templateForm');
        elements.templateFormClose = document.getElementById('templateFormClose');
        elements.templateFormCancel = document.getElementById('templateFormCancel');
        elements.templateFormHeading = document.getElementById('templateFormHeading');
        elements.templateSubmitBtn = document.getElementById('templateSubmitBtn');
        elements.templateIdInput = document.getElementById('templateIdInput');
        elements.templateTitleInput = document.getElementById('templateTitleInput');
        elements.templateBriefInput = document.getElementById('templateBriefInput');
        elements.templateTagsInput = document.getElementById('templateTagsInput');
        elements.templateRoleInput = document.getElementById('templateRoleInput');
        elements.templateAssignInput = document.getElementById('templateAssignInput');
        elements.templateObjectivesList = document.getElementById('templateObjectivesList');
        elements.templateAddObjective = document.getElementById('templateAddObjective');
        elements.blockerModal = document.getElementById('questBlockerModal');
        elements.blockerBackdrop = elements.blockerModal?.querySelector('.quest-blocker-modal__backdrop');
        elements.blockerInput = document.getElementById('blockerReasonInput');
        elements.blockerSubmit = document.getElementById('blockerSubmitBtn');
        elements.blockerCancel = document.getElementById('blockerCancelBtn');
        elements.blockerClose = document.getElementById('blockerCloseBtn');
    }

    function bindEvents() {
        if (elements.deploymentLedger) {
            elements.deploymentLedger.addEventListener('click', (event) => {
                const target = event.target.closest('[data-deployment-id]');
                if (!target) {
                    return;
                }
                const deploymentId = target.getAttribute('data-deployment-id');
                selectDeployment(deploymentId);
            });
        }
        if (elements.questStatusFilter) {
            QUEST_STATUSES.forEach((status) => {
                const option = document.createElement('option');
                option.value = status;
                option.textContent = formatStatus(status);
                elements.questStatusFilter.appendChild(option);
            });
            elements.questStatusFilter.addEventListener('change', renderQuestBoard);
        }
        if (elements.questDetailClose) {
            elements.questDetailClose.addEventListener('click', closeQuestDetail);
        }
        if (elements.questDetailScrim) {
            elements.questDetailScrim.addEventListener('click', closeQuestDetail);
        }
        if (elements.questStatusBtn) {
            elements.questStatusBtn.addEventListener('click', () => {
                if (!state.questDetail) {
                    return;
                }
                openStatusPrompt();
            });
        }
        if (elements.questAddBtn) {
            elements.questAddBtn.addEventListener('click', openQuestForm);
        }
        if (elements.deploymentCreateBtn) {
            elements.deploymentCreateBtn.addEventListener('click', openDeploymentForm);
        }
        if (elements.deploymentCreateClose) {
            elements.deploymentCreateClose.addEventListener('click', closeDeploymentForm);
        }
        if (elements.deploymentCreateCancel) {
            elements.deploymentCreateCancel.addEventListener('click', closeDeploymentForm);
        }
        if (elements.deploymentCreateForm) {
            elements.deploymentCreateForm.addEventListener('submit', handleDeploymentCreate);
        }
        if (elements.createSiteName) {
            elements.createSiteName.addEventListener('input', debounce(handleLocalStoreSearchInput, 250));
            document.addEventListener('click', (event) => {
                if (elements.localStoreResults && !elements.localStoreResults.hidden
                    && !elements.localStoreResults.contains(event.target)
                    && event.target !== elements.createSiteName) {
                    hideLocalStoreResults();
                }
            });
        }
        if (elements.cwProjectSearch) {
            elements.cwProjectSearch.addEventListener('input', debounce(handleCwProjectSearchInput, 250));
            document.addEventListener('click', (event) => {
                if (elements.cwProjectResults && !elements.cwProjectResults.hidden
                    && !elements.cwProjectResults.contains(event.target)
                    && event.target !== elements.cwProjectSearch) {
                    hideCwProjectResults();
                }
            });
        }
        if (elements.questFormClose) {
            elements.questFormClose.addEventListener('click', closeQuestForm);
        }
        if (elements.questFormCancel) {
            elements.questFormCancel.addEventListener('click', closeQuestForm);
        }
        if (elements.questForm) {
            elements.questForm.addEventListener('submit', handleQuestCreate);
        }
        if (elements.openTemplateLibrary) {
            elements.openTemplateLibrary.addEventListener('click', openTemplateLibrary);
        }
        if (elements.openQuestForms) {
            elements.openQuestForms.addEventListener('click', openQuestFormsModal);
        }
        if (elements.openFormResponses) {
            elements.openFormResponses.addEventListener('click', openFormResponses);
        }
        if (elements.questFormsClose) {
            elements.questFormsClose.addEventListener('click', closeQuestFormsModal);
        }
        if (elements.questFormsCancel) {
            elements.questFormsCancel.addEventListener('click', closeQuestFormsModal);
        }
        if (elements.questFormsOpenBtn) {
            elements.questFormsOpenBtn.addEventListener('click', handleQuestFormsOpen);
        }
        if (elements.templateLibraryClose) {
            elements.templateLibraryClose.addEventListener('click', closeTemplateLibrary);
        }
        if (elements.templateLibraryCancel) {
            elements.templateLibraryCancel.addEventListener('click', closeTemplateLibrary);
        }
        if (elements.templateLibrary) {
            elements.templateLibrary.addEventListener('click', handleTemplateLibraryClick);
        }
        if (elements.templateCreateBtn) {
            elements.templateCreateBtn.addEventListener('click', () => openTemplateForm('create'));
        }
        if (elements.templateFormClose) {
            elements.templateFormClose.addEventListener('click', closeTemplateForm);
        }
        if (elements.templateFormCancel) {
            elements.templateFormCancel.addEventListener('click', closeTemplateForm);
        }
        if (elements.templateForm) {
            elements.templateForm.addEventListener('submit', handleTemplateSubmit);
        }
        if (elements.templateAddObjective) {
            elements.templateAddObjective.addEventListener('click', () => addTemplateObjectiveRow());
        }
        if (elements.templateObjectivesList) {
            elements.templateObjectivesList.addEventListener('click', handleTemplateObjectiveActions);
        }
        if (elements.blockerSubmit) {
            elements.blockerSubmit.addEventListener('click', handleBlockerSubmit);
        }
        if (elements.blockerCancel) {
            elements.blockerCancel.addEventListener('click', handleBlockerCancel);
        }
        if (elements.blockerClose) {
            elements.blockerClose.addEventListener('click', handleBlockerCancel);
        }
        if (elements.blockerBackdrop) {
            elements.blockerBackdrop.addEventListener('click', handleBlockerCancel);
        }
        document.querySelectorAll('.deployment-modal-shell').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeDeploymentModalById(modal.id);
                }
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isBlockerModalOpen()) {
                handleBlockerCancel();
            } else if (event.key === 'Escape' && document.querySelector('.deployment-modal-shell.show')) {
                const openModals = [...document.querySelectorAll('.deployment-modal-shell.show')];
                closeDeploymentModalById(openModals.at(-1)?.id || '');
            } else if (event.key === 'Escape' && elements.questDetailPanel?.classList.contains('open')) {
                closeQuestDetail();
            } else if (event.key === 'Tab') {
                trapDeploymentModalFocus(event);
            }
        });
    }

    function openDeploymentModal(modal, focusTarget = null) {
        if (!modal) {
            return;
        }
        modalReturnFocus.set(modal, document.activeElement);
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('deployment-modal-open');
        requestAnimationFrame(() => {
            const firstControl = focusTarget || modal.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])');
            firstControl?.focus();
        });
    }

    function closeDeploymentModal(modal, restoreFocus = true) {
        if (!modal) {
            return;
        }
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.deployment-modal-shell.show')) {
            document.body.classList.remove('deployment-modal-open');
        }
        if (restoreFocus) {
            modalReturnFocus.get(modal)?.focus?.();
        }
    }

    function closeDeploymentModalById(modalId) {
        const closers = {
            questFormModal: closeQuestForm,
            templateLibraryModal: closeTemplateLibrary,
            templateFormModal: closeTemplateForm,
            questFormsModal: closeQuestFormsModal,
            deploymentCreateModal: closeDeploymentForm,
            questBlockerModal: handleBlockerCancel
        };
        closers[modalId]?.();
    }

    function trapDeploymentModalFocus(event) {
        const openModals = [...document.querySelectorAll('.deployment-modal-shell.show')];
        const surface = openModals.at(-1) || (elements.questDetailPanel?.classList.contains('open') ? elements.questDetailPanel : null);
        if (!surface) {
            return;
        }
        const controls = [...surface.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]')];
        if (!controls.length) {
            return;
        }
        const first = controls[0];
        const last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    async function fetchDeployments() {
        // Preload forms list for the quest forms modal (if enabled)
        if (config.canManageFormCampaigns || config.canSubmitInternalForms) {
            fetchAvailableForms().catch(() => {});
        }

        try {
            const params = new URLSearchParams({ action: 'list', limit: 200 });
            const response = await fetch(`${API_ENDPOINT}?${params.toString()}`);
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Unable to load deployments');
            }
            state.deployments = data.data?.records || [];
            renderDeploymentLedger();
            renderQuestContext();
        } catch (err) {
            showError(err.message);
        }
    }

    function renderDeploymentLedger() {
        if (!elements.deploymentLedger) {
            return;
        }
        if (!state.deployments.length) {
            elements.deploymentLedger.innerHTML = '<p class="text-muted">No campaigns yet.</p>';
            state.currentDeploymentId = null;
            renderQuestBoard();
            return;
        }
        let activeId = state.currentDeploymentId ? String(state.currentDeploymentId) : '';
        if (activeId && !state.deployments.some((deployment) => String(deployment.id) === activeId)) {
            activeId = '';
            state.currentDeploymentId = null;
            if (elements.boardDeployment) {
                elements.boardDeployment.value = '';
            }
            state.quests = [];
            renderQuestBoard();
        }
        const ledgerItems = state.deployments
            .map((deployment) => {
                const label = deployment.site_name || deployment.site_number || deployment.project_code || 'Unnamed Site';
                const subtitleParts = [deployment.project_code, deployment.stage, deployment.region_name]
                    .filter(Boolean)
                    .join(' • ');
                const activeClass = String(deployment.id) === activeId ? ' is-active' : '';
                const currentAttribute = activeClass ? ' aria-current="true"' : '';
                return `
          <button type="button" class="ledger-entry${activeClass}" data-deployment-id="${deployment.id}"${currentAttribute}>
            <span class="ledger-entry__top">
              <span class="ledger-title">${escapeHtml(label)}</span>
              ${deployment.stage ? `<span class="ledger-stage">${escapeHtml(formatStatus(deployment.stage))}</span>` : ''}
            </span>
            <span class="ledger-subtitle-line">${escapeHtml(subtitleParts)}</span>
          </button>
        `;
            })
            .join('');
        elements.deploymentLedger.innerHTML = ledgerItems;
        if (activeId) {
            const active = elements.deploymentLedger.querySelector('.ledger-entry.is-active');
            active?.scrollIntoView({ block: 'nearest' });
        }
    }

    function selectDeployment(deploymentId) {
        const parsed = deploymentId ? parseInt(deploymentId, 10) : NaN;
        state.currentDeploymentId = Number.isFinite(parsed) ? parsed : null;
        if (elements.boardDeployment) {
            elements.boardDeployment.value = state.currentDeploymentId ? String(state.currentDeploymentId) : '';
        }
        if (!state.currentDeploymentId) {
            state.quests = [];
            renderDeploymentLedger();
            renderQuestContext();
            renderQuestBoard();
            return;
        }
        const selectedDeployment = state.deployments.find((deployment) => String(deployment.id) === String(state.currentDeploymentId)) || null;
        config.selectedDeployment = selectedDeployment;
        window.DEPLOYMENT_PAGE = config;
        document.dispatchEvent(new CustomEvent('quacktrack:deployment-selected', {
            detail: {
                deployment: selectedDeployment,
                deploymentId: state.currentDeploymentId
            }
        }));
        if (elements.questFormDeployment) {
            elements.questFormDeployment.value = state.currentDeploymentId;
        }
        state.quests = [];
        renderDeploymentLedger();
        renderQuestContext();
        renderQuestBoard();
        loadQuestBoard(state.currentDeploymentId);
    }

    function renderQuestContext() {
        const deployment = state.deployments.find((record) => String(record.id) === String(state.currentDeploymentId));
        if (!deployment) {
            if (elements.questBoardTitle) {
                elements.questBoardTitle.textContent = 'Quest board';
            }
            if (elements.questBoardContext) {
                elements.questBoardContext.textContent = 'Select a campaign to view its work.';
            }
            return;
        }
        const label = deployment.site_name || deployment.site_number || deployment.project_code || 'Selected campaign';
        if (elements.questBoardTitle) {
            elements.questBoardTitle.textContent = label;
        }
        if (elements.questBoardContext) {
            elements.questBoardContext.textContent = [deployment.project_code, formatStatus(deployment.stage), deployment.region_name]
                .filter(Boolean)
                .join(' · ');
        }
    }

    async function loadQuestBoard(deploymentId) {
        if (!deploymentId) {
            renderQuestBoard();
            return;
        }
        const params = new URLSearchParams({
            action: 'quest_board',
            deployment_id: deploymentId
        });
        try {
            const response = await fetch(`${API_ENDPOINT}?${params.toString()}`);
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Unable to load quests');
            }
            state.quests = data.data?.quests || [];
            renderQuestBoard();
        } catch (err) {
            showError(err.message);
            elements.questBoard.innerHTML = `<p class="text-danger">${escapeHtml(err.message)}</p>`;
        }
    }

    function renderQuestBoard() {
        if (!elements.questBoard) {
            return;
        }
        renderCampaignProgress();
        if (!state.currentDeploymentId) {
            elements.questBoard.innerHTML = '<p class="text-muted">Select a campaign to load quest board.</p>';
            return;
        }
        const statusFilter = elements.questStatusFilter?.value || '';
        const columns = statusFilter
            ? [statusFilter]
            : ['available', 'in_progress', 'blocked', 'ready_review', 'complete', 'archived'];
        const columnTitles = {
            available: 'Open Bounties',
            in_progress: 'Active Voyages',
            blocked: 'Storm Delayed',
            ready_review: "Captain's Review",
            complete: 'Treasure Secured',
            archived: 'Archived Tales'
        };
        const container = document.createElement('div');
        container.className = `quest-columns${statusFilter ? ' is-filtered' : ''}`;
        columns.forEach((status) => {
            const column = document.createElement('div');
            column.className = `quest-column quest-column-${status}`;
            column.innerHTML = `
        <div class="quest-column-header">
          <h3>${columnTitles[status] || formatStatus(status)}</h3>
          <span class="quest-count" id="count-${status}"></span>
        </div>
        <div class="quest-column-body" data-status="${status}"></div>
      `;
            container.appendChild(column);
        });
        elements.questBoard.innerHTML = '';
        elements.questBoard.appendChild(container);

        const grouped = columns.reduce((acc, status) => {
            acc[status] = [];
            return acc;
        }, {});
        state.quests.forEach((quest) => {
            if (grouped[quest.status]) {
                grouped[quest.status].push(quest);
            } else {
                (grouped.misc || (grouped.misc = [])).push(quest);
            }
        });

        columns.forEach((status) => {
            const list = container.querySelector(`.quest-column-body[data-status="${status}"]`);
            const countEl = container.querySelector(`#count-${status}`);
            const quests = grouped[status] || [];
            if (countEl) {
                countEl.textContent = quests.length;
            }
            if (!quests.length) {
                list.innerHTML = '<p class="text-muted">No quests</p>';
                return;
            }
            list.innerHTML = '';
            quests.forEach((quest) => list.appendChild(renderQuestCard(quest)));
        });
    }

    function renderCampaignProgress() {
        if (!elements.questCampaignProgress) {
            return;
        }
        if (!state.currentDeploymentId) {
            elements.questCampaignProgress.hidden = true;
            return;
        }
        const activeQuests = state.quests.filter((quest) => quest.status !== 'archived');
        const completed = activeQuests.filter((quest) => quest.status === 'complete').length;
        const total = activeQuests.length;
        const percent = total ? Math.round((completed / total) * 100) : 0;
        elements.questCampaignProgress.hidden = false;
        if (elements.questCampaignProgressValue) {
            elements.questCampaignProgressValue.textContent = `${percent}%`;
        }
        if (elements.questCampaignProgressBar) {
            elements.questCampaignProgressBar.value = percent;
            elements.questCampaignProgressBar.setAttribute(
                'aria-label',
                total ? `${completed} of ${total} quests complete` : 'No active quests'
            );
        }
    }

    function renderQuestCard(quest) {
        const card = document.createElement('article');
        card.className = `quest-card quest-status-${quest.status}`;
        card.dataset.questId = quest.id;
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-label', `Open quest: ${quest.title || 'Untitled quest'}`);
        const lastLog = Array.isArray(quest.recent_logs) && quest.recent_logs.length ? quest.recent_logs[0] : null;
        const logSnippet = lastLog
            ? `<p class="quest-log-snippet" title="${escapeHtml(lastLog.entry_type || '')} • ${escapeHtml(lastLog.created_at || '')}">${escapeHtml(lastLog.message || '')}</p>`
            : '';
        const assignmentLabel = quest.assigned_display || quest.assigned_email || '';
        const assignmentChip = assignmentLabel
            ? `<span class="quest-chip quest-chip-assignee" title="Assigned to">${escapeHtml(assignmentLabel)}</span>`
            : '';
        const objectiveProgress = renderQuestObjectiveProgress(quest.objectives);
        card.innerHTML = `
      <header>
        <h4>${escapeHtml(quest.title)}</h4>
        <span class="quest-tag quest-tag-${quest.status}">${formatStatus(quest.status)}</span>
      </header>
      <p class="quest-brief">${escapeHtml((quest.brief || '').slice(0, 120)) || 'No narrative yet.'}</p>
      ${objectiveProgress}
      <div class="quest-meta-row">
        <span class="quest-meta-right">
          ${assignmentChip}
          ${quest.due_date ? `<span class="quest-chip">Due ${escapeHtml(quest.due_date)}</span>` : ''}
        </span>
      </div>
      ${logSnippet}
    `;
        card.addEventListener('click', () => openQuestDetail(quest.id));
        card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openQuestDetail(quest.id);
            }
        });
        return card;
    }

    function renderQuestObjectiveProgress(objectives = []) {
        const items = Array.isArray(objectives) ? objectives : [];
        if (!items.length) {
            return '<p class="quest-progress quest-progress--empty">No objectives assigned</p>';
        }
        const completed = items.filter((objective) => objective.status === 'done').length;
        const total = items.length;
        return `<div class="quest-progress"><div class="quest-progress__label"><span>Objectives</span><span>${completed}/${total}</span></div><progress class="quest-progress__bar" value="${completed}" max="${total}" aria-label="${completed} of ${total} objectives complete"></progress></div>`;
    }

    async function openQuestDetail(questId) {
        questDetailReturnFocus = document.activeElement;
        try {
            const response = await fetch(`${API_ENDPOINT}?action=quest_get&quest_id=${questId}`);
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Unable to load quest');
            }
            state.questDetail = data.data?.quest || null;
            renderQuestDetail();
        } catch (err) {
            showError(err.message);
        }
    }

    function renderQuestDetail() {
        if (!state.questDetail || !elements.questDetailBody) {
            return;
        }
        const quest = state.questDetail;
        elements.questStatusLabel.textContent = formatStatus(quest.status);
        elements.questTitle.textContent = quest.title;
        const tags = Array.isArray(quest.tags) ? quest.tags.join(', ') : '';
        const assigneeLabel = quest.assigned_display || quest.assigned_email || '';
        const metaParts = [tags, quest.due_date ? `Due ${quest.due_date}` : '', assigneeLabel ? `Assigned ${assigneeLabel}` : ''];
        elements.questMeta.textContent = metaParts.filter(Boolean).join(' • ');
        if (elements.questAssignment) {
            elements.questAssignment.textContent = assigneeLabel ? `Assigned to ${assigneeLabel}` : 'Unassigned quest';
        }
        renderQuestActions();

        const objectives = (quest.objectives || []).map((obj) => `
      <li data-objective-id="${obj.id}">
        <label>
          <input type="checkbox" ${obj.status === 'done' ? 'checked' : ''} data-action="objective-status">
          <span>${escapeHtml(obj.label)}</span>
        </label>
      </li>
    `).join('') || '<p class="text-muted">No objectives yet.</p>';

        const logs = (quest.logs || []).map((log) => `
      <div class="quest-log-entry quest-log-${log.entry_type}">
        <div class="quest-log-meta">
          <span>${escapeHtml(log.user_display || log.user_email || 'System')}</span>
          <span>${escapeHtml(log.created_at)}</span>
        </div>
        <p>${escapeHtml(log.message)}</p>
      </div>
    `).join('') || '<p class="text-muted">No log entries yet.</p>';

        const blockerNotice = quest.blocker_reason
            ? `<div class="quest-blocker" role="status">
           <strong>Blocked:</strong>
           <span>${escapeHtml(quest.blocker_reason)}</span>
         </div>`
            : '';

        elements.questDetailBody.innerHTML = `
      ${blockerNotice}
      <section>
        <h3>Narrative</h3>
        <p>${escapeHtml(quest.brief || 'No narrative yet.')}</p>
      </section>
      <section>
        <h3>Objectives</h3>
        <ul class="quest-objectives">
          ${objectives}
        </ul>
      </section>
      <section>
        <h3>Logs</h3>
        <form id="questLogForm">
          <textarea name="message" rows="2" placeholder="Add log entry..."></textarea>
          <div class="form-field">
            <label for="logType">Type</label>
            <select name="entry_type">
              ${QUEST_LOG_TYPES.map((type) => `<option value="${type}">${formatStatus(type)}</option>`).join('')}
            </select>
          </div>
          <button type="submit" class="btn-secondary">Add Log</button>
        </form>
        <div class="quest-logs">${logs}</div>
      </section>
    `;

        const logForm = document.getElementById('questLogForm');
        if (logForm) {
            logForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const formData = new FormData(logForm);
                formData.set('quest_id', quest.id);
                postQuestAction('quest_log', formData, 'Log added').then((updated) => {
                    state.questDetail = updated;
                    renderQuestDetail();
                    loadQuestBoard(state.currentDeploymentId);
                });
            });
        }

        const objectivesList = elements.questDetailBody.querySelector('.quest-objectives');
        if (objectivesList) {
            objectivesList.addEventListener('change', (event) => {
                const checkbox = event.target.closest('input[type="checkbox"][data-action="objective-status"]');
                if (!checkbox) {
                    return;
                }
                const li = checkbox.closest('li');
                if (!li) {
                    return;
                }
                const objectiveId = parseInt(li.dataset.objectiveId, 10);
                if (!objectiveId) {
                    return;
                }
                const payload = new URLSearchParams();
                payload.set('objective_id', objectiveId);
                payload.set('status', checkbox.checked ? 'done' : 'in_progress');
                postQuestAction('quest_update_objective', payload, 'Objective updated')
                    .then((updated) => {
                        state.questDetail = updated;
                        renderQuestDetail();
                        loadQuestBoard(state.currentDeploymentId);
                    })
                    .catch((err) => showError(err.message));
            });
        }

        elements.questDetailPanel?.setAttribute('aria-hidden', 'false');
        elements.questDetailPanel?.classList.add('open');
        document.body.classList.add('deployment-detail-open');
        elements.questTitle?.focus({ preventScroll: true });
    }

    function renderQuestActions() {
        if (!elements.questActionBar) {
            return;
        }
        const quest = state.questDetail;
        if (!quest) {
            elements.questActionBar.innerHTML = '<p class="text-muted">Select a quest to unlock actions.</p>';
            return;
        }
        const actions = buildQuestActions(quest);
        if (!actions.length) {
            elements.questActionBar.innerHTML = '<p class="text-muted">No quick actions available.</p>';
            return;
        }
        elements.questActionBar.innerHTML = '';
        actions.forEach((action) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = action.variant || 'btn-secondary';
            button.textContent = action.label;
            if (action.title) {
                button.title = action.title;
            }
            button.addEventListener('click', action.handler);
            elements.questActionBar.appendChild(button);
        });
    }

    function buildQuestActions(quest) {
        const actions = [];

        const userId = Number(config.currentUserId) || null;
        const assignedId = quest.assigned_user_id ? Number(quest.assigned_user_id) : null;
        if (userId) {
            if (!assignedId) {
                actions.push({
                    key: 'claim',
                    label: 'Take Quest',
                    variant: 'btn-primary',
                    handler: () => assignQuest(quest.id, userId)
                });
            } else if (assignedId === userId) {
                actions.push({
                    key: 'release',
                    label: 'Leave Quest',
                    variant: 'btn-link',
                    handler: () => assignQuest(quest.id, null)
                });
            }
        }

        if (!canChangeQuestStatus(quest)) {
            return actions;
        }

        const objectivesComplete = areObjectivesComplete(quest);
        switch (quest.status) {
        case 'available':
            actions.push({
                key: 'start',
                label: 'Begin Quest',
                variant: 'btn-secondary',
                handler: () => updateQuestStatus('in_progress')
            });
            break;
        case 'in_progress':
            actions.push({
                key: 'blocked',
                label: 'Mark Blocked',
                variant: 'btn-danger',
                handler: () => updateQuestStatus('blocked')
            });
            if (objectivesComplete) {
                actions.push({
                    key: 'ready',
                    label: 'Ready for Review',
                    variant: 'btn-primary',
                    handler: () => updateQuestStatus('ready_review')
                });
            }
            break;
        case 'blocked':
            actions.push({
                key: 'resume',
                label: 'Resume Quest',
                variant: 'btn-secondary',
                handler: () => updateQuestStatus('in_progress')
            });
            if (objectivesComplete) {
                actions.push({
                    key: 'ready',
                    label: 'Ready for Review',
                    variant: 'btn-primary',
                    handler: () => updateQuestStatus('ready_review')
                });
            }
            break;
        case 'ready_review':
            if (config.canQuestEdit) {
                actions.push({
                    key: 'complete',
                    label: 'Mark Complete',
                    variant: 'btn-primary',
                    handler: () => updateQuestStatus('complete')
                });
            }
            actions.push({
                key: 'reopen',
                label: 'Reopen Work',
                variant: 'btn-secondary',
                handler: () => updateQuestStatus('in_progress')
            });
            break;
        case 'complete':
            if (config.canQuestEdit) {
                actions.push({
                    key: 'reopen',
                    label: 'Reopen Quest',
                    variant: 'btn-secondary',
                    handler: () => updateQuestStatus('in_progress')
                });
                actions.push({
                    key: 'archive',
                    label: 'Archive Quest',
                    variant: 'btn-link',
                    handler: () => updateQuestStatus('archived')
                });
            }
            break;
        default:
            break;
        }
        return actions;
    }

    function areObjectivesComplete(quest) {
        const items = Array.isArray(quest.objectives) ? quest.objectives : [];
        if (!items.length) {
            return true;
        }
        return items.every((item) => item.status === 'done');
    }

    async function assignQuest(questId, assignedUserId) {
        const payload = new URLSearchParams();
        payload.set('quest_id', questId);
        if (assignedUserId) {
            payload.set('assigned_user_id', assignedUserId);
        }
        try {
            const updated = await postQuestAction('quest_assign', payload, assignedUserId ? 'Quest claimed' : 'Assignment cleared');
            state.questDetail = updated;
            renderQuestDetail();
            loadQuestBoard(state.currentDeploymentId);
        } catch (err) {
            showError(err.message);
        }
    }

    function canChangeQuestStatus(quest) {
        if (config.canQuestEdit) {
            return true;
        }
        const userId = Number(config.currentUserId) || null;
        const assignedId = quest.assigned_user_id ? Number(quest.assigned_user_id) : null;
        return Boolean(userId && assignedId && assignedId === userId);
    }

    function closeQuestDetail() {
        state.questDetail = null;
        if (elements.questDetailPanel) {
            elements.questDetailPanel.setAttribute('aria-hidden', 'true');
            elements.questDetailPanel.classList.remove('open');
        }
        document.body.classList.remove('deployment-detail-open');
        questDetailReturnFocus?.focus?.();
        questDetailReturnFocus = null;
        if (elements.questActionBar) {
            elements.questActionBar.innerHTML = '<p class="text-muted">Select a quest to unlock actions.</p>';
        }
    }

    function openQuestForm() {
        if (!state.currentDeploymentId || !elements.questFormModal) {
            showError('Select a campaign first.');
            return;
        }
        elements.questFormDeployment.value = state.currentDeploymentId;
        openDeploymentModal(elements.questFormModal, document.getElementById('questTitleInput'));
    }

    function closeQuestForm() {
        if (elements.questFormModal) {
            closeDeploymentModal(elements.questFormModal);
        }
        elements.questForm?.reset();
    }

    async function handleQuestCreate(event) {
        event.preventDefault();
        const formData = new FormData(elements.questForm);
        try {
            await postQuestAction('quest_create', formData, 'Quest created');
            closeQuestForm();
            loadQuestBoard(state.currentDeploymentId);
        } catch (err) {
            showError(err.message);
        }
    }

    function openStatusPrompt() {
        if (!state.questDetail) {
            return;
        }
        const menu = document.createElement('div');
        menu.className = 'quest-status-menu';
        QUEST_STATUSES.forEach((status) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = formatStatus(status);
            btn.className = status === state.questDetail.status ? 'active' : '';
            btn.addEventListener('click', () => {
                updateQuestStatus(status);
                menu.remove();
            });
            menu.appendChild(btn);
        });
        elements.questStatusBtn?.appendChild(menu);
        setTimeout(() => document.addEventListener('click', () => menu.remove(), { once: true }), 0);
    }

    async function updateQuestStatus(status, options = {}) {
        if (!state.questDetail) {
            return;
        }
        const payload = new URLSearchParams();
        payload.set('quest_id', state.questDetail.id);
        payload.set('status', status);
        let blockerReason = options.blocker_reason ?? null;
        if (status === 'blocked') {
            if (typeof blockerReason !== 'string') {
                blockerReason = await promptBlockerReason();
            }
            if (blockerReason === null) {
                return;
            }
            if (!blockerReason.trim()) {
                showError('Blocker reason required');
                return;
            }
            payload.set('blocker_reason', blockerReason.trim());
        } else if (blockerReason) {
            payload.set('blocker_reason', blockerReason);
        } else if (state.questDetail.blocker_reason) {
            payload.set('blocker_reason', '');
        }
        try {
            const updated = await postQuestAction('quest_update_status', payload, 'Quest updated');
            state.questDetail = updated;
            renderQuestDetail();
            loadQuestBoard(state.currentDeploymentId);
        } catch (err) {
            showError(err.message);
        }
    }

    async function postQuestAction(action, data, successMessage) {
        let body;
        if (data instanceof FormData) {
            data.set('csrf', config.csrf || '');
            data.set('action', action);
            body = data;
        } else {
            const params = new URLSearchParams(data);
            params.set('csrf', config.csrf || '');
            params.set('action', action);
            body = params;
        }
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body
        });
        const json = await response.json();
        if (!response.ok || json.success === false) {
            throw new Error(json.error || json.message || 'Request failed');
        }
        if (successMessage) {
            showSuccess(successMessage);
        }
        return json.data?.quest || json.data;
    }

    function promptBlockerReason() {
        if (isBlockerModalOpen()) {
            return Promise.resolve(null);
        }
        return new Promise((resolve) => {
            blockerPromptResolver = resolve;
            openBlockerModal(state.questDetail?.blocker_reason || '');
        });
    }

    function openBlockerModal(initialValue = '') {
        if (!elements.blockerModal) {
            return;
        }
        if (elements.blockerInput) {
            elements.blockerInput.value = initialValue;
        }
        openDeploymentModal(elements.blockerModal, elements.blockerInput);
    }

    function closeBlockerModal() {
        if (!elements.blockerModal) {
            return;
        }
        closeDeploymentModal(elements.blockerModal);
    }

    function isBlockerModalOpen() {
        return elements.blockerModal?.classList.contains('show');
    }

    function handleBlockerSubmit() {
        if (!blockerPromptResolver) {
            closeBlockerModal();
            return;
        }
        const value = (elements.blockerInput?.value || '').trim();
        if (!value) {
            showError('Please describe the blocker.');
            elements.blockerInput?.focus();
            return;
        }
        closeBlockerModal();
        blockerPromptResolver(value);
        blockerPromptResolver = null;
    }

    function handleBlockerCancel() {
        closeBlockerModal();
        if (blockerPromptResolver) {
            blockerPromptResolver(null);
            blockerPromptResolver = null;
        }
    }

    function openTemplateLibrary() {
        loadTemplates();
        openDeploymentModal(elements.templateLibraryModal, elements.templateCreateBtn);
    }

    function closeTemplateLibrary() {
        if (elements.templateLibraryModal) {
            closeDeploymentModal(elements.templateLibraryModal);
        }
    }

    async function loadTemplates() {
        if (!elements.templateLibrary) {
            return;
        }
        elements.templateLibrary.innerHTML = '<p>Loading templates...</p>';
        try {
            const resp = await fetch(`${API_ENDPOINT}?action=quest_templates`);
            const data = await resp.json();
            if (!resp.ok || data.success === false) {
                throw new Error(data.error || 'Unable to load templates');
            }
            const templates = data.data?.templates || [];
            state.templates = templates;
            if (!templates.length) {
                elements.templateLibrary.innerHTML = '<p class="text-muted">No templates yet.</p>';
                return;
            }
            elements.templateLibrary.innerHTML = templates.map(renderTemplateCard).join('');
        } catch (err) {
            elements.templateLibrary.innerHTML = `<p class="text-danger">${escapeHtml(err.message)}</p>`;
        }
    }

    function renderTemplateCard(template) {
        const objectives = (template.objectives || []).map((obj) => `<li>${escapeHtml(obj.label)}</li>`).join('');
        const tags = (template.default_tags || []).map((tag) => `<span class="template-tag-chip">${escapeHtml(tag)}</span>`).join('');
        const role = template.recommended_role ? `<p class="template-role">Recommended: ${escapeHtml(template.recommended_role)}</p>` : '';
        return `
      <article class="template-card" data-template-id="${template.id}">
        <header>
          <div>
            <h4>${escapeHtml(template.title)}</h4>
            <span class="template-meta">Updated ${escapeHtml(template.updated_at)}</span>
          </div>
          <div class="template-tags">${tags}</div>
        </header>
        ${role}
        <p>${escapeHtml(template.brief || 'No brief')}</p>
        <ul>${objectives || '<li>No objectives yet.</li>'}</ul>
        <div class="template-card-actions">
          <button type="button" class="btn-primary" data-template-action="use">Add to Campaign</button>
          <button type="button" class="btn-secondary" data-template-action="edit">Edit</button>
          <button type="button" class="btn-secondary" data-template-action="duplicate">Duplicate</button>
          <button type="button" class="btn-link" data-template-action="archive">Archive</button>
        </div>
        <div class="template-card-confirm">
          <p>Archive this template? Crew members won't be able to use it.</p>
          <div class="confirm-buttons">
            <button type="button" class="btn-danger" data-template-action="confirm-archive">Archive</button>
            <button type="button" class="btn-link" data-template-action="cancel-archive">Cancel</button>
          </div>
        </div>
      </article>
    `;
    }

    function handleTemplateLibraryClick(event) {
        const actionBtn = event.target.closest('[data-template-action]');
        if (!actionBtn) {
            return;
        }
        const card = actionBtn.closest('.template-card');
        if (!card) {
            return;
        }
        event.preventDefault();
        const templateId = parseInt(card.dataset.templateId, 10);
        const template = getTemplateById(templateId);
        const action = actionBtn.dataset.templateAction;
        switch (action) {
        case 'use':
            applyTemplate(templateId);
            break;
        case 'edit':
            if (template) {
                openTemplateForm('edit', template);
            }
            break;
        case 'duplicate':
            if (template) {
                openTemplateForm('duplicate', template);
            }
            break;
        case 'archive':
            toggleTemplateArchiveConfirm(card, true);
            break;
        case 'confirm-archive':
            archiveTemplate(templateId);
            break;
        case 'cancel-archive':
            toggleTemplateArchiveConfirm(card, false);
            break;
        default:
            break;
        }
    }

    async function applyTemplate(templateId) {
        if (!state.currentDeploymentId) {
            showError('Select a campaign first.');
            return;
        }
        const id = parseInt(templateId, 10);
        if (!id) {
            showError('Invalid template.');
            return;
        }
        const payload = new URLSearchParams();
        payload.set('deployment_id', state.currentDeploymentId);
        payload.set('template_id', id);
        try {
            await postQuestAction('quest_template_apply', payload, 'Quest added');
            closeTemplateLibrary();
            loadQuestBoard(state.currentDeploymentId);
        } catch (err) {
            showError(err.message);
        }
    }

    function getTemplateById(templateId) {
        return state.templates.find((tpl) => Number(tpl.id) === Number(templateId));
    }

    function openTemplateForm(mode = 'create', template = null) {
        const openedFromLibrary = elements.templateLibraryModal?.classList.contains('show');
        if (openedFromLibrary) {
            closeDeploymentModal(elements.templateLibraryModal, false);
        }
        state.templateFormMode = mode;
        state.templateEditingId = template ? Number(template.id) : null;
        if (elements.templateFormHeading) {
            elements.templateFormHeading.textContent = mode === 'edit'
                ? 'Edit Template'
                : mode === 'duplicate'
                    ? 'Duplicate Template'
                    : 'Create Template';
        }
        if (elements.templateSubmitBtn) {
            elements.templateSubmitBtn.textContent = mode === 'edit' ? 'Save Changes' : 'Save Template';
        }
        elements.templateForm?.reset();
        if (elements.templateIdInput) {
            elements.templateIdInput.value = state.templateEditingId ? String(state.templateEditingId) : '';
        }
        const titleValue = template ? template.title : '';
        const derivedTitle = mode === 'duplicate' && template ? `${template.title} Copy` : titleValue;
        if (elements.templateTitleInput) {
            elements.templateTitleInput.value = derivedTitle || '';
        }
        if (elements.templateBriefInput) {
            elements.templateBriefInput.value = template?.brief || '';
        }
        if (elements.templateTagsInput) {
            elements.templateTagsInput.value = (template?.default_tags || []).join(', ');
        }
        if (elements.templateRoleInput) {
            elements.templateRoleInput.value = template?.recommended_role || '';
        }
        if (elements.templateAssignInput) {
            elements.templateAssignInput.value = template?.auto_assign_role || '';
        }
        const objectives = template?.objectives || [];
        renderTemplateObjectives(objectives);
        openDeploymentModal(elements.templateFormModal, elements.templateTitleInput);
        if (openedFromLibrary && elements.openTemplateLibrary && elements.templateFormModal) {
            modalReturnFocus.set(elements.templateFormModal, elements.openTemplateLibrary);
        }
    }

    function closeTemplateForm() {
        if (elements.templateFormModal) {
            closeDeploymentModal(elements.templateFormModal);
        }
        clearFormLoading(elements.templateForm, elements.templateSubmitBtn);
        elements.templateForm?.reset();
        state.templateFormMode = 'create';
        state.templateEditingId = null;
        if (elements.templateFormHeading) {
            elements.templateFormHeading.textContent = 'Create Template';
        }
        if (elements.templateSubmitBtn) {
            elements.templateSubmitBtn.textContent = 'Save Template';
        }
        renderTemplateObjectives([]);
    }

    async function handleTemplateSubmit(event) {
        event.preventDefault();
        const objectives = collectTemplateObjectives();
        if (!objectives.length) {
            showError('Add at least one objective.');
            clearFormLoading(elements.templateForm, elements.templateSubmitBtn);
            return;
        }
        const formData = new FormData(elements.templateForm);
        formData.set('objectives', JSON.stringify(objectives));
        formData.set('default_tags', elements.templateTagsInput?.value || '');
        let action = 'quest_template_create';
        if (state.templateFormMode === 'edit') {
            action = 'quest_template_update';
        } else if (state.templateFormMode === 'duplicate') {
            action = 'quest_template_duplicate';
        }
        if (state.templateFormMode !== 'create' && state.templateEditingId) {
            formData.set('template_id', state.templateEditingId);
        }
        const successMessage = state.templateFormMode === 'edit'
            ? 'Template updated'
            : state.templateFormMode === 'duplicate'
                ? 'Template duplicated'
                : 'Template saved';
        try {
            await postQuestAction(action, formData, successMessage);
            closeTemplateForm();
            loadTemplates();
        } catch (err) {
            showError(err.message);
        } finally {
            clearFormLoading(elements.templateForm, elements.templateSubmitBtn);
        }
    }

    async function archiveTemplate(templateId) {
        const payload = new URLSearchParams();
        payload.set('template_id', templateId);
        try {
            await postQuestAction('quest_template_archive', payload, 'Template archived');
            loadTemplates();
        } catch (err) {
            showError(err.message);
        }
    }

    function toggleTemplateArchiveConfirm(card, show) {
        if (!elements.templateLibrary) {
            return;
        }
        elements.templateLibrary.querySelectorAll('.template-card').forEach((node) => {
            node.classList.remove('confirm-archive');
        });
        if (show) {
            card.classList.add('confirm-archive');
        }
    }

    function renderTemplateObjectives(objectives = []) {
        if (!elements.templateObjectivesList) {
            return;
        }
        const rows = objectives.length ? objectives : [{}];
        elements.templateObjectivesList.innerHTML = '';
        rows.forEach((obj, index) => {
            elements.templateObjectivesList.appendChild(createTemplateObjectiveRow(obj, index));
        });
    }

    function createTemplateObjectiveRow(obj = {}, index = 0) {
        const row = document.createElement('div');
        row.className = 'template-objective-row';
        row.dataset.index = index;
        const requiresReview = obj.requires_review === true || obj.requires_review === 1 || obj.requires_review === '1';
        row.innerHTML = `
      <div class="objective-row-header">
        <span class="objective-order">Objective ${index + 1}</span>
        <div class="objective-row-actions">
          <button type="button" class="btn-link" data-objective-action="up" title="Move up" aria-label="Move objective up">↑</button>
          <button type="button" class="btn-link" data-objective-action="down" title="Move down" aria-label="Move objective down">↓</button>
          <button type="button" class="btn-link" data-objective-action="remove" title="Remove" aria-label="Remove objective">✕</button>
        </div>
      </div>
      <div class="objective-row-body">
        <input type="text" class="objective-label" placeholder="Objective title" value="${escapeHtml(obj.label || '')}">
        <textarea class="objective-description" rows="2" placeholder="Description (optional)">${escapeHtml(obj.description || '')}</textarea>
        <label class="objective-review-flag">
          <input type="checkbox" class="objective-review" ${requiresReview ? 'checked' : ''}>
          Requires review
        </label>
      </div>
    `;
        return row;
    }

    function addTemplateObjectiveRow(data = {}) {
        if (!elements.templateObjectivesList) {
            return;
        }
        const index = elements.templateObjectivesList.querySelectorAll('.template-objective-row').length;
        elements.templateObjectivesList.appendChild(createTemplateObjectiveRow(data, index));
        refreshTemplateObjectiveOrder();
    }

    function handleTemplateObjectiveActions(event) {
        const actionBtn = event.target.closest('[data-objective-action]');
        if (!actionBtn) {
            return;
        }
        event.preventDefault();
        const action = actionBtn.dataset.objectiveAction;
        const row = actionBtn.closest('.template-objective-row');
        if (!row || !elements.templateObjectivesList) {
            return;
        }
        if (action === 'remove') {
            row.remove();
            if (!elements.templateObjectivesList.querySelector('.template-objective-row')) {
                addTemplateObjectiveRow();
            }
        } else if (action === 'up' && row.previousElementSibling) {
            elements.templateObjectivesList.insertBefore(row, row.previousElementSibling);
        } else if (action === 'down' && row.nextElementSibling) {
            row.nextElementSibling.after(row);
        }
        refreshTemplateObjectiveOrder();
    }

    function refreshTemplateObjectiveOrder() {
        if (!elements.templateObjectivesList) {
            return;
        }
        elements.templateObjectivesList.querySelectorAll('.template-objective-row').forEach((row, idx) => {
            row.dataset.index = idx;
            const label = row.querySelector('.objective-order');
            if (label) {
                label.textContent = `Objective ${idx + 1}`;
            }
        });
    }

    function collectTemplateObjectives() {
        if (!elements.templateObjectivesList) {
            return [];
        }
        const rows = Array.from(elements.templateObjectivesList.querySelectorAll('.template-objective-row'));
        return rows.map((row, idx) => {
            const labelInput = row.querySelector('.objective-label');
            const descInput = row.querySelector('.objective-description');
            const reviewInput = row.querySelector('.objective-review');
            const label = labelInput?.value.trim();
            if (!label) {
                return null;
            }
            return {
                label,
                description: descInput?.value.trim() || '',
                requires_review: reviewInput?.checked ? 1 : 0,
                sort_order: (idx + 1) * 10
            };
        }).filter(Boolean);
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

    function formatStatus(status) {
        return status
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase());
    }

    function showSuccess(message) {
        if (window.QuackNotify) {
            window.QuackNotify.show(message, 'success');
        } else if (window.AppLogger) {
            AppLogger.info(message);
        }
    }

    function showError(message) {
        if (window.QuackNotify) {
            window.QuackNotify.show(message, 'error');
        } else {
            console.error(message);
        }
    }

    function clearFormLoading(form, button) {
        if (form) {
            form.classList.remove('form-loading');
        }
        if (button && window.ButtonLoadingManager) {
            window.ButtonLoadingManager.removeLoading(button);
        } else if (button) {
            button.classList.remove('btn-loading');
        }
    }

    function resolveElement(key, fallbackId) {
        if (typeof elements !== 'undefined' && elements[key]) {
            return elements[key];
        }
        return fallbackId ? document.getElementById(fallbackId) : null;
    }

    function openDeploymentForm() {
        const modal = resolveElement('deploymentCreateModal', 'deploymentCreateModal');
        if (!modal) {
            return;
        }
        openDeploymentModal(modal, document.getElementById('createSiteName'));
    }

    function closeDeploymentForm() {
        const modal = resolveElement('deploymentCreateModal', 'deploymentCreateModal');
        if (modal) {
            closeDeploymentModal(modal);
        }
        const form = resolveElement('deploymentCreateForm', 'deploymentCreateForm');
        form?.reset();
        if (elements.createConnectwiseProjectId) {
            elements.createConnectwiseProjectId.value = '';
        }
        hideCwProjectResults();
        hideLocalStoreResults();
    }

    function debounce(fn, wait) {
        let timer = null;
        return function debounced(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    }

    function hideCwProjectResults() {
        if (elements.cwProjectResults) {
            elements.cwProjectResults.hidden = true;
            elements.cwProjectResults.innerHTML = '';
        }
    }

    function hideLocalStoreResults() {
        if (elements.localStoreResults) {
            elements.localStoreResults.hidden = true;
            elements.localStoreResults.innerHTML = '';
        }
    }

    async function handleLocalStoreSearchInput() {
        const query = (elements.createSiteName?.value || '').trim();
        if (query.length < 2) {
            hideLocalStoreResults();
            return;
        }
        try {
            const params = new URLSearchParams({ action: 'local_store_search', q: query });
            const response = await fetch(`${API_ENDPOINT}?${params.toString()}`);
            const data = await response.json();
            if (!response.ok || data.success === false) {
                hideLocalStoreResults();
                return;
            }
            renderLocalStoreResults((data.data && data.data.stores) || []);
        } catch (err) {
            hideLocalStoreResults();
        }
    }

    function renderLocalStoreResults(stores) {
        if (!elements.localStoreResults) {
            return;
        }
        if (!stores.length) {
            elements.localStoreResults.innerHTML = '<li class="cw-project-results__empty">No local stores found</li>';
            elements.localStoreResults.hidden = false;
            return;
        }
        elements.localStoreResults.innerHTML = stores.map((store, index) => {
            const label = escapeHtml(store.name || store.store_number || 'Unnamed store');
            const meta = escapeHtml([store.store_number && `Store #${store.store_number}`, store.project_number && `Project #${store.project_number}`, store.address].filter(Boolean).join(' • '));
            return `<li class="cw-project-results__item" data-index="${index}" tabindex="0" role="option">
                <span class="cw-project-results__name">${label}</span>
                <span class="cw-project-results__meta">${meta}</span>
            </li>`;
        }).join('');
        elements.localStoreResults.hidden = false;
        elements.localStoreResults.querySelectorAll('.cw-project-results__item').forEach((item) => {
            const select = () => applyLocalStore(stores[Number(item.dataset.index)]);
            item.addEventListener('click', select);
            item.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    select();
                }
            });
        });
    }

    function applyLocalStore(store) {
        if (!store) {
            return;
        }
        if (elements.createStoreId) {
            elements.createStoreId.value = store.id || '';
        }
        if (elements.createSiteNumber) {
            elements.createSiteNumber.value = store.store_number || '';
        }
        if (elements.createConnectwiseProjectId) {
            elements.createConnectwiseProjectId.value = '';
        }
        if (elements.createSiteName) {
            elements.createSiteName.value = store.name || '';
        }
        if (elements.createProjectCode && store.project_number) {
            elements.createProjectCode.value = store.project_number;
        }
        if (elements.createRegion && store.region_id) {
            elements.createRegion.value = String(store.region_id);
        }
        applyLocalAddress(store.address || '');
        hideLocalStoreResults();
    }

    function applyLocalAddress(address) {
        if (elements.createAddress1) {
            elements.createAddress1.value = address;
        }
        if (!address) {
            return;
        }
        const parts = address.split(',').map((part) => part.trim()).filter(Boolean);
        if (parts.length < 3) {
            return;
        }
        if (elements.createAddress1) {
            elements.createAddress1.value = parts[0];
        }
        if (elements.createCity) {
            elements.createCity.value = parts[1] || '';
        }
        const stateZip = parts.slice(2).join(' ').match(/^([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/);
        if (stateZip) {
            if (elements.createState) {
                elements.createState.value = stateZip[1];
            }
            if (elements.createPostalCode) {
                elements.createPostalCode.value = stateZip[2];
            }
        }
    }

    async function handleCwProjectSearchInput() {
        const query = (elements.cwProjectSearch?.value || '').trim();
        if (query.length < 2) {
            hideCwProjectResults();
            return;
        }
        try {
            const params = new URLSearchParams({ action: 'cw_project_search', q: query });
            const response = await fetch(`${API_ENDPOINT}?${params.toString()}`);
            const data = await response.json();
            if (!response.ok || data.success === false) {
                hideCwProjectResults();
                return;
            }
            renderCwProjectResults((data.data && data.data.projects) || []);
        } catch (err) {
            hideCwProjectResults();
        }
    }

    function renderCwProjectResults(projects) {
        if (!elements.cwProjectResults) {
            return;
        }
        if (!projects.length) {
            elements.cwProjectResults.innerHTML = '<li class="cw-project-results__empty">No matching ConnectWise projects</li>';
            elements.cwProjectResults.hidden = false;
            return;
        }
        elements.cwProjectResults.innerHTML = projects.map((project, index) => {
            const label = escapeHtml(project.name || project.project_code || 'Untitled project');
            const meta = escapeHtml([project.company_name, project.site_city, project.site_state].filter(Boolean).join(' • '));
            return `<li class="cw-project-results__item" data-index="${index}" tabindex="0" role="option">
                <span class="cw-project-results__name">${label}</span>
                <span class="cw-project-results__meta">${meta}</span>
            </li>`;
        }).join('');
        elements.cwProjectResults.hidden = false;

        elements.cwProjectResults.querySelectorAll('.cw-project-results__item').forEach((item) => {
            const select = () => applyCwProject(projects[Number(item.dataset.index)]);
            item.addEventListener('click', select);
            item.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    select();
                }
            });
        });
    }

    function applyCwProject(project) {
        if (!project) {
            return;
        }
        if (elements.cwProjectSearch) {
            elements.cwProjectSearch.value = project.name || '';
        }
        if (elements.createConnectwiseProjectId) {
            elements.createConnectwiseProjectId.value = project.external_id || '';
        }
        if (elements.createStoreId) {
            elements.createStoreId.value = '';
        }
        if (elements.createSiteNumber) {
            elements.createSiteNumber.value = '';
        }
        if (elements.createSiteName && project.site_name) {
            elements.createSiteName.value = project.site_name;
        }
        if (elements.createProjectCode && project.project_code) {
            elements.createProjectCode.value = project.project_code;
        }
        if (elements.createAddress1) {
            elements.createAddress1.value = project.site_address1 || '';
        }
        if (elements.createAddress2) {
            elements.createAddress2.value = project.site_address2 || '';
        }
        if (elements.createCity) {
            elements.createCity.value = project.site_city || '';
        }
        if (elements.createState) {
            elements.createState.value = project.site_state || '';
        }
        if (elements.createPostalCode) {
            elements.createPostalCode.value = project.site_zip || '';
        }
        // Best-effort only: regions are named business territories (e.g. "Admin
        // Region"), not states, so this exact-text match will rarely hit today.
        // Left in case a region is ever named after its state, or regions gain
        // real state metadata later; the region select stays on its "Select
        // region" placeholder and the field remains required either way.
        if (elements.createRegion && project.site_state) {
            const stateValue = String(project.site_state).trim().toLowerCase();
            const match = Array.from(elements.createRegion.options).find(
                (opt) => opt.value && opt.textContent.trim().toLowerCase() === stateValue
            );
            if (match) {
                elements.createRegion.value = match.value;
            }
        }
        hideCwProjectResults();
    }

    async function handleDeploymentCreate(event) {
        event.preventDefault();
        const form = resolveElement('deploymentCreateForm', 'deploymentCreateForm');
        if (!form) {
            showError('Campaign form not available');
            return;
        }
        const formData = new FormData(form);
        formData.set('action', 'create');
        formData.set('csrf', config.csrf || '');
        try {
            const response = await fetch(API_ENDPOINT, { method: 'POST', body: formData });
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.error || data.message || 'Unable to create campaign');
            }
            showSuccess('Campaign created');
            closeDeploymentForm();
            fetchDeployments();
        } catch (err) {
            showError(err.message);
        }
    }

    // =====================
    // Forms integration (quest -> form campaign -> internal submit)
    // =====================

    let cachedForms = null;

    async function fetchAvailableForms() {
        if (!config.canManageFormCampaigns) {
            return [];
        }
        if (cachedForms) {
            return cachedForms;
        }
        const params = new URLSearchParams({ action: 'list_forms' });
        const response = await fetch(`${FORMS_API_ENDPOINT}?${params.toString()}`);
        const data = await response.json();
        if (!response.ok || data.success === false) {
            throw new Error(data.error || 'Unable to load forms');
        }
        cachedForms = data.data?.forms || [];
        return cachedForms;
    }

    let formsModalMode = 'submit';

    function openQuestFormsModal(mode = 'submit') {
        formsModalMode = mode;
        if (!elements.questFormsModal) {
            showError('Forms modal not available');
            return;
        }
        if (!state.currentDeploymentId) {
            showError('Select a deployment first');
            return;
        }

        // Populate select
        if (elements.questFormsSelect) {
            elements.questFormsSelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select…';
            elements.questFormsSelect.appendChild(placeholder);
        }

        // Permission: allow opening if you can manage campaigns OR submit internal forms OR view responses.
        if (!config.canManageFormCampaigns && !config.canSubmitInternalForms && !config.canViewFormResponses) {
            showError('You do not have permission to use forms.');
            return;
        }

        const viewingResponses = formsModalMode === 'responses';
        if (elements.questFormsHeading) {
            elements.questFormsHeading.textContent = viewingResponses ? 'Form responses' : 'Campaign form';
        }
        if (elements.questFormsIntro) {
            elements.questFormsIntro.textContent = viewingResponses
                ? 'Choose a form to view responses for the selected campaign.'
                : 'Choose a form to open for the selected campaign.';
        }
        if (elements.questFormsOpenBtn) {
            elements.questFormsOpenBtn.textContent = viewingResponses ? 'View responses' : 'Open form';
        }

        fetchAvailableForms()
            .then((forms) => {
                if (!elements.questFormsSelect) {
                    return;
                }
                forms.forEach((f) => {
                    const opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = `${f.title} (${f.status})`;
                    elements.questFormsSelect.appendChild(opt);
                });
            })
            .catch((err) => showError(err.message));

        openDeploymentModal(elements.questFormsModal, elements.questFormsSelect);
    }

    function closeQuestFormsModal() {
        if (elements.questFormsModal) {
            closeDeploymentModal(elements.questFormsModal);
        }
    }

    async function handleQuestFormsOpen() {
        const formId = elements.questFormsSelect ? parseInt(elements.questFormsSelect.value, 10) : NaN;
        if (!formId) {
            showError('Select a form first');
            return;
        }
        const deploymentId = Number(state.currentDeploymentId) || 0;
        if (!deploymentId) {
            showError('Select a deployment first');
            return;
        }

        try {
            const payload = new FormData();
            payload.set('action', 'resolve_campaign_for_deployment');
            payload.set('csrf', config.csrf || '');
            payload.set('form_id', String(formId));
            payload.set('deployment_id', String(deploymentId));

            const response = await fetch(FORMS_API_ENDPOINT, { method: 'POST', body: payload });
            const data = await response.json();
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Unable to load campaign');
            }

            closeQuestFormsModal();

            if (formsModalMode === 'responses') {
                const campaignId = Number(data.data?.campaign_id) || 0;
                if (!campaignId) {
                    throw new Error('Campaign not found');
                }
                window.location.href = `/form_responses?campaign_id=${campaignId}`;
                return;
            }

            const redirect = data.data?.redirect;
            if (redirect) {
                window.location.href = redirect;
            }
        } catch (err) {
            showError(err.message);
        }
    }

    async function openFormResponses() {
        if (!config.canViewFormResponses) {
            showError('You do not have permission to view responses.');
            return;
        }
        openQuestFormsModal('responses');
    }
})();
