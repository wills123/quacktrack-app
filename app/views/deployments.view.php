<?php
/** @var array $regions */
/** @var array $stageOptions */
/** @var array $taskStatusOptions */
/** @var bool $canManageDeployments */
/** @var bool $canCustomizeTasks */
$regions = $regions ?? [];
$canManageFormCampaigns = $canManageFormCampaigns ?? false;
$canSubmitInternalForms = $canSubmitInternalForms ?? false;
$canManageForms = $canManageForms ?? false;
$canExportDeployments = $canExportDeployments ?? false;
$canRequestShipment = $canRequestShipment ?? false;
$canApproveShipment = $canApproveShipment ?? false;
$canReceiveShipment = $canReceiveShipment ?? false;
$canDispatchShipment = $canDispatchShipment ?? false;
$questStatuses = ['available','in_progress','blocked','ready_review','complete','archived'];
$currentUserId = (int)($_SESSION['uid'] ?? 0);
$currentUserEmail = $_SESSION['email'] ?? '';
$canQuestEdit = $canManageDeployments || $canCustomizeTasks;
$extra_styles = ['/assets/deployments-quest.css', '/assets/pages/deployment-shipments.css'];
$extra_scripts = ['/assets/deployments.js', '/assets/pages/deployment-shipments.js'];

require __DIR__ . '/header.php';
?>

<div class="deployments-page">
  <div class="page-masthead svx-header">
    <div class="svx-header__inner">
      <div class="svx-header__brand">
        <h1 class="svx-header__title">Deployments</h1>
        <p class="svx-header__sub">Campaign quests and equipment shipments</p>
      </div>
      <div class="masthead-tabs svx-header__actions deployments-view-tabs" role="tablist" aria-label="Deployment view">
        <button type="button" role="tab" class="masthead-tab is-active" id="deploymentQuestsTab" aria-controls="deploymentQuestsWorkspace" aria-selected="true">Quests</button>
        <button type="button" role="tab" class="masthead-tab" id="deploymentShipmentsTab" aria-controls="deploymentShipmentsWorkspace" aria-selected="false">Shipments</button>
      </div>
    </div>
  </div>

  <input type="hidden" id="boardDeployment" name="deployment_id">

  <?php if ($canCustomizeTasks || $canManageDeployments || $canManageFormCampaigns || $canSubmitInternalForms || $canExportDeployments) : ?>
    <nav class="deployment-module-bar" aria-label="Campaign tools">
      <span class="deployment-module-bar__label">Campaign tools</span>
      <div class="deployment-module-bar__actions">
        <?php if ($canCustomizeTasks) : ?>
          <button type="button" class="deployment-module" id="deploymentCreateBtn"><span aria-hidden="true">+</span> New campaign</button>
        <?php endif; ?>
        <?php if ($canManageDeployments) : ?>
          <button type="button" class="deployment-module" id="openTemplateLibrary"><span aria-hidden="true">◇</span> Templates</button>
        <?php endif; ?>
        <?php if ($canManageFormCampaigns || $canSubmitInternalForms) : ?>
          <button type="button" class="deployment-module" id="openQuestForms"><span aria-hidden="true">≡</span> Forms</button>
          <button type="button" class="deployment-module" id="openFormResponses"><span aria-hidden="true">↗</span> Responses</button>
        <?php endif; ?>
        <?php if ($canExportDeployments) : ?>
          <button type="button" class="deployment-module" id="deploymentExportBtn"><span aria-hidden="true">⇩</span> Export</button>
        <?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>

  <div id="deploymentQuestsWorkspace" class="deployment-workspace" role="tabpanel" aria-labelledby="deploymentQuestsTab">
    <div class="quest-scroll">
      <aside class="campaign-ledger">
        <header class="ledger-header">
          <p class="ledger-kicker">Campaign ledger</p>
          <h2>Campaigns</h2>
          <p class="ledger-subtitle">Select a store deployment</p>
        </header>
        <div id="deploymentLedger" class="ledger-list">
          <p class="text-muted">Loading campaigns…</p>
        </div>
      </aside>

      <section class="quest-board-panel">
        <header class="quest-board-header">
          <div class="quest-board-heading">
            <p class="quest-board-kicker">Campaign quest log</p>
            <h2 id="questBoardTitle">Quest board</h2>
            <p id="questBoardContext">Select a campaign to view its work.</p>
          </div>
          <div class="quest-board-utilities">
            <div class="quest-campaign-progress" id="questCampaignProgress" hidden>
              <div class="quest-campaign-progress__label">
                <span>Campaign progress</span>
                <strong id="questCampaignProgressValue">0%</strong>
              </div>
              <progress id="questCampaignProgressBar" value="0" max="100" aria-label="Campaign progress"></progress>
            </div>
            <div class="deployment-board-controls">
              <label for="questStatusFilter">Status</label>
              <select id="questStatusFilter" name="quest_status">
                <option value="">All quests</option>
              </select>
            </div>
          </div>
        </header>
        <div id="questBoard" class="quest-board" aria-live="polite">
          <p class="text-muted">Select a campaign to load its quest board.</p>
        </div>
      </section>
    </div>

    <section class="quest-detail-panel" id="questDetailPanel" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="questTitle">
      <button type="button" class="quest-detail-scrim" id="questDetailScrim" tabindex="-1" aria-label="Close quest details"></button>
      <div class="quest-detail-card">
        <header class="quest-detail-header">
          <div>
            <p class="quest-status" id="questStatusLabel"></p>
            <h2 id="questTitle" tabindex="-1">Select a quest</h2>
            <p id="questMeta" class="detail-subtitle"></p>
            <p id="questAssignment" class="quest-assignment"></p>
            <div id="questActionBar" class="quest-action-bar"></div>
          </div>
          <div class="detail-actions">
            <?php if ($canManageDeployments) : ?>
              <button type="button" class="btn-secondary" id="questStatusBtn">Change Status</button>
            <?php endif; ?>
            <button type="button" class="btn-link" id="questDetailClose">Close</button>
          </div>
        </header>
        <div id="questDetailBody" class="quest-detail-body">
          <p class="text-muted">Choose a quest card to view narrative, objectives, and logs.</p>
        </div>
      </div>
    </section>
  </div>

  <section id="deploymentShipmentsWorkspace" class="deployment-shipments" role="tabpanel" aria-labelledby="deploymentShipmentsTab" hidden>
    <header class="deployment-shipments__header">
      <div>
        <p class="deployment-shipments__eyebrow">Deployment logistics</p>
        <h2>Equipment shipments</h2>
        <p id="deploymentShipmentsContext" class="deployment-shipments__context">Select a campaign to view its shipments.</p>
      </div>
      <?php if ($canRequestShipment) : ?>
        <button type="button" class="btn-primary" id="deploymentShipmentsRequestBtn">Request shipment</button>
      <?php endif; ?>
    </header>
    <div class="deployment-shipments__layout">
      <div class="deployment-shipments__list-pane">
        <div id="deploymentShipmentStatusFilters" class="deployment-shipments__filters" aria-label="Shipment status filters"></div>
        <div id="deploymentShipmentsList" class="deployment-shipments__list" aria-live="polite">
          <p class="text-muted">Select a campaign to view shipments.</p>
        </div>
      </div>
      <div id="deploymentShipmentDetail" class="deployment-shipments__detail" tabindex="-1" aria-live="polite">
        <p class="text-muted">Choose a shipment to view its details.</p>
      </div>
    </div>
  </section>

  <div class="modal deployment-modal-shell quest-blocker-modal" id="questBlockerModal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="quest-blocker-modal__backdrop"></div>
    <div class="modal-content deployment-dialog deployment-dialog--compact quest-blocker-modal__content" role="document">
      <header class="deployment-dialog__header">
        <div>
          <p class="deployment-dialog__eyebrow">Quest status</p>
          <h3>Mark quest as blocked</h3>
          <p>Record what is stopping the work.</p>
        </div>
        <button type="button" class="close-button" id="blockerCloseBtn" aria-label="Close blocker form">&times;</button>
      </header>
      <div class="deployment-dialog__body form-field">
        <label for="blockerReasonInput">Blocker details<span class="text-danger">*</span></label>
        <textarea id="blockerReasonInput" rows="3" placeholder="Example: Waiting on electrical contractor to energize rack."></textarea>
      </div>
      <div class="modal-actions deployment-dialog__footer">
        <button type="button" class="btn-link" id="blockerCancelBtn">Cancel</button>
        <button type="button" class="btn-primary" id="blockerSubmitBtn">Save Blocker</button>
      </div>
    </div>
  </div>
</div>

<?php if ($canManageDeployments) : ?>
<div id="questFormModal" class="modal deployment-modal-shell" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content quest-modal deployment-dialog">
    <div class="modal-header-custom deployment-dialog__header">
      <div>
        <p class="deployment-dialog__eyebrow">Campaign work</p>
        <h2>Create Quest</h2>
      </div>
      <button type="button" class="close-button" id="questFormClose" aria-label="Close quest form">&times;</button>
    </div>
    <form id="questForm" class="quest-form">
      <input type="hidden" name="deployment_id" id="questFormDeployment">
      <div class="deployment-dialog__form-body">
        <div class="form-grid">
          <div class="form-field">
            <label for="questTitleInput">Title<span class="text-danger">*</span></label>
            <input type="text" id="questTitleInput" name="title" required>
          </div>
          <div class="form-field">
            <label for="questDueInput">Due date</label>
            <input type="date" id="questDueInput" name="due_date">
          </div>
          <div class="form-field">
            <label for="questAssigneeInput">Assign to (user id)</label>
            <input type="number" id="questAssigneeInput" name="assigned_user_id">
          </div>
          <div class="form-field">
            <label for="questPriorityInput">Priority</label>
            <input type="number" id="questPriorityInput" name="priority" value="0">
          </div>
        </div>
        <div class="form-field">
          <label for="questBriefInput">Narrative</label>
          <textarea id="questBriefInput" name="brief" rows="4"></textarea>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-link" id="questFormCancel">Cancel</button>
        <button type="submit" class="btn-primary">Create Quest</button>
      </div>
    </form>
  </div>
</div>

<div id="templateLibraryModal" class="modal deployment-modal-shell" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content quest-modal deployment-dialog deployment-dialog--wide">
    <div class="modal-header-custom deployment-dialog__header">
      <div>
        <p class="deployment-dialog__eyebrow">Reusable work</p>
        <h2>Quest Templates</h2>
      </div>
      <button type="button" class="close-button" id="templateLibraryClose" aria-label="Close">&times;</button>
    </div>
    <div class="template-library deployment-dialog__body" id="templateLibrary"></div>
    <div class="modal-actions deployment-dialog__footer">
      <button type="button" class="btn-secondary" id="templateCreateBtn">New Template</button>
      <button type="button" class="btn-link" id="templateLibraryCancel">Close</button>
    </div>
  </div>
</div>

<div id="templateFormModal" class="modal deployment-modal-shell" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content quest-modal deployment-dialog">
    <div class="modal-header-custom deployment-dialog__header">
      <div>
        <p class="deployment-dialog__eyebrow">Quest template</p>
        <h2 id="templateFormHeading">Create Template</h2>
      </div>
      <button type="button" class="close-button" id="templateFormClose" aria-label="Close">&times;</button>
    </div>
    <form id="templateForm" class="quest-form">
      <input type="hidden" name="template_id" id="templateIdInput">
      <div class="deployment-dialog__form-body">
        <div class="form-field">
          <label for="templateTitleInput">Title<span class="text-danger">*</span></label>
          <input type="text" id="templateTitleInput" name="title" required>
        </div>
        <div class="form-field">
          <label for="templateBriefInput">Brief</label>
          <textarea id="templateBriefInput" name="brief" rows="4"></textarea>
        </div>
        <div class="form-grid">
          <div class="form-field">
            <label for="templateTagsInput">Tags</label>
            <input type="text" id="templateTagsInput" name="default_tags" placeholder="Comma separated tags">
            <p class="field-hint">Example: dark_week,prep</p>
          </div>
          <div class="form-field">
            <label for="templateRoleInput">Recommended Role</label>
            <input type="text" id="templateRoleInput" name="recommended_role" placeholder="e.g., DS Lead">
          </div>
        </div>
        <div class="form-field">
          <label for="templateAssignInput">Auto Assign Role</label>
          <input type="text" id="templateAssignInput" name="auto_assign_role" placeholder="Optional: role to auto assign">
        </div>
        <div class="form-field">
          <label>Objectives<span class="text-danger">*</span></label>
          <div id="templateObjectivesList" class="template-objectives"></div>
          <button type="button" class="btn-secondary" id="templateAddObjective">Add Objective</button>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-link" id="templateFormCancel">Cancel</button>
        <button type="submit" class="btn-primary" id="templateSubmitBtn">Save Template</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div id="questFormsModal" class="modal deployment-modal-shell" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content quest-modal deployment-dialog deployment-dialog--compact">
    <div class="modal-header-custom deployment-dialog__header">
      <div>
        <p class="deployment-dialog__eyebrow">Campaign records</p>
        <h2 id="questFormsHeading">Campaign form</h2>
      </div>
      <button type="button" class="close-button" id="questFormsClose" aria-label="Close">&times;</button>
    </div>

    <div class="quest-forms-modal-body deployment-dialog__body">
      <p class="text-muted quest-forms-modal-intro" id="questFormsIntro">Choose a form for the selected campaign.</p>
      <div class="form-field">
        <label for="questFormsSelect">Form</label>
        <select id="questFormsSelect"></select>
      </div>
    </div>
    <div class="modal-actions deployment-dialog__footer">
      <button type="button" class="btn-link" id="questFormsCancel">Cancel</button>
      <button type="button" class="btn-primary" id="questFormsOpenBtn">Open form</button>
    </div>
  </div>
</div>

<?php if ($canCustomizeTasks) : ?>
<div id="deploymentCreateModal" class="modal deployment-modal-shell" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="modal-content deployment-modal deployment-dialog">
    <div class="modal-header-custom deployment-dialog__header">
      <div>
        <p class="deployment-dialog__eyebrow">Deployment setup</p>
        <h2>New Campaign</h2>
      </div>
      <button type="button" class="close-button" id="deploymentCreateClose" aria-label="Close new campaign form">&times;</button>
    </div>
    <form id="deploymentCreateForm" class="deployment-create-form">
      <div class="deployment-dialog__form-body">
        <div class="form-grid">
          <?php /* Optional lookup: searches ConnectWise projects via QuickQueue
                   (assets/deployments.js handleCwProjectSearchInput/applyCwProject)
                   and pre-fills the fields below. Search input has no `name` —
                   it's not part of the create payload; only the hidden
                   connectwise_project_id and the fields it fills are submitted. */ ?>
          <div class="form-field form-field--full">
            <label for="cwProjectSearch">Look up a ConnectWise project</label>
            <input type="text" id="cwProjectSearch" autocomplete="off" placeholder="Search by project name or ID&hellip;">
            <ul id="cwProjectResults" class="cw-project-results" hidden></ul>
            <p class="field-hint">Optional &mdash; select a match to pre-fill the fields below, or fill them in by hand.</p>
          </div>
          <input type="hidden" id="createConnectwiseProjectId" name="connectwise_project_id">
          <input type="hidden" id="createStoreId" name="store_id">
          <input type="hidden" id="createSiteNumber" name="site_number">
          <div class="form-field form-field--lookup">
            <label for="createSiteName">Site Name<span class="text-danger">*</span></label>
            <input type="text" id="createSiteName" name="site_name" autocomplete="off" placeholder="Search by SIR name, Store #, or Project Number" required>
            <ul id="localStoreResults" class="cw-project-results" hidden></ul>
            <p class="field-hint">Search local SIR stores, or enter a campaign manually.</p>
          </div>
          <div class="form-field">
            <label for="createProjectCode">Project Code<span class="text-danger">*</span></label>
            <input type="text" id="createProjectCode" name="project_code" required>
          </div>
          <div class="form-field">
            <label for="createRegion">Region<span class="text-danger">*</span></label>
            <select id="createRegion" name="region_id" required>
              <option value="">Select region</option>
              <?php foreach ($regions as $region) : ?>
                <option value="<?= (int)$region['id'] ?>"><?= h($region['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label for="createAddress1">Address Line 1</label>
            <input type="text" id="createAddress1" name="address_line1">
          </div>
          <div class="form-field">
            <label for="createAddress2">Address Line 2</label>
            <input type="text" id="createAddress2" name="address_line2">
          </div>
          <div class="form-field">
            <label for="createCity">City</label>
            <input type="text" id="createCity" name="city">
          </div>
          <div class="form-field">
            <label for="createState">State</label>
            <input type="text" id="createState" name="state">
          </div>
          <div class="form-field">
            <label for="createPostalCode">Postal Code</label>
            <input type="text" id="createPostalCode" name="postal_code">
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-link" id="deploymentCreateCancel">Cancel</button>
        <button type="submit" class="btn-primary">Create Campaign</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
window.DEPLOYMENT_PAGE = {
  csrf: <?= json_encode(csrf_token()) ?>,
  stages: <?= json_encode($stageOptions) ?>,
  taskStatuses: <?= json_encode($taskStatusOptions) ?>,
  canManage: <?= $canManageDeployments ? 'true' : 'false' ?>,
  canCustomize: <?= $canCustomizeTasks ? 'true' : 'false' ?>,
  canQuestEdit: <?= $canQuestEdit ? 'true' : 'false' ?>,
  canExport: <?= $canExportDeployments ? 'true' : 'false' ?>,
  canManageForms: <?= $canManageForms ? 'true' : 'false' ?>,
  canManageFormCampaigns: <?= $canManageFormCampaigns ? 'true' : 'false' ?>,
  canSubmitInternalForms: <?= $canSubmitInternalForms ? 'true' : 'false' ?>,
  canViewFormResponses: <?= has_permission('forms.view_responses') ? 'true' : 'false' ?>,
  canRequestShipment: <?= $canRequestShipment ? 'true' : 'false' ?>,
  canApproveShipment: <?= $canApproveShipment ? 'true' : 'false' ?>,
  canReceiveShipment: <?= $canReceiveShipment ? 'true' : 'false' ?>,
  canDispatchShipment: <?= $canDispatchShipment ? 'true' : 'false' ?>,
  questStatuses: <?= json_encode($questStatuses) ?>,
  currentUserId: <?= json_encode($currentUserId) ?>,
  currentUserEmail: <?= json_encode($currentUserEmail) ?>
};
</script>
<?php require __DIR__ . '/footer.php'; ?>
