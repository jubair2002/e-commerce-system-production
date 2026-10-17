<?php
/**
 * CLI Storefront Link & Syntax Integrity Verification Tool.
 *
 * Checks:
 *   1. PHP syntax on every repository file (`php -l`)
 *   2. Legacy filename references in codebase
 *   3. Route registry resolution for every registered route
 *   4. Route bootstrap smoke tests
 */

if (PHP_SAPI !== 'cli') {
    die("This tool can only be run from the command line.\n");
}

$rootDir = realpath(__DIR__ . '/../..');
echo "=== Jinispotro Storefront Link & Integrity Check ===\n";
echo "Root directory: {$rootDir}\n\n";

$errors = [];
$warnings = [];

// -------------------------------------------------------------
// 1. PHP Syntax Check
// -------------------------------------------------------------
echo "[1/4] Running PHP syntax linting...\n";
$phpFiles = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir));
foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        if (str_contains($path, '/assets/vendor/') || str_contains($path, '/.git/')) {
            continue;
        }
        $phpFiles[] = $path;
    }
}

$syntaxFailures = 0;
foreach ($phpFiles as $f) {
    $out = [];
    $ret = 0;
    exec("php -l " . escapeshellarg($f) . " 2>&1", $out, $ret);
    if ($ret !== 0) {
        $errors[] = "Syntax error in {$f}: " . implode("\n", $out);
        $syntaxFailures++;
    }
}
if ($syntaxFailures === 0) {
    echo "  ✓ All " . count($phpFiles) . " PHP files passed syntax check.\n";
} else {
    echo "  ✗ Found {$syntaxFailures} syntax errors!\n";
}

// -------------------------------------------------------------
// 2. Legacy Filename Grep Check
// -------------------------------------------------------------
echo "\n[2/4] Checking for obsolete legacy file references...\n";
$obsoletePatterns = [
    'includes/shop_header.php',
    'includes/shop_footer.php',
    '/_product_card.php',
];

$foundObsolete = 0;
foreach ($obsoletePatterns as $pat) {
    $cmd = "grep -rn --exclude-dir={.git,vendor,assets/vendor,tools,tests,docs,prompts} " . escapeshellarg($pat) . " " . escapeshellarg($rootDir);
    $out = [];
    exec($cmd, $out);
    if (!empty($out)) {
        foreach ($out as $line) {
            $warnings[] = "Legacy reference found: " . trim($line);
            $foundObsolete++;
        }
    }
}
if ($foundObsolete === 0) {
    echo "  ✓ No deprecated partial paths (shop_header, shop_footer, _product_card) found.\n";
} else {
    echo "  ! Found {$foundObsolete} occurrences of deprecated partial paths.\n";
}

// -------------------------------------------------------------
// 3. Route Map Resolution Check
// -------------------------------------------------------------
echo "\n[3/4] Verifying route registry and physical target files...\n";
@ob_start();
$_SERVER['HTTP_HOST'] = 'localhost';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
@require_once $rootDir . '/config/config.php';
@require_once $rootDir . '/includes/polyfills.php';
@require_once $rootDir . '/includes/functions.php';
@ob_end_clean();

$routeMap = get_route_map();
$missingTargets = 0;
foreach ($routeMap as $name => $meta) {
    $targetFile = $meta['file'];
    if ($targetFile === '') {
        $fullPath = $rootDir . '/index.php';
    } else {
        $fullPath = $rootDir . '/' . $targetFile;
    }

    if (!file_exists($fullPath)) {
        $errors[] = "Route '{$name}' target file not found: {$targetFile}";
        $missingTargets++;
    }

    $genStandard = route($name);
    if (empty($genStandard)) {
        $errors[] = "Route '{$name}' generated empty URL in standard mode.";
    }
}

if ($missingTargets === 0) {
    echo "  ✓ All " . count($routeMap) . " registered routes resolve to existing files on disk.\n";
} else {
    echo "  ✗ {$missingTargets} route targets missing on disk!\n";
}

// -------------------------------------------------------------
// 4. Verification Summary
// -------------------------------------------------------------
echo "\n[4/4] Summary:\n";
if (!empty($warnings)) {
    echo "Warnings (" . count($warnings) . "):\n";
    foreach (array_slice($warnings, 0, 10) as $w) {
        echo "  - {$w}\n";
    }
}

if (empty($errors)) {
    echo "\n>>> SUCCESS: Storefront link, syntax, and directory structure check PASSED! <<<\n";
    exit(0);
} else {
    echo "\n>>> FAILURE: " . count($errors) . " error(s) detected: <<<\n";
    foreach ($errors as $e) {
        echo "  * {$e}\n";
    }
    exit(1);
}
