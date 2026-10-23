<?php
$campaign = $campaign ?? [];
$comparisonRows = $comparisonRows ?? [];
require __DIR__ . '/header.php';
?>

<div class="page-header">
  <div>
    <h1><?= h($campaign['form_title'] ?? 'Form') ?></h1>
    <p class="page-subtitle">Campaign: <?= h($campaign['name'] ?? '') ?> (ID #<?= (int)$campaign['id'] ?>)</p>
  </div>
  <div class="page-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn btn-secondary" href="/form_submit?campaign_id=<?= (int)$campaign['id'] ?>">New internal submission</a>
    <?php if (has_permission('forms.export')) : ?>
      <a class="btn btn-secondary" href="/api/forms.php?action=export_csv_wide&campaign_id=<?= (int)$campaign['id'] ?>">Export CSV (wide)</a>
      <a class="btn btn-secondary" href="/api/forms.php?action=export_csv_long&campaign_id=<?= (int)$campaign['id'] ?>">Export CSV (long)</a>
    <?php endif; ?>
    <?php if (has_permission('forms.invites.send')) : ?>
      <button class="btn btn-secondary" type="button" id="createShareLinkBtn">Create contractor link</button>
    <?php endif; ?>
  </div>
</div>

<section class="card" style="margin-top:16px;">
  <div class="card-header"><h2>Latest snapshots</h2></div>
  <div class="card-body">
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
      <div class="card" style="padding:12px;">
        <div style="font-weight:700;">Latest contractor</div>
        <?php if (empty($latestContractor)) : ?>
          <div class="muted" style="margin-top:6px;">No contractor submissions yet.</div>
        <?php else : ?>
          <div class="muted" style="margin-top:6px;">Submitted: <?= h((string)$latestContractor['submitted_at']) ?></div>
          <div class="muted" style="margin-top:6px;">Email: <?= h((string)($latestContractor['respondent_email'] ?? '')) ?></div>
          <div style="margin-top:10px;"><a class="btn btn-secondary" href="/form_response_detail?response_id=<?= (int)$latestContractor['id'] ?>">View</a></div>
        <?php endif; ?>
      </div>

      <div class="card" style="padding:12px;">
        <div style="font-weight:700;">Latest internal (onsite)</div>
        <?php if (empty($latestInternal)) : ?>
          <div class="muted" style="margin-top:6px;">No internal submissions yet.</div>
        <?php else : ?>
          <div class="muted" style="margin-top:6px;">Submitted: <?= h((string)$latestInternal['submitted_at']) ?></div>
          <div class="muted" style="margin-top:6px;">Visit ID: <?= h((string)($latestInternal['deployment_visit_id'] ?? '')) ?></div>
          <div style="margin-top:10px;"><a class="btn btn-secondary" href="/form_response_detail?response_id=<?= (int)$latestInternal['id'] ?>">View</a></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($latestContractor) && !empty($latestInternal)) : ?>
      <hr style="margin:16px 0;">
      <h3 style="margin:0 0 10px;">Latest comparison (contractor vs onsite)</h3>

      <table class="table">
        <thead>
          <tr>
            <th style="width:35%;">Question</th>
            <th>Contractor</th>
            <th>Onsite</th>
            <th style="width:90px;">Match</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($comparisonRows as $row) : ?>
                <?php
                $cVal = (string)($row['contractor_value'] ?? '');
                $iVal = (string)($row['internal_value'] ?? '');
                $match = !empty($row['is_match']);
                $emptyBoth = (trim($cVal) === '' && trim($iVal) === '');
                ?>
            <tr <?= $match ? '' : 'style="background: rgba(220, 53, 69, 0.06);"' ?> >
              <td><?= h((string)$row['label']) ?></td>
              <td><?= h($cVal !== '' ? $cVal : '—') ?></td>
              <td><?= h($iVal !== '' ? $iVal : '—') ?></td>
              <td>
                <?php if ($emptyBoth) : ?>
                  <span class="muted">—</span>
                <?php else : ?>
                    <?= $match ? 'Yes' : 'No' ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="muted" style="margin-top:10px;">This compares the latest contractor submission to the latest internal submission for this campaign.</div>
    <?php else : ?>
      <div class="muted" style="margin-top:12px;">Submit at least one contractor and one internal report to see a comparison.</div>
    <?php endif; ?>
  </div>
</section>

<?php if (has_permission('forms.invites.send')) : ?>
<section class="card" style="margin-top:16px;">
  <div class="card-header"><h2>Contractor links</h2></div>
  <div class="card-body">
    <div id="shareLinksContainer"><div class="muted">Loading links...</div></div>
  </div>
</section>
<?php endif; ?>

<section class="card" style="margin-top:16px;">
  <div class="card-header"><h2>Recent submissions</h2></div>
  <div class="card-body">

    <?php if (empty($responses)) : ?>
      <div class="muted">No submissions yet.</div>
    <?php else : ?>
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>Submitted</th>
            <th>Email/User</th>
            <th>Visit</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($responses as $r) : ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h((string)$r['respondent_type']) ?></td>
              <td><?= h((string)$r['submitted_at']) ?></td>
              <td>
                <?php if (!empty($r['respondent_email'])) : ?>
                    <?= h((string)$r['respondent_email']) ?>
                <?php else : ?>
                  User #<?= (int)($r['respondent_user_id'] ?? 0) ?>
                <?php endif; ?>
              </td>
              <td><?= h((string)($r['deployment_visit_id'] ?? '')) ?></td>
              <td><a class="btn btn-secondary" href="/form_response_detail?response_id=<?= (int)$r['id'] ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>

<?php if (has_permission('forms.invites.send')) : ?>
<script>
(function() {
  var btn = document.getElementById('createShareLinkBtn');
  if (!btn) return;

  async function refreshLinks() {
    try {
      var res = await fetch('/api/forms.php?action=list_share_links&campaign_id=' + encodeURIComponent(<?= json_encode((int)$campaign['id']) ?>));
      var data = await res.json();
      if (!res.ok || data.success === false) return;

      var container = document.getElementById('shareLinksContainer');
      if (!container) return;

      var links = (data.data && data.data.links) ? data.data.links : [];
      if (!links.length) {
        container.innerHTML = '<div class="muted">No active contractor links yet.</div>';
        return;
      }

      var html = '<table class="table"><thead><tr><th>ID</th><th>Created</th><th>Expires</th><th>Revoked</th><th></th></tr></thead><tbody>';
      links.forEach(function(l) {
        html += '<tr>' +
          '<td>' + l.id + '</td>' +
          '<td>' + (l.created_at || '') + '</td>' +
          '<td>' + (l.expires_at || '') + '</td>' +
          '<td>' + (l.revoked_at || '') + '</td>' +
          '<td>' + (l.revoked_at ? '' : '<button type="button" class="btn btn-secondary" data-revoke-link="' + l.id + '">Revoke</button>') + '</td>' +
        '</tr>';
      });
      html += '</tbody></table>';
      container.innerHTML = html;

      container.querySelectorAll('[data-revoke-link]').forEach(function(node) {
        node.addEventListener('click', async function() {
          var linkId = node.getAttribute('data-revoke-link');
          if (!linkId) return;
          var ok = await QuackNotify.confirm('Revoke this contractor link? The link will stop working immediately.', {
            title: 'Revoke Link',
            confirmText: 'Revoke',
            confirmStyle: 'danger'
          });
          if (!ok) return;

          var fd = new FormData();
          fd.set('action', 'revoke_share_link');
          fd.set('csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
          fd.set('link_id', linkId);

          var res2 = await fetch('/api/forms.php', { method: 'POST', body: fd });
          var data2 = await res2.json();
          if (!res2.ok || data2.success === false) {
            QuackNotify.error(data2.error || 'Unable to revoke link');
            return;
          }
          refreshLinks();
        });
      });

    } catch (e) {
      // silent
    }
  }

  btn.addEventListener('click', async function() {
    btn.disabled = true;
    try {
      var fd = new FormData();
      fd.set('action', 'create_share_link');
      fd.set('csrf', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
      fd.set('campaign_id', <?= json_encode((int)$campaign['id']) ?>);

      var res = await fetch('/api/forms.php', { method: 'POST', body: fd });
      var data = await res.json();
      if (!res.ok || data.success === false) {
        throw new Error(data.error || 'Unable to create link');
      }

      var url = data.data && data.data.url ? data.data.url : '';
      var exp = data.data && data.data.expires_at ? data.data.expires_at : '';
      window.prompt('Contractor link (copy/paste). Expires: ' + exp, url);
      refreshLinks();
    } catch (e) {
      alert(e.message);
    } finally {
      btn.disabled = false;
    }
  });

  refreshLinks();
})();
</script>
<?php endif; ?>
