<?php
$title = 'Run All Migrations';
require __DIR__ . '/header.php';
?>

<h1>Run All Migrations</h1>

<?php if (!empty($results)) : ?>
    <h2>Migration Results</h2>
    <ul>
        <?php foreach ($results as $result) : ?>
            <li>
                <strong><?=h($result['name'])?>:</strong>
                <span class="<?=h($result['status'])?>"><?=h($result['message'])?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" class="migration-runner-form">
    <?= csrf_field() ?>
    <button type="submit">Run All Migrations</button>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const migrationForm = document.querySelector('.migration-runner-form');
    if (migrationForm) {
        migrationForm.addEventListener('submit', function(e) {
            e.preventDefault();

            QuackNotify.confirm('Are you sure you want to run all migrations? This will modify the database.', {
                title: 'Run All Migrations',
                confirmText: 'Run All Migrations',
                cancelText: 'Cancel',
                confirmStyle: 'warning'
            }).then(confirmed => {
                if (confirmed) {
                    this.submit();
                }
            });
        });
    }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
