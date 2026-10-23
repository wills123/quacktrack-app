<?php
$campaign = $campaign ?? [];
$questions = $questions ?? [];
require __DIR__ . '/header.php';
?>

<div class="page-header">
  <div>
    <h1><?= h($campaign['form_title'] ?? 'Report') ?></h1>
    <p class="page-subtitle"><?= h($campaign['name'] ?? '') ?></p>
  </div>
</div>

<section class="card" style="margin-top:16px;">
  <div class="card-header"><h2>Submit your update</h2></div>
  <div class="card-body">

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>

      <div class="form-row">
        <label>Your email<span class="text-danger">*</span>
          <input type="email" name="respondent_email" required value="<?= h((string)($old['respondent_email'] ?? $prefillEmail ?? '')) ?>" maxlength="190">
        </label>
        <?php if (!empty($errors['respondent_email'])) : ?>
          <div class="text-danger" style="margin-top:6px;"><?= h((string)$errors['respondent_email']) ?></div>
        <?php endif; ?>
        <div class="muted" style="margin-top:6px; font-size:12px;">This identifies who submitted the report. Links may allow multiple submissions.</div>
      </div>

      <?php foreach ($questions as $q) : ?>
            <?php
            $qid = (int)$q['id'];
            $name = 'q_' . $qid;
            $required = ((int)$q['is_required'] === 1);
            $type = (string)$q['type'];
            ?>
        <div class="form-row" style="margin-top:12px;">
          <label>
            <?= h($q['label']) ?><?= $required ? ' *' : '' ?>
            <?php if (!empty($q['help_text'])) : ?>
              <div class="muted" style="margin:6px 0 0; font-size:12px;"><?= h($q['help_text']) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors[$name])) : ?>
              <div class="text-danger" style="margin-top:6px;"><?= h((string)$errors[$name]) ?></div>
            <?php endif; ?>

            <?php $oldVal = $old[$name] ?? null; ?>

            <?php if ($type === 'long_text') : ?>
              <textarea name="<?= h($name) ?>" rows="4" <?= $required ? 'required' : '' ?>><?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?></textarea>
            <?php elseif ($type === 'number') : ?>
              <input type="number" name="<?= h($name) ?>" value="<?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?>" <?= $required ? 'required' : '' ?>>
            <?php elseif ($type === 'date') : ?>
              <input type="date" name="<?= h($name) ?>" value="<?= h(is_scalar($oldVal) ? (string)$oldVal : '') ?>" <?= $required ? 'required' : '' ?>>
            <?php elseif ($type === 'yes_no') : ?>
              <select name="<?= h($name) ?>" <?= $required ? 'required' : '' ?>>
                <option value="">Select…</option>
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
                <input type="text" name="<?= h($name) ?>" <?= $required ? 'required' : '' ?>>
                <?php else : ?>
                    <?php if ($type === 'single_choice') : ?>
                  <select name="<?= h($name) ?>" <?= $required ? 'required' : '' ?>>
                    <option value="">Select…</option>
                        <?php foreach ($opts as $opt) : ?>
                      <option value="<?= h((string)$opt) ?>" <?= ($oldVal === (string)$opt) ? 'selected' : '' ?>><?= h((string)$opt) ?></option>
                        <?php endforeach; ?>
                  </select>
                    <?php else : ?>
                        <?php $oldArr = is_array($oldVal) ? $oldVal : []; ?>
                  <div style="display:grid; gap:6px; margin-top:8px;">
                        <?php foreach ($opts as $opt) : ?>
                            <?php $checked = in_array((string)$opt, array_map('strval', $oldArr), true); ?>
                      <label style="display:flex; align-items:center; gap:8px;">
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

      <div style="margin-top:16px;">
        <button class="btn btn-primary" type="submit">Submit</button>
      </div>

    </form>

  </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
