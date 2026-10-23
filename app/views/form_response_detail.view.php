<?php
$response = $response ?? [];
$answers = $answers ?? [];
require __DIR__ . '/header.php';
?>

<div class="page-header">
  <div>
    <h1>Response #<?= (int)$response['id'] ?></h1>
    <p class="page-subtitle"><?= h($response['form_title'] ?? '') ?> · <?= h($response['campaign_name'] ?? '') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-secondary" href="/form_responses?campaign_id=<?= (int)$response['campaign_id'] ?>">Back to responses</a>
  </div>
</div>

<section class="card" style="margin-top:16px;">
  <div class="card-header"><h2>Metadata</h2></div>
  <div class="card-body">
    <div class="muted">Type: <?= h((string)$response['respondent_type']) ?></div>
    <div class="muted">Submitted: <?= h((string)$response['submitted_at']) ?></div>
    <?php if (!empty($response['respondent_email'])) : ?>
      <div class="muted">Email: <?= h((string)$response['respondent_email']) ?></div>
    <?php endif; ?>
    <?php if (!empty($response['deployment_visit_id'])) : ?>
      <div class="muted">Deployment visit ID: <?= h((string)$response['deployment_visit_id']) ?></div>
    <?php endif; ?>
  </div>
</section>

<section class="card" style="margin-top:16px;">
  <div class="card-header"><h2>Answers</h2></div>
  <div class="card-body">

    <?php foreach ($answers as $a) : ?>
        <?php
        $val = null;
        if (!empty($a['answer_json'])) {
            $decoded = json_decode((string)$a['answer_json'], true);
            if (is_array($decoded)) {
                $val = implode(', ', array_map('strval', $decoded));
            } else {
                $val = (string)$a['answer_json'];
            }
        } else {
            $val = (string)($a['answer_text'] ?? '');
        }
        ?>
      <div style="padding:10px 0; border-bottom:1px solid rgba(0,0,0,0.06);">
        <div style="font-weight:700;"><?= h((string)$a['label']) ?></div>
        <div class="muted" style="margin-top:6px; white-space:pre-wrap;"><?= h($val) ?></div>
      </div>
    <?php endforeach; ?>

  </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
