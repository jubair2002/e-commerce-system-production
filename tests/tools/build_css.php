<?php
/**
 * CLI Tool: Build Storefront CSS
 * Concatenates all modular CSS files and writes a single optimized assets/css/shop.css
 */
if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

$rootDir = realpath(__DIR__ . '/../..');
$sourceDir = $rootDir . '/assets/css/shop';
$outputFile = $rootDir . '/assets/css/shop.css';

echo "Building storefront CSS...\n";

$files = [
    $sourceDir . '/00-tokens.css',
    $sourceDir . '/01-base.css',
    $sourceDir . '/02-layout.css',
    $sourceDir . '/03-utilities.css',
];

// Add components
foreach (glob($sourceDir . '/components/*.css') as $compFile) {
    $files[] = $compFile;
}

// Add pages
foreach (glob($sourceDir . '/pages/*.css') as $pageFile) {
    $files[] = $pageFile;
}

$combined = "/**\n * Jinispotro Storefront Compiled Stylesheet (V2/V3)\n * Built: " . date('c') . "\n */\n";

foreach ($files as $file) {
    if (file_exists($file)) {
        $combined .= "\n/* Source: " . basename($file) . " */\n";
        $combined .= file_get_contents($file) . "\n";
    }
}

// Lightweight minification: strip comments and redundant spacing
$minified = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $combined);
$minified = preg_replace('/\s+/', ' ', $minified);
$minified = preg_replace('/\s*([\{\}\:\;\,])\s*/', '$1', $minified);
$minified = str_replace(';}', '}', $minified);
$minified = trim($minified);

file_put_contents($outputFile, "/* Jinispotro CSS */\n" . $minified);

$rawSize = strlen($combined);
$minSize = strlen($minified);
$gzipSize = strlen(gzencode($minified, 9));

echo "✓ Compiled " . count($files) . " CSS files into assets/css/shop.css\n";
echo "  Raw: " . round($rawSize / 1024, 2) . " KB\n";
echo "  Minified: " . round($minSize / 1024, 2) . " KB (Budget: <= 70 KB)\n";
echo "  Gzipped: " . round($gzipSize / 1024, 2) . " KB\n";

if ($minSize / 1024 > 70) {
    echo "⚠ WARNING: Minified CSS exceeds 70 KB budget!\n";
    exit(1);
} else {
    echo "✓ CSS is well within performance budget!\n";
    exit(0);
}
