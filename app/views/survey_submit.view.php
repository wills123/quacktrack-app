<?php
// @var array $regions
// @var array $stores
// @var bool $canSubmit
// @var bool $canManageTemplates
// @var bool $canCompare
$stores = $stores ?? [];
$canSubmit = $canSubmit ?? false;
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
$submissionId = (int)($_GET['submission_id'] ?? 0);
$storeId = (int)($_GET['store_id'] ?? 0);

// Look up store name
$storeName = '';
foreach ($stores as $store) {
    if ((int)$store['id'] === $storeId) {
        $storeName = $store['name'];
        break;
    }
}

// Submitter role selection
$canSubmitAsManager = has_permission('surveys.templates.manage');
// Default role (user can pick if they have permission)
$defaultSubmitterRole = 'tech';
$requestedSubmitterRole = $_GET['role'] ?? $defaultSubmitterRole;
$submitterRole = $canSubmitAsManager && $requestedSubmitterRole === 'manager' ? 'manager' : 'tech';
$userName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($userName === '') {
    $userName = $_SESSION['username'] ?? 'User';
}
?>

<div class="svx-page survey-page" data-survey-view="submit" data-submission-id="<?= $submissionId ?>" data-store-id="<?= $storeId ?>">

  <!-- Loading state (shown while survey initialises) -->
  <div id="surveyLoading" class="survey-loading">
    <div class="survey-loading__card">
      <div class="survey-loading__spinner"></div>
      <h2 class="survey-loading__title"><?= $submissionId > 0 ? 'Loading your draft...' : 'Preparing survey...' ?></h2>
      <div class="survey-loading__meta">
        <?php if ($storeName) : ?>
          <span class="survey-loading__store"><?= h($storeName) ?></span>
        <?php endif; ?>
        <span id="surveyLoadingRole" class="survey-loading__role survey-loading__role--<?= $submitterRole ?>"><?= $submitterRole === 'manager' ? 'Manager Review' : 'Tech Review' ?></span>
      </div>
    </div>
  </div>

  <!-- Survey form (rendered by JS after API response) -->
  <div id="surveySubmitContainer" class="survey-submit-container" style="display:none;"></div>

</div>

<script>
  window.SURVEY_PAGE = {
    canSubmit: <?= json_encode($canSubmit) ?>,
    canSubmitAsManager: <?= json_encode($canSubmitAsManager) ?>,
    canCompare: <?= json_encode($canCompare) ?>,
    submitterRole: <?= json_encode($submitterRole) ?>,
    allowRoleChoice: <?= json_encode($canSubmitAsManager && $submissionId <= 0) ?>,
    storeName: <?= json_encode($storeName) ?>,
    userName: <?= json_encode($userName) ?>
  };
</script>
<script src="/assets/pages/surveys.js"></script>

<?php require __DIR__ . '/footer.php'; ?>
