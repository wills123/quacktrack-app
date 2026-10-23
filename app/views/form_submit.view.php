<?php
$campaign = $campaign ?? [];
$questions = $questions ?? [];
$visits = $visits ?? [];
require __DIR__ . '/header.php';
?>

<div class="form-submit-page">
  <div class="form-submit-header">
    <h1 class="form-submit-header__title"><?= h($campaign['form_title'] ?? 'Report') ?></h1>
    <div class="form-submit-header__campaign">Campaign: <?= h($campaign['name'] ?? '') ?></div>
  </div>

  <div class="form-submit-card">
    <div class="form-submit-card__bar"></div>
    <div class="form-submit-card__body">
      <form method="post">
        <?= csrf_field() ?>

        <?php if (!empty($visits)) : ?>
          <div class="form-submit-visit">
            <label>Deployment visit (optional)
              <select name="deployment_visit_id">
                <option value="">None</option>
                <?php foreach ($visits as $v) : ?>
                  <option value="<?= (int)$v['id'] ?>">
                    #<?= (int)$v['id'] ?> — <?= h((string)$v['visit_date']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        <?php endif; ?>

        <?php foreach ($questions as $q) : ?>
            <?php
            $qid = (int)$q['id'];
            $name = 'q_' . $qid;
            $required = ((int)$q['is_required'] === 1);
            $type = (string)$q['type'];
            $oldVal = $old[$name] ?? null;
            ?>
          <div class="form-submit-field">
            <label>
              <?= h($q['label']) ?><?= $required ? ' *' : '' ?>
              <?php if (!empty($q['help_text'])) : ?>
                <div class="form-submit-field__help"><?= h($q['help_text']) ?></div>
              <?php endif; ?>

              <?php if (!empty($errors[$name])) : ?>
                <div class="form-submit-field__error"><?= h((string)$errors[$name]) ?></div>
              <?php endif; ?>

              <?php if ($type === 'long_text') : ?>
                <textarea name="<?= h($name) ?>" rows="4" <?= $required ? 'required' : '' ?>><?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?></textarea>
              <?php elseif ($type === 'number') : ?>
                <input type="number" name="<?= h($name) ?>" value="<?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?>" <?= $required ? 'required' : '' ?>>
              <?php elseif ($type === 'date') : ?>
                <input type="date" name="<?= h($name) ?>" value="<?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?>" <?= $required ? 'required' : '' ?>>
              <?php elseif ($type === 'yes_no') : ?>
                <select name="<?= h($name) ?>" <?= $required ? 'required' : '' ?>>
                  <option value="">Select...</option>
                  <option value="yes" <?= ($oldVal === 'yes') ? 'selected' : '' ?>>Yes</option>
                  <option value="no" <?= ($oldVal === 'no') ? 'selected' : '' ?>>No</option>
                </select>
              <?php elseif ($type === 'single_choice' || $type === 'multi_choice') : ?>
                  <?php
                    $opts = [];
                    if (!empty($q['options_json'])) {
                        $decoded = json_decode((string)$q['options_json'], true);
                        if (is_array($decoded)) {
                            $opts = $decoded;
                        }
                    }
                    ?>
                  <?php if (empty($opts)) : ?>
                  <div class="notice notice--warning">This question needs options configured. For now you can type a comma-separated list.</div>
                  <input type="text" name="<?= h($name) ?>" value="<?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?>" <?= $required ? 'required' : '' ?> placeholder="e.g., Option A, Option B">
                  <?php else : ?>
                      <?php if ($type === 'single_choice') : ?>
                    <select name="<?= h($name) ?>" <?= $required ? 'required' : '' ?>>
                      <option value="">Select...</option>
                            <?php foreach ($opts as $opt) : ?>
                        <option value="<?= h((string)$opt) ?>" <?= ($oldVal === (string)$opt) ? 'selected' : '' ?>><?= h((string)$opt) ?></option>
                            <?php endforeach; ?>
                    </select>
                      <?php else : ?>
                          <?php $oldArr = is_array($oldVal) ? $oldVal : []; ?>
                    <div class="form-submit-choices">
                          <?php foreach ($opts as $opt) : ?>
                                <?php $checked = in_array((string)$opt, array_map('strval', $oldArr), true); ?>
                        <label class="form-submit-choice">
                          <input type="checkbox" name="<?= h($name) ?>[]" value="<?= h((string)$opt) ?>" <?= $checked ? 'checked' : '' ?>>
                          <span><?= h((string)$opt) ?></span>
                        </label>
                          <?php endforeach; ?>
                    </div>
                      <?php endif; ?>
                  <?php endif; ?>
              <?php else : ?>
                <input type="text" name="<?= h($name) ?>" value="<?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?>" <?= $required ? 'required' : '' ?>>
              <?php endif; ?>
            </label>
          </div>
        <?php endforeach; ?>

        <div class="form-submit-actions">
          <button class="btn btn-primary" type="submit">Submit report</button>
          <a class="btn btn-secondary" href="/deployments">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
