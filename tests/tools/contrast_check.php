<?php
/**
 * CLI Tool: WCAG Contrast Checker (Phase B2)
 * Tests all text / background color pairs against WCAG AA standards (>= 4.5:1 for normal text, >= 3.0:1 for large text).
 */
if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
}

function relativeLuminance($rgb) {
    $r = $rgb[0] / 255;
    $g = $rgb[1] / 255;
    $b = $rgb[2] / 255;

    $r = ($r <= 0.03928) ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
    $g = ($g <= 0.03928) ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
    $b = ($b <= 0.03928) ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function contrastRatio($hex1, $hex2) {
    $lum1 = relativeLuminance(hexToRgb($hex1));
    $lum2 = relativeLuminance(hexToRgb($hex2));
    $brightest = max($lum1, $lum2);
    $darkest = min($lum1, $lum2);
    return ($brightest + 0.05) / ($darkest + 0.05);
}

$pairs = [
    // Light Theme Pairs
    ['name' => 'Light: Primary ink on white bg', 'fg' => '#111827', 'bg' => '#ffffff', 'req' => 4.5],
    ['name' => 'Light: Secondary ink-2 on white bg', 'fg' => '#4b5563', 'bg' => '#ffffff', 'req' => 4.5],
    ['name' => 'Light: Muted text on white bg', 'fg' => '#6b7280', 'bg' => '#ffffff', 'req' => 4.5],
    ['name' => 'Light: Primary ink on soft bg', 'fg' => '#111827', 'bg' => '#f8f9fa', 'req' => 4.5],
    ['name' => 'Light: Accent contrast on accent', 'fg' => '#ffffff', 'bg' => '#111827', 'req' => 4.5],
    ['name' => 'Light: Sale text on white bg', 'fg' => '#dc2626', 'bg' => '#ffffff', 'req' => 4.5],
    ['name' => 'Light: Ok/Success text on white bg', 'fg' => '#067647', 'bg' => '#ffffff', 'req' => 4.5],
    ['name' => 'Light: Warn text on white bg (large)', 'fg' => '#d97706', 'bg' => '#ffffff', 'req' => 3.0],

    // Dark Theme Pairs
    ['name' => 'Dark: Primary ink on dark bg', 'fg' => '#f3f4f6', 'bg' => '#0f1115', 'req' => 4.5],
    ['name' => 'Dark: Secondary ink-2 on dark bg', 'fg' => '#9ca3af', 'bg' => '#0f1115', 'req' => 4.5],
    ['name' => 'Dark: Primary ink on surface', 'fg' => '#f3f4f6', 'bg' => '#1e2129', 'req' => 4.5],
    ['name' => 'Dark: Accent contrast on dark accent', 'fg' => '#0f1115', 'bg' => '#f3f4f6', 'req' => 4.5],
    ['name' => 'Dark: Sale text on dark bg', 'fg' => '#ef4444', 'bg' => '#0f1115', 'req' => 4.5],
    ['name' => 'Dark: Ok text on dark bg', 'fg' => '#10b981', 'bg' => '#0f1115', 'req' => 4.5],
];

echo "=== Jinispotro WCAG AA Contrast Verification ===\n\n";
$fails = 0;
foreach ($pairs as $p) {
    $ratio = contrastRatio($p['fg'], $p['bg']);
    $ratioStr = number_format($ratio, 2) . ':1';
    $pass = $ratio >= $p['req'];
    if ($pass) {
        echo "  ✓ PASS: {$p['name']} ({$p['fg']} on {$p['bg']}) -> {$ratioStr} (min {$p['req']}:1)\n";
    } else {
        echo "  ✗ FAIL: {$p['name']} ({$p['fg']} on {$p['bg']}) -> {$ratioStr} (min {$p['req']}:1)\n";
        $fails++;
    }
}

echo "\nSummary: " . (count($pairs) - $fails) . "/" . count($pairs) . " passed.\n";
if ($fails === 0) {
    echo ">>> All design token contrast pairs meet WCAG AA requirements! <<<\n";
    exit(0);
} else {
    echo ">>> Contrast failures detected! <<<\n";
    exit(1);
}
