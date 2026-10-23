<?php

require_once __DIR__ . '/config.php';
function fresh_db_connection(): PDO
{
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASSWORD;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);
    return new PDO($dsn, $DB_USER, $DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ] + db_tls_options());
}

// The runner's own connection, with buffered queries: a migration that ends in a SELECT (017, 024)
// would otherwise leave an unread result set behind, the commit that follows would fail with
// "unbuffered queries are active", and the transaction (and its table locks) could never be closed.
$pdo = fresh_db_connection();

function is_sql_char_escaped(string $sql, int $pos): bool
{
    // Determines whether the character at $pos is escaped by an odd number of backslashes.
    $backslash_count = 0;
    for ($i = $pos - 1; $i >= 0; $i--) {
        if ($sql[$i] !== '\\') {
            break;
        }
        $backslash_count++;
    }

    return ($backslash_count % 2) === 1;
}

function split_sql_statements(string $sql): array
{
    // Split a SQL file into executable statements.
    // Handles semicolons inside strings/identifiers and strips MySQL-style comments.
    $statements = [];
    $buffer = '';
    $len = strlen($sql);
    $in_single = false;
    $in_double = false;
    $in_backtick = false;
    $in_line_comment = false;
    $in_block_comment = false;
    $block_comment_has_newline = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';
        if ($in_line_comment) {
            if ($ch === "\n") {
                $in_line_comment = false;
                $buffer .= "\n";
            }
            continue;
        }

        if ($in_block_comment) {
            if ($ch === "\n") {
                $block_comment_has_newline = true;
            }
            if ($ch === '*' && $next === '/') {
                $in_block_comment = false;
                $i++;
// Preserve statement token boundaries when stripping block comments.
                // Using a newline keeps line structure for debugging; otherwise, add a space.
                $buffer .= $block_comment_has_newline ? "\n" : ' ';
            }
            continue;
        }

        if (!$in_single && !$in_double && !$in_backtick) {
// Line comments: -- <space>, #
            if ($ch === '-' && $next === '-') {
                $after = ($i + 2 < $len) ? $sql[$i + 2] : '';
                if ($after === '' || $after === ' ' || $after === "\t" || $after === "\r" || $after === "\n") {
                    $in_line_comment = true;
                    $i++;
                    continue;
                }
            }

            if ($ch === '#') {
                $in_line_comment = true;
                continue;
            }

            // Block comments: /* ... */
            if ($ch === '/' && $next === '*') {
// Preserve MySQL versioned comments (/*!...*/), which may contain executable SQL.
                $after = ($i + 2 < $len) ? $sql[$i + 2] : '';
                if ($after === '!') {
                    $end = strpos($sql, '*/', $i + 2);
                    if ($end === false) {
                        $buffer .= substr($sql, $i);
                        break;
                    }
                    $buffer .= substr($sql, $i, $end - $i + 2);
                    $i = $end + 1;
                    continue;
                }
                $in_block_comment = true;
                $block_comment_has_newline = false;
                $i++;
                continue;
            }
        }

        // Quote/identifier handling
        if ($ch === "'" && !$in_double && !$in_backtick) {
            if ($in_single) {
            // Handle doubled quotes inside strings: ''
                if ($next === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                if (!is_sql_char_escaped($sql, $i)) {
                    $in_single = false;
                }
            } else {
                $in_single = true;
            }
            $buffer .= $ch;
            continue;
        }

        if ($ch === '"' && !$in_single && !$in_backtick) {
            if ($in_double) {
            // Handle doubled quotes inside strings: ""
                if ($next === '"') {
                    $buffer .= '""';
                    $i++;
                    continue;
                }
                if (!is_sql_char_escaped($sql, $i)) {
                    $in_double = false;
                }
            } else {
                $in_double = true;
            }
            $buffer .= $ch;
            continue;
        }

        if ($ch === '`' && !$in_single && !$in_double) {
            if ($in_backtick) {
            // Handle doubled backticks inside identifiers: ``
                if ($next === '`') {
                    $buffer .= '``';
                    $i++;
                    continue;
                }
                $in_backtick = false;
            } else {
                $in_backtick = true;
            }
            $buffer .= $ch;
            continue;
        }

        // Statement terminator
        if ($ch === ';' && !$in_single && !$in_double && !$in_backtick) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

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
$migrationDir = env('MIGRATIONS_DIR', '/var/www/html/db/migrations');
$migrationFiles = glob($migrationDir . '/*.sql');
if ($migrationFiles === false) {
    echo "✗ Could not list the migrations in {$migrationDir}\n";
    exit(1);
}
sort($migrationFiles);
$appliedCount = 0;
foreach ($migrationFiles as $file) {
    $filename = basename($file);
// Skip if already applied
    if (in_array($filename, $applied)) {
        echo "Skipping {$filename} (already applied)\n";
        continue;
    }

    echo "Applying {$filename}...\n";
    try {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new Exception("Could not read migration file: {$filename}");
        }

        // Split SQL into individual executable statements.
        // IMPORTANT: Don't drop statements just because they *start* with a comment.
        $statements = split_sql_statements($sql);
        $pdo->beginTransaction();
        $hasError = false;
        foreach ($statements as $statement) {
            try {
                // query() and closeCursor(), not exec(): a statement that returns rows (the SELECT that
                // ends 017 and 024) would leave its result set open and the next call would fail.
                $pdo->query($statement)->closeCursor();
            } catch (PDOException $e) {
            // Allow DROP INDEX failures (index might not exist)
                if (
                    stripos($statement, 'DROP INDEX') !== false &&
                    $e->getCode() === '42000' &&
                    stripos($e->getMessage(), 'check that column/key exists') !== false
                ) {
                    echo "  ⚠ Skipping non-existent index (expected)\n";
                    continue;
                }
                throw $e;
            }
        }

        // MySQL DDL can cause implicit commits; only commit if the transaction is still active.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // Record as applied
        $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $stmt->execute([$filename]);
        echo "✓ Applied {$filename}\n";
        $appliedCount++;
    } catch (Exception $e) {
        qt_rethrow_bad_input($e);
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Exception $re) {
                qt_rethrow_bad_input($re);
                error_log('Migration rollback failed after an application error: ' . get_class($re));
            }
        }
        // PDO can believe the transaction is over (a DDL statement committed it) while the server
        // still holds one open; an open transaction keeps its locks, and the next migration would
        // wait on them forever. Roll back on the server whatever PDO thinks.
        try {
            $pdo->exec('ROLLBACK');
        } catch (Exception $se) {
            qt_rethrow_bad_input($se);
            error_log('Migration server-side rollback failed: ' . get_class($se));
        }
        echo "✗ Error applying {$filename}: " . $e->getMessage() . "\n";
        // Reconnect to clear any stuck PDO/MySQL state (e.g. unbuffered result sets
        // left open after a failed DDL transaction) so subsequent migrations can run.
        $stmt = null;
        $pdo = null;
        try {
            $pdo = fresh_db_connection();
        } catch (Exception $ce) {
            qt_rethrow_bad_input($ce);
            error_log('Migration database reconnect failed after an application error: ' . get_class($ce));
            echo "✗ Could not reconnect to the database; stopping.\n";
            exit(1);
        }
        // Continue with other migrations
    }
}

if ($appliedCount === 0) {
    echo "No new migrations to apply.\n";
} else {
    echo "\nApplied {$appliedCount} migrations successfully.\n";
}

echo "Migration process complete.\n";
