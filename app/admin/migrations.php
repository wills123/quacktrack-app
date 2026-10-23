<?php

require_once __DIR__ . '/../config.php';
require_login();
require_permission('admin.access');
require_permission('migrations.run');
$pdo = db();
$output = [];
$error = null;
$migrationDir = __DIR__ . '/../../db/migrations';
// ALWAYS ensure migrations table has correct schema (run on every page load)
try {
// Create migrations tracking table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
// Check if the table has the correct schema (handle old schemas)
    $columns = $pdo->query("SHOW COLUMNS FROM migrations")->fetchAll(PDO::FETCH_ASSOC);
    $hasFilename = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'filename') {
            $hasFilename = true;
            break;
        }
    }

    // If table exists but doesn't have filename column, it's an old schema
    if (!$hasFilename) {
// Check if it has 'migration' or 'name' column instead
        $oldColumnName = null;
        foreach ($columns as $column) {
            if ($column['Field'] === 'migration' || $column['Field'] === 'name') {
                $oldColumnName = $column['Field'];
                break;
            }
        }

        if ($oldColumnName) {
// Rename the column to 'filename'
            $pdo->exec("ALTER TABLE migrations CHANGE COLUMN {$oldColumnName} filename VARCHAR(255) NOT NULL UNIQUE");
            Logger::info('migrations_schema_updated', "Updated migrations table schema (renamed {$oldColumnName} → filename)", [
                'admin_id' => $_SESSION['uid'],
                'old_column' => $oldColumnName
            ]);
        }
    }
} catch (PDOException $e) {
// Log error but continue
    Logger::error('migrations_table_setup_failed', $e, [
        'admin_id' => $_SESSION['uid']
    ]);
}

// Handle migration execution / maintenance actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup_stale_drafts') {
    csrf_verify();
    try {
        $deleted = survey_cleanup_stale_drafts(30);
        flash("Deleted {$deleted} stale draft submission(s) (older than 30 days).", 'success');
        Logger::info('survey_cleanup_stale_drafts', 'Deleted stale survey drafts', [
            'admin_id' => $_SESSION['uid'],
            'deleted' => $deleted,
            'days_old' => 30,
        ]);
    } catch (Throwable $e) {
        qt_rethrow_bad_input($e);
        flash('Error cleaning up stale drafts. See logs for details.', 'error');
        Logger::error('survey_cleanup_stale_drafts_failed', $e, ['admin_id' => $_SESSION['uid']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_migrations') {
    csrf_verify();
    try {
    // Get list of applied migrations
        try {
            $applied = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $applied = [];
        }

        // Get all migration files
        $migrationFiles = glob($migrationDir . '/*.sql') ?: [];
        sort($migrationFiles);
        $appliedCount = 0;
        foreach ($migrationFiles as $file) {
            $filename = basename($file);
        // Skip if already applied
            if (in_array($filename, $applied)) {
                $output[] = ['type' => 'skip', 'message' => "Skipped {$filename} (already applied)"];
                continue;
            }

            $output[] = ['type' => 'info', 'message' => "Applying {$filename}..."];
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
                $output[] = ['type' => 'success', 'message' => "✓ Applied {$filename}"];
                $appliedCount++;
                Logger::info('migration_applied', "Migration applied: {$filename}", [
                    'admin_id' => $_SESSION['uid'],
                    'migration' => $filename
                ]);
            } catch (Exception $e) {
                qt_rethrow_bad_input($e);
                $errorMsg = $e->getMessage();
            // Check if error is because migration was already applied
                $isAlreadyApplied = (
                    strpos($errorMsg, 'Duplicate key name') !== false ||
                    strpos($errorMsg, 'Duplicate column name') !== false ||
                    strpos($errorMsg, 'Column already exists') !== false ||
                    strpos($errorMsg, 'already exists') !== false
                );
                if ($isAlreadyApplied) {
                // Migration already applied, mark it as such
                    try {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO migrations (filename) VALUES (?)");
                        $stmt->execute([$filename]);
                        $output[] = ['type' => 'skip', 'message' => "⚠ {$filename} - Already applied (marked as complete)"];
                    } catch (Exception $insertError) {
                        qt_rethrow_bad_input($insertError);
                        $output[] = ['type' => 'skip', 'message' => "⚠ {$filename} - Already applied"];
                    }
                } else {
                // Real error
                    $output[] = ['type' => 'error', 'message' => "✗ Error applying {$filename}: " . $errorMsg];
                    Logger::error('migration_failed', $e, [
                        'admin_id' => $_SESSION['uid'],
                        'migration' => $filename
                    ]);
                }
            }
        }

        if ($appliedCount === 0) {
            $output[] = ['type' => 'info', 'message' => "No new migrations to apply."];
            flash('No new migrations to apply.', 'info');
        } else {
            $output[] = ['type' => 'success', 'message' => "Applied {$appliedCount} migration(s) successfully."];
            flash("Applied {$appliedCount} migration(s) successfully.", 'success');
        }
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        $error = $e->getMessage();
        flash('Error running migrations: ' . $error, 'error');
        Logger::error('migration_process_failed', $e, [
            'admin_id' => $_SESSION['uid']
        ]);
    }
}

// Get migration status
try {
// Create table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $applied = $pdo->query("SELECT filename, applied_at FROM migrations ORDER BY applied_at DESC")->fetchAll();
} catch (PDOException $e) {
    $applied = [];
}

// Get all migration files
$migrationFiles = glob($migrationDir . '/*.sql') ?: [];
sort($migrationFiles);
$migrations = [];
foreach ($migrationFiles as $file) {
    $filename = basename($file);
    $appliedInfo = array_filter($applied, fn($a) => $a['filename'] === $filename);
    $appliedInfo = reset($appliedInfo);
    $migrations[] = [
        'filename' => $filename,
        'applied' => !empty($appliedInfo),
        'applied_at' => $appliedInfo['applied_at'] ?? null
    ];
}

require __DIR__ . '/../views/admin/migrations.view.php';
