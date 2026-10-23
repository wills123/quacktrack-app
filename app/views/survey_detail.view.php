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
$submissionId = (int)($_GET['submission_id'] ?? $_GET['id'] ?? 0);
?>

<div class="svx-page survey-page" data-survey-view="detail" data-submission-id="<?= $submissionId ?>">

  <div class="page-masthead svx-header">
    <div class="svx-header__inner">
      <div class="svx-header__brand">
        <h1 class="svx-header__title">Survey Detail</h1>
        <p class="svx-header__sub" id="detailSubtitle"></p>
      </div>
      <div class="masthead-tabs svx-header__actions" id="detailActions">
        <a href="/surveys" class="masthead-tab">← Back to Surveys</a>
      </div>
    </div>
  </div>

  <div class="svx-content">
    <div id="surveyDetailContainer" class="survey-detail">
      <div class="survey-detail__loading">Loading survey…</div>
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
