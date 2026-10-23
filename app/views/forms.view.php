<?php
$forms = $forms ?? [];
$tab = $tab ?? 'active';
$canManageCampaigns = $canManageCampaigns ?? false;
$activeFormId = $activeFormId ?? null;
$formQuestions = $formQuestions ?? [];
$deploymentProjects = $deploymentProjects ?? [];
$canManageForms = $canManageForms ?? false;
$canViewResponses = $canViewResponses ?? false;
require __DIR__ . '/header.php';
?>
<script>
/* Anti-flash: apply saved theme + signal od-page is active (hides legacy topbar) */
(function(){
  var t = localStorage.getItem('kit_theme');
  if (t === 'dark' || (t === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.setAttribute('data-kit-theme', 'dark');
  }
  /* Fallback for browsers without CSS :has() — add od-active to .app-main */
  var main = document.querySelector('.app-main');
  if (main) main.classList.add('od-active');
}());
</script>

<?php
// ── Flash messages ────────────────────────────────────────────────────────
foreach (flashes() as [$type, $msg]) :
    $cls = $type === 'error' ? 'od-notice--warn' : 'od-notice--info';
    ?>
<div class="od-notice <?= $cls ?>" style="border-radius:0;margin:0;">
    <?= h($msg) ?>
</div>
<?php endforeach; ?>

<?php
// ── Stat counts ───────────────────────────────────────────────────────────
$totalForms     = count($forms);
$publishedForms = count(array_filter($forms, fn($f) => strtolower(trim((string)$f['status'])) === 'published'));
$draftForms     = count(array_filter($forms, fn($f) => strtolower(trim((string)$f['status'])) === 'draft'));
?>

<div class="od-page">

<!-- ── STICKY HEADER ──────────────────────────────────────────────────── -->
<div class="od-header-wrap">

  <div class="page-masthead od-masthead">
    <div class="od-masthead-inner">

      <div class="od-masthead-brand">
        <h1 class="od-masthead-title">Forms</h1>
      </div>

      <div class="od-masthead-right">
        <nav class="masthead-tabs" role="tablist">
          <a href="/forms?tab=forms" class="masthead-tab <?= $tab === 'forms' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'forms' ? 'true' : 'false' ?>">Forms</a>
          <?php if ($canManageCampaigns) : ?>
          <a href="/forms?tab=campaigns" class="masthead-tab <?= $tab === 'campaigns' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'campaigns' ? 'true' : 'false' ?>">Campaigns</a>
          <?php endif; ?>
        </nav>
      </div>

    </div>
  </div>

  <div class="page-stat-rail od-stat-rail">
    <?php if ($tab === 'forms') : ?>
      <div class="od-stat">
        <div>
          <div class="od-stat-value"><?= $totalForms ?></div>
          <div class="od-stat-label">Total Forms</div>
        </div>
      </div>
      <div class="od-stat">
        <div>
          <div class="od-stat-value"><?= $publishedForms ?></div>
          <div class="od-stat-label">Published</div>
        </div>
      </div>
      <div class="od-stat">
        <div>
          <div class="od-stat-value"><?= $draftForms ?></div>
          <div class="od-stat-label">Drafts</div>
        </div>
      </div>
        <?php if ($activeFormId > 0) : ?>
      <div class="od-stat">
        <div>
          <div class="od-stat-value"><?= count($formQuestions) ?></div>
          <div class="od-stat-label">Questions</div>
        </div>
      </div>
        <?php endif; ?>
    <?php else : ?>
      <div class="od-stat">
        <div>
          <div class="od-stat-value"><?= $totalForms ?></div>
          <div class="od-stat-label">Available Forms</div>
        </div>
      </div>
      <div class="od-stat">
        <div>
          <div class="od-stat-value"><?= count($deploymentProjects) ?></div>
          <div class="od-stat-label">Deployments</div>
        </div>
      </div>
    <?php endif; ?>
  </div>

</div><!-- /.od-header-wrap -->

<!-- ── BODY ───────────────────────────────────────────────────────────── -->
<div class="od-body">

<?php if ($tab === 'forms') : ?>
<!-- ================================================================== -->
<!-- FORMS TAB                                                           -->
<!-- ================================================================== -->
<div class="od-layout">

  <aside class="od-sidebar">
    <?php if ($canManageForms) : ?>
      <button type="button" class="od-sidebar-new" id="frmSidebarNewBtn">+ New Form</button>
    <?php endif; ?>
    <div class="od-sidebar-hdr">Forms</div>

    <?php if (empty($forms)) : ?>
      <div class="od-empty">
        <div class="od-empty-msg">No forms yet</div>
        <div class="od-empty-sub">Create your first form above</div>
      </div>
    <?php else : ?>
      <ul class="od-list" role="list">
        <?php foreach ($forms as $f) :
            $fStatus   = strtolower(trim((string)$f['status']));
            $isActive  = (int)$f['id'] === $activeFormId;
            ?>
          <li class="od-list-item <?= $isActive ? 'od-list-item--active' : '' ?>">
            <a href="/forms?tab=forms&form_id=<?= (int)$f['id'] ?>" class="od-list-link">
              <span class="od-list-name"><?= h($f['title']) ?></span>
              <span class="od-list-meta">
                <span class="od-badge od-badge--<?= $fStatus === 'published' ? 'published' : ($fStatus === 'archived' ? 'archived' : 'draft') ?>"><?= ucfirst($fStatus) ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>

  <section class="od-detail">

    <?php if (!$activeFormId) : ?>
        <?php if ($canManageForms) : ?>
        <!-- New form card shown when nothing is selected -->
        <div class="od-card">
          <div class="od-card-header">
            <div>
              <div class="od-card-title">Create a Form</div>
              <div class="od-card-sub">Build a progress report or survey, then run campaigns tied to deployments</div>
            </div>
          </div>
          <div class="od-card-body">
            <form method="post" id="frmCreateForm">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="create_form">
              <div class="od-form-group">
                <label class="od-label" for="frm-title">Title</label>
                <input class="od-input" id="frm-title" type="text" name="title" required maxlength="190" placeholder="Give your form a name">
              </div>
              <div class="od-form-group">
                <label class="od-label" for="frm-template">
                  Copy from template <span class="od-label-note">(optional)</span>
                </label>
                <select class="od-select" id="frm-template" name="template_form_id">
                  <option value="0">None — start blank</option>
                  <?php foreach ($forms as $tf) : ?>
                    <option value="<?= (int)$tf['id'] ?>"><?= h($tf['title']) ?> (#<?= (int)$tf['id'] ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="od-form-group">
                <label class="od-label" for="frm-desc">
                  Description <span class="od-label-note">(optional)</span>
                </label>
                <textarea class="od-textarea" id="frm-desc" name="description" rows="2" placeholder="Describe the purpose of this form"></textarea>
              </div>
              <div class="od-form-row">
                <button class="od-btn od-btn--primary" type="submit">Create Form</button>
              </div>
            </form>
          </div>
        </div>
        <?php else : ?>
        <div class="od-detail-empty">
          <div class="od-detail-empty-eyebrow">Forms</div>
          <div class="od-detail-empty-msg">Select a form from the sidebar</div>
          <div class="od-detail-empty-sub">Click any form on the left to view its questions</div>
        </div>
        <?php endif; ?>

    <?php else : ?>
        <?php
        $activeForm = null;
        foreach ($forms as $f) {
            if ((int)$f['id'] === $activeFormId) {
                $activeForm = $f;
                break;
            }
        }
        $fStatus = $activeForm ? strtolower(trim((string)$activeForm['status'])) : 'draft';
        $editId = (int)($_GET['edit_question_id'] ?? 0);
        $editRow = null;
        $editOptionsLines = '';
        $editOptions = [];
        if ($editId > 0) {
            foreach ($formQuestions as $fq) {
                if ((int)$fq['id'] === $editId) {
                    $editRow = $fq;
                    break;
                }
            }
            if ($editRow && !empty($editRow['options_json'])) {
                $decoded = json_decode((string)$editRow['options_json'], true);
                if (is_array($decoded)) {
                    $editOptions = array_map('strval', $decoded);
                    $editOptionsLines = implode("\n", $editOptions);
                }
            }
        }
        ?>

      <!-- Form header card -->
      <div class="od-card">
        <div class="od-card-header">
          <div>
            <div class="od-card-title"><?= h($activeForm['title'] ?? 'Form #' . $activeFormId) ?></div>
            <div class="od-card-sub">
              <span class="od-badge od-badge--<?= $fStatus === 'published' ? 'published' : ($fStatus === 'archived' ? 'archived' : 'draft') ?>"><?= ucfirst($fStatus) ?></span>
              &middot;
              <?= count($formQuestions) ?> question<?= count($formQuestions) !== 1 ? 's' : '' ?>
              &middot;
              Updated <?= h((string)($activeForm['updated_at'] ?? '')) ?>
            </div>
          </div>
          <div class="od-card-actions">
            <?php if ($canViewResponses) : ?>
              <a href="/form_responses?form_id=<?= $activeFormId ?>" class="od-btn od-btn--ghost od-btn--sm">View Responses</a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Question editor -->
        <?php if ($canManageForms) : ?>
      <div class="frm-editor od-card">
        <div class="od-dark-head">
          <h3 class="od-dark-head-title"><?= $editRow ? '&#9998; Edit Question' : '+ Add Question' ?></h3>
            <?php if ($editRow) : ?>
            <a class="od-btn od-btn--ghost-inv od-btn--sm" href="/forms?tab=forms&form_id=<?= $activeFormId ?>">Cancel</a>
            <?php endif; ?>
        </div>
        <div class="od-card-body">
          <form id="questionEditorForm" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update_question' : 'add_question' ?>">
            <input type="hidden" name="form_id" value="<?= $activeFormId ?>">
            <?php if ($editRow) : ?>
              <input type="hidden" name="question_id" value="<?= (int)$editRow['id'] ?>">
            <?php endif; ?>

            <div class="od-form-grid">
              <div class="od-form-group">
                <label class="od-label" for="q-type">Type</label>
                <?php $currentType = $editRow ? (string)$editRow['type'] : 'short_text'; ?>
                <select class="od-select" id="q-type" name="type" required>
                  <option value="short_text"    <?= $currentType === 'short_text'    ? 'selected' : '' ?>>Short text</option>
                  <option value="long_text"     <?= $currentType === 'long_text'     ? 'selected' : '' ?>>Long text</option>
                  <option value="number"        <?= $currentType === 'number'        ? 'selected' : '' ?>>Number</option>
                  <option value="date"          <?= $currentType === 'date'          ? 'selected' : '' ?>>Date</option>
                  <option value="yes_no"        <?= $currentType === 'yes_no'        ? 'selected' : '' ?>>Yes / No</option>
                  <option value="single_choice" <?= $currentType === 'single_choice' ? 'selected' : '' ?>>Single choice</option>
                  <option value="multi_choice"  <?= $currentType === 'multi_choice'  ? 'selected' : '' ?>>Multi choice</option>
                </select>
              </div>
              <div class="od-form-group">
                <label class="od-label" for="q-position">Position</label>
                <input class="od-input" id="q-position" name="position" type="number" value="<?= $editRow ? (int)$editRow['position'] : 0 ?>">
              </div>
            </div>

            <div class="od-form-group">
              <label class="od-label" for="q-label">Question label</label>
              <input class="od-input" id="q-label" name="label" type="text" required maxlength="255"
                value="<?= h($editRow ? (string)$editRow['label'] : '') ?>"
                placeholder="e.g., How many units were installed?">
            </div>

            <div class="od-form-group">
              <label class="od-label" for="q-help">Help text <span class="od-label-note">(optional)</span></label>
              <input class="od-input" id="q-help" name="help_text" type="text" maxlength="255"
                value="<?= h($editRow ? (string)($editRow['help_text'] ?? '') : '') ?>"
                placeholder="Hint shown below the question">
            </div>

            <!-- Options (choice questions only) -->
            <div id="frmOptionsWrap">
              <div class="od-form-group">
                <label class="od-label">Options <span class="od-label-note">(drag to reorder)</span></label>
                <div class="frm-option-list" id="frmOptionsFields">
                  <?php if (!empty($editOptions)) : ?>
                        <?php foreach ($editOptions as $opt) : ?>
                      <div class="frm-option-row" draggable="true">
                        <button type="button" class="frm-option-drag" aria-label="Drag to reorder">&#8801;</button>
                        <input class="frm-option-input" type="text" value="<?= h($opt) ?>" placeholder="Option text">
                        <button type="button" class="frm-option-remove" title="Remove">&#10005;</button>
                      </div>
                        <?php endforeach; ?>
                  <?php endif; ?>
                  <div class="frm-option-row frm-option-row--empty" draggable="true">
                    <button type="button" class="frm-option-drag" aria-label="Drag to reorder">&#8801;</button>
                    <input class="frm-option-input" type="text" value="" placeholder="Option text">
                    <button type="button" class="frm-option-remove" title="Remove">&#10005;</button>
                  </div>
                </div>
                <input type="hidden" name="options_lines" id="frmOptionsHidden" value="<?= h($editRow ? $editOptionsLines : '') ?>">
                <div class="frm-option-meta">
                  <span class="od-form-hint">Required for Single / Multi choice</span>
                  <span class="frm-option-count" id="frmOptionsCount">No options yet</span>
                </div>
                <div class="frm-option-error" id="frmOptionsError" role="alert" aria-live="polite"></div>
              </div>
            </div>

            <div class="od-form-row">
              <label class="od-check-label">
                <input name="is_required" type="checkbox" value="1"
                  <?= ($editRow && (int)$editRow['is_required'] === 1) ? 'checked' : '' ?>>
                Required
              </label>
              <button class="od-btn od-btn--primary" type="submit">
                <?= $editRow ? 'Save Changes' : 'Add Question' ?>
              </button>
            </div>
          </form>
        </div>
      </div>
        <?php elseif (!$canManageForms) : ?>
        <div class="od-notice od-notice--warn">You can view questions but cannot edit this form.</div>
        <?php endif; ?>

      <!-- Question list -->
        <?php if (empty($formQuestions)) : ?>
        <div class="od-empty">
          <div class="od-empty-msg">No questions yet</div>
          <div class="od-empty-sub">Use the editor above to add your first question</div>
        </div>
        <?php else : ?>
        <div class="od-card">
          <div class="od-card-header">
            <div>
              <div class="od-card-title">Questions</div>
              <div class="od-card-sub"><?= count($formQuestions) ?> question<?= count($formQuestions) !== 1 ? 's' : '' ?></div>
            </div>
          </div>
          <div class="od-card-body">
            <div class="frm-q-list">
              <?php foreach ($formQuestions as $q) :
                    $qOpts = 0;
                    if (!empty($q['options_json'])) {
                        $dec = json_decode((string)$q['options_json'], true);
                        if (is_array($dec)) {
                            $qOpts = count($dec);
                        }
                    }
                    $qTypeLabel = str_replace('_', ' ', (string)$q['type']);
                    ?>
                <div class="frm-q-card">
                  <div class="frm-q-num"><?= (int)$q['position'] ?></div>
                  <div class="frm-q-body">
                    <div class="frm-q-label"><?= h($q['label']) ?></div>
                    <?php if (!empty($q['help_text'])) : ?>
                      <div class="frm-q-help"><?= h((string)$q['help_text']) ?></div>
                    <?php endif; ?>
                    <div class="frm-q-meta">
                      <span class="od-badge od-badge--info"><?= h($qTypeLabel) ?></span>
                      <?php if ((int)$q['is_required'] === 1) : ?>
                        <span class="od-badge od-badge--danger">Required</span>
                      <?php endif; ?>
                      <?php if ($qOpts > 0) : ?>
                        <span class="od-badge od-badge--draft"><?= $qOpts ?> option<?= $qOpts !== 1 ? 's' : '' ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="frm-q-actions">
                    <?php if ($canManageForms) : ?>
                      <a class="od-btn od-btn--ghost od-btn--sm" href="/forms?tab=forms&form_id=<?= $activeFormId ?>&edit_question_id=<?= (int)$q['id'] ?>">Edit</a>
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move_question">
                        <input type="hidden" name="form_id" value="<?= $activeFormId ?>">
                        <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                        <input type="hidden" name="direction" value="up">
                        <button class="od-btn od-btn--ghost od-btn--icon od-btn--sm" type="submit" title="Move up">&#9650;</button>
                      </form>
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move_question">
                        <input type="hidden" name="form_id" value="<?= $activeFormId ?>">
                        <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                        <input type="hidden" name="direction" value="down">
                        <button class="od-btn od-btn--ghost od-btn--icon od-btn--sm" type="submit" title="Move down">&#9660;</button>
                      </form>
                      <form method="post"
                        data-confirm="Remove this question? This cannot be undone."
                        data-confirm-title="Remove Question"
                        data-confirm-text="Remove"
                        data-confirm-style="danger">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="form_id" value="<?= $activeFormId ?>">
                        <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                        <button class="od-btn od-btn--danger od-btn--icon od-btn--sm" type="submit" title="Remove">&#10005;</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Live preview -->
        <div class="od-card">
          <div class="od-card-header">
            <div>
              <div class="od-card-title">Live Preview</div>
              <div class="od-card-sub">How this form renders to respondents</div>
            </div>
          </div>
          <div class="frm-preview-inner">
            <?php foreach ($formQuestions as $q) :
                $type     = (string)$q['type'];
                $required = ((int)$q['is_required'] === 1);
                $opts     = [];
                if (!empty($q['options_json'])) {
                    $dec = json_decode((string)$q['options_json'], true);
                    if (is_array($dec)) {
                        $opts = $dec;
                    }
                }
                ?>
              <div class="frm-preview-row">
                <label class="frm-preview-label">
                  <?= h((string)$q['label']) ?>
                  <?php if ($required) :
                        ?><span class="frm-preview-required"> *</span><?php
                  endif; ?>
                  <?php if (!empty($q['help_text'])) : ?>
                    <div class="frm-preview-help"><?= h((string)$q['help_text']) ?></div>
                  <?php endif; ?>
                  <?php if ($type === 'long_text') : ?>
                    <textarea disabled rows="3"></textarea>
                  <?php elseif ($type === 'number') : ?>
                    <input disabled type="number">
                  <?php elseif ($type === 'date') : ?>
                    <input disabled type="date">
                  <?php elseif ($type === 'yes_no') : ?>
                    <select disabled><option>Select...</option><option>Yes</option><option>No</option></select>
                  <?php elseif ($type === 'single_choice') : ?>
                      <?php if (empty($opts)) : ?>
                      <div class="od-notice od-notice--warn">No options configured yet.</div>
                      <?php else : ?>
                      <select disabled>
                        <option>Select...</option>
                          <?php foreach ($opts as $o) :
                                ?><option><?= h((string)$o) ?></option><?php
                          endforeach; ?>
                      </select>
                      <?php endif; ?>
                  <?php elseif ($type === 'multi_choice') : ?>
                      <?php if (empty($opts)) : ?>
                      <div class="od-notice od-notice--warn">No options configured yet.</div>
                      <?php else : ?>
                      <div class="frm-preview-check-group">
                          <?php foreach ($opts as $o) : ?>
                          <label class="frm-preview-check-label">
                            <input type="checkbox" disabled> <?= h((string)$o) ?>
                          </label>
                          <?php endforeach; ?>
                      </div>
                      <?php endif; ?>
                  <?php else : ?>
                    <input disabled type="text">
                  <?php endif; ?>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php endif; // formQuestions ?>

    <?php endif; // activeFormId ?>

  </section>
</div><!-- /.od-layout -->

<?php else : ?>
<!-- ================================================================== -->
<!-- CAMPAIGNS TAB                                                       -->
<!-- ================================================================== -->
<div class="od-layout">

  <aside class="od-sidebar">
    <div class="od-sidebar-hdr">Campaigns</div>
    <p class="od-empty-sub">Create a campaign to tie a form snapshot to a deployment project.</p>
  </aside>

  <section class="od-detail">
    <?php if (!$canManageCampaigns) : ?>
      <div class="od-notice od-notice--warn">You do not have permission to create campaigns.</div>
    <?php else : ?>
      <div class="od-card">
        <div class="od-card-header">
          <div>
            <div class="od-card-title">Create Campaign</div>
            <div class="od-card-sub">Snapshot a form and link it to a deployment project</div>
          </div>
        </div>
        <div class="od-card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_campaign">
            <div class="frm-campaign-grid">
              <div class="od-form-group">
                <label class="od-label" for="c-form">Form</label>
                <select class="od-select" id="c-form" name="form_id" required>
                  <option value="">Select a form...</option>
                  <?php foreach ($forms as $f) : ?>
                    <option value="<?= (int)$f['id'] ?>"><?= h($f['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="od-form-group">
                <label class="od-label" for="c-name">Campaign name</label>
                <input class="od-input" id="c-name" type="text" name="name" required maxlength="190"
                  placeholder="e.g., Contractor Progress Report – Week 1">
              </div>
              <div class="od-form-group">
                <label class="od-label" for="c-project">
                  Deployment project <span class="od-label-note">(optional)</span>
                </label>
                <select class="od-select" id="c-project" name="deployment_project_id">
                  <option value="">None</option>
                  <?php foreach ($deploymentProjects as $d) : ?>
                    <option value="<?= (int)$d['id'] ?>">
                      #<?= (int)$d['id'] ?> – <?= h($d['site_name']) ?> (<?= h($d['project_code']) ?> / <?= h($d['stage']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="od-form-group">
                <label class="od-label" for="c-extid">
                  External project ID <span class="od-label-note">(optional)</span>
                </label>
                <input class="od-input" id="c-extid" type="text" name="external_project_id" maxlength="100"
                  placeholder="Reference from external system">
              </div>
              <div class="od-form-group">
                <label class="od-label" for="c-site">
                  Site name <span class="od-label-note">(optional)</span>
                </label>
                <input class="od-input" id="c-site" type="text" name="site_name" maxlength="150"
                  placeholder="e.g., Warehouse A">
              </div>
              <div class="od-form-group">
                <label class="od-label" for="c-due">
                  Due date <span class="od-label-note">(optional)</span>
                </label>
                <input class="od-input" id="c-due" type="datetime-local" name="due_at">
              </div>
            </div>
            <div class="od-form-row">
              <button class="od-btn od-btn--primary" type="submit">Create Campaign</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </section>

</div><!-- /.od-layout -->
<?php endif; ?>

</div><!-- /.od-body -->
</div><!-- /.od-page -->

<?php require __DIR__ . '/footer.php'; ?>
