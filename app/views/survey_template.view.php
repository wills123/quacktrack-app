<?php
// @var bool $canManageTemplates
$canManageTemplates = $canManageTemplates ?? false;

require __DIR__ . '/header.php';
?>
<script>
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
  var m = document.querySelector('.app-main');
  if (m) m.classList.add('od-active');
}());
</script>

<div class="svx-page survey-page" data-survey-view="template">

  <!-- ── Page header ──────────────────────────────────────────── -->
  <div class="page-masthead svx-header">
    <div class="svx-header__inner">
      <div class="svx-header__brand">
        <h1 class="svx-header__title">Survey Template</h1>
        <p class="svx-header__sub">Manage categories, questions, and scoring guidance</p>
      </div>
      <div class="masthead-tabs svx-header__actions">
        <a href="/surveys" class="masthead-tab" id="surveyBackToList">← Back to Surveys</a>
        <button type="button" class="masthead-tab" id="surveyPublishVersion" hidden>Publish Version</button>
      </div>
    </div>
  </div>

  <!-- ── Content area ─────────────────────────────────────────── -->
  <div class="svx-content">

    <!-- No template notice -->
    <div id="surveyNoTemplate" class="svx-notice svx-notice--info" style="display:none;">
      No survey template exists yet.
      <button type="button" class="svx-btn svx-btn--primary svx-btn--sm" id="surveyCreateTemplate">Create Template</button>
    </div>

    <!-- Template editor (shown by JS) -->
    <div id="surveyTemplateEditor" class="survey-template-editor" style="display:none;">

      <!-- Template info card -->
      <div class="svx-template-info-card">
        <div class="svx-template-info-card__header">
          <span class="svx-template-info-card__label">Template Info</span>
        </div>
        <div class="svx-template-info-card__body">
          <div class="svx-field-row">
            <div class="svx-field">
              <label class="svx-label" for="surveyTemplateTitle">Title</label>
              <input type="text" id="surveyTemplateTitle" class="svx-input" placeholder="e.g. IT Standards Survey">
            </div>
            <div class="svx-field svx-field--wide">
              <label class="svx-label" for="surveyTemplateDescription">Description <span class="svx-label-note">(optional)</span></label>
              <textarea id="surveyTemplateDescription" class="svx-textarea" rows="2" placeholder="Describe the purpose of this template..."></textarea>
            </div>
          </div>
          <div class="svx-template-info-card__actions">
            <button type="button" class="svx-btn svx-btn--ghost svx-btn--sm" id="surveySaveTemplateInfo">Save Info</button>
          </div>
        </div>
      </div>

      <!-- Categories list (populated by JS) -->
      <div id="surveyCategoriesList" class="survey-template-categories"></div>

      <!-- Add category -->
      <button type="button" class="svx-btn svx-btn--ghost" id="surveyAddCategory">+ Add Category</button>

    </div>

    <!-- Version history (populated by JS) -->
    <div id="surveyVersionHistory" style="display:none;"></div>

  </div>
</div>

<script>
  window.SURVEY_PAGE = {
    canManageTemplates: <?= json_encode($canManageTemplates) ?>
  };
</script>
<script src="/assets/pages/surveys.js"></script>

<?php require __DIR__ . '/footer.php'; ?>
