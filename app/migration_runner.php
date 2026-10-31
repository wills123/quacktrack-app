<?php

require_once __DIR__ . '/config.php';
// Development only (production migrations run from admin/migrations.php or the deploy scripts).
if (!app_is_development()) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}
require_login();
require_permission('admin.access');
require_permission('migrations.run');

$pdo = db();

// Create migrations tracking table if it doesn't exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Get list of applied migrations
try {
    $applied = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Table doesn't exist yet, no migrations applied
    $applied = [];
}

// Get all migration files
$migrationDir = __DIR__ . '/db/migrations';
$migrationFiles = glob($migrationDir . '/*.sql');
sort($migrationFiles);

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for security
    csrf_verify();

    $appliedCount = 0;

    foreach ($migrationFiles as $file) {
        $filename = basename($file);

        // Skip if already applied
        if (in_array($filename, $applied)) {
            $results[] = ['name' => $filename, 'status' => 'skipped', 'message' => 'Migration has already been run.'];
            continue;
        }

        try {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new Exception("Could not read migration file: {$filename}");
            }

            // Execute the migration
            $pdo->exec($sql);

            // Record as applied
            $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
            $stmt->execute([$filename]);

            $results[] = ['name' => $filename, 'status' => 'success', 'message' => 'Migration ran successfully.'];
            $appliedCount++;
            $applied[] = $filename;
        } catch (Throwable $e) {
            qt_rethrow_bad_input($e);
            $results[] = ['name' => $filename, 'status' => 'error', 'message' => 'Error running migration: ' . $e->getMessage()];
        }
    }

    if ($appliedCount === 0) {
        $results[] = ['name' => 'Summary', 'status' => 'info', 'message' => 'No new migrations to apply.'];
    } else {
        $results[] = ['name' => 'Summary', 'status' => 'success', 'message' => "Applied {$appliedCount} migrations successfully."];
    }
}

require __DIR__ . '/views/migration_runner.view.php';
