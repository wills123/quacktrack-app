<?php
// @var bool $canCompare
$canCompare = $canCompare ?? false;

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
<?php
$managerSubId = (int)($_GET['manager_id'] ?? 0);
$techSubId    = (int)($_GET['tech_id'] ?? 0);
?>

<div class="svx-page survey-page" data-survey-view="compare" data-manager-id="<?= $managerSubId ?>" data-tech-id="<?= $techSubId ?>">

  <div class="page-masthead svx-header">
    <div class="svx-header__inner">
      <div class="svx-header__brand">
        <h1 class="svx-header__title">Survey Comparison</h1>
        <p class="svx-header__sub">Manager vs. Tech assessment side-by-side</p>
      </div>
      <div class="masthead-tabs svx-header__actions">
        <a href="/surveys?view=detail&id=<?= $managerSubId ?>" class="masthead-tab">← Back to Detail</a>
        <button type="button" class="masthead-tab" id="btnChangeTech">Change Tech Survey</button>
      </div>
    </div>
  </div>

  <div class="svx-content">
    <div id="surveyCompareContainer" class="survey-comparison">
      <div class="survey-detail__loading">Loading comparison…</div>
    </div>
  </div>

</div>

<!-- Tech selection modal -->
<div id="techSelectModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Select Tech Survey</h2>
      <button class="close-button" type="button" aria-label="Close modal">&times;</button>
    </div>
    <div class="modal-body">
      <div id="techSelectList" class="survey-tech-select-list"></div>
    </div>
  </div>
</div>

<script>
  window.SURVEY_PAGE = {
    canCompare: <?= json_encode($canCompare) ?>
  };
</script>
<script src="/assets/pages/surveys.js"></script>

<?php require __DIR__ . '/footer.php'; ?>
