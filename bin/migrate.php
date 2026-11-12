<?php
/**
 * Migration CLI Runner
 *
 * Usage:
 *   php bin/migrate.php up
 *   php bin/migrate.php down
 *   php bin/migrate.php status
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/SqlRunner.php';

$pdo = db();
$runner = new SqlRunner($pdo);

// 1. Ensure schema_migrations table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
      `version` VARCHAR(64) NOT NULL,
      `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$migrationsDir = dirname(__DIR__) . '/migrations';
if (!is_dir($migrationsDir)) {
    @mkdir($migrationsDir, 0755, true);
}

$action = $argv[1] ?? 'up';

// Fetch applied migrations
$stmtApplied = $pdo->query("SELECT version, applied_at FROM schema_migrations ORDER BY version ASC");
$appliedMigrations = [];
while ($row = $stmtApplied->fetch(PDO::FETCH_ASSOC)) {
    $appliedMigrations[$row['version']] = $row['applied_at'];
}

// Find all migration files
$upFiles = glob($migrationsDir . '/*.up.sql') ?: [];
sort($upFiles);

if ($action === 'status') {
    echo "=== Database Schema Migrations Status ===" . PHP_EOL;
    if (empty($upFiles)) {
        echo "No migration files found in migrations/ directory." . PHP_EOL;
        exit(0);
    }
    printf("%-40s | %-10s | %-20s\n", "Migration Version", "Status", "Applied At");
    echo str_repeat("-", 76) . PHP_EOL;
    foreach ($upFiles as $file) {
        $basename = basename($file, '.up.sql');
        $isApplied = isset($appliedMigrations[$basename]);
        $statusStr = $isApplied ? "\033[32mAPPLIED\033[0m" : "\033[33mPENDING\033[0m";
        $appliedAt = $isApplied ? $appliedMigrations[$basename] : "—";
        printf("%-40s | %-19s | %-20s\n", $basename, $statusStr, $appliedAt);
    }
    exit(0);
}

if ($action === 'up') {
    echo "=== Running Migrations (UP) ===" . PHP_EOL;
    $count = 0;
    foreach ($upFiles as $file) {
        $version = basename($file, '.up.sql');
        if (isset($appliedMigrations[$version])) {
            continue; // Already applied
        }

        echo "Applying: {$version}... ";
        $sql = (string)file_get_contents($file);

        // Execute migration statements
        try {
            $runner->runFile($file);
            $ins = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (:ver, NOW())");
            $ins->execute([':ver' => $version]);
            echo "\033[32m[DONE]\033[0m" . PHP_EOL;
            $count++;
        } catch (Throwable $e) {
            echo "\033[31m[FAILED]\033[0m" . PHP_EOL;
            echo "Error executing {$version}: " . $e->getMessage() . PHP_EOL;
            exit(1);
        }
    }

    if ($count === 0) {
        echo "Nothing to migrate. Database is already up to date." . PHP_EOL;
    } else {
        echo "Successfully applied {$count} migration(s)." . PHP_EOL;
    }
    exit(0);
}

if ($action === 'down') {
    echo "=== Rolling Back Migration (DOWN) ===" . PHP_EOL;
    if (empty($appliedMigrations)) {
        echo "No applied migrations to revert." . PHP_EOL;
        exit(0);
    }

    // Get the latest applied version
    $versions = array_keys($appliedMigrations);
    $latestVersion = end($versions);
    $downFile = "{$migrationsDir}/{$latestVersion}.down.sql";

    if (!file_exists($downFile)) {
        echo "\033[31mError:\033[0m Rollback file not found: {$downFile}" . PHP_EOL;
        exit(1);
    }

    echo "Reverting: {$latestVersion}... ";
    $sql = (string)file_get_contents($downFile);

    try {
        $runner->runFile($downFile);
        $del = $pdo->prepare("DELETE FROM schema_migrations WHERE version = :ver");
        $del->execute([':ver' => $latestVersion]);
        echo "\033[32m[DONE]\033[0m" . PHP_EOL;
        echo "Successfully rolled back {$latestVersion}." . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        echo "\033[31m[FAILED]\033[0m" . PHP_EOL;
        echo "Error rolling back {$latestVersion}: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

echo "Unknown action '{$action}'. Supported: up, down, status." . PHP_EOL;
exit(1);
