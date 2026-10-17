<?php
/**
 * Production Media Storage Link Provisioner
 * Mirrors Laravel's 'php artisan storage:link' for instantaneous, zero-overhead static media delivery.
 *
 * Usage:
 *   CLI: php storage_link.php
 */

require_once __DIR__ . '/config/config.php';

$isCli = (php_sapi_name() === 'cli');

function output_line(string $msg, string $type = 'info'): void
{
    global $isCli;
    if ($isCli) {
        $colors = [
            'success' => "\033[32m[OK]\033[0m ",
            'error'   => "\033[31m[ERROR]\033[0m ",
            'warning' => "\033[33m[WARN]\033[0m ",
            'info'    => "\033[36m[INFO]\033[0m ",
        ];
        echo ($colors[$type] ?? '') . $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg) . "<br>\n";
    }
}

output_line('--- Media Storage Link Provisioner ---');
output_line('ROOT_PATH:    ' . ROOT_PATH);
output_line('UPLOADS_PATH: ' . UPLOADS_PATH);
output_line('UPLOADS_URL:  ' . UPLOADS_URL);

// 1. Verify / Create target storage directory
if (!is_dir(UPLOADS_PATH)) {
    if (@mkdir(UPLOADS_PATH, 0775, true)) {
        output_line('Created UPLOADS_PATH directory: ' . UPLOADS_PATH, 'success');
    } else {
        output_line('Failed to create UPLOADS_PATH directory: ' . UPLOADS_PATH . '. Please check filesystem permissions.', 'error');
    }
} else {
    output_line('Target UPLOADS_PATH directory exists.', 'success');
}

// 2. Ensure subdirectories exist
$subDirs = ['products', 'avatars', 'banners', 'branding', 'categories', 'cache'];
foreach ($subDirs as $sub) {
    $dir = UPLOADS_PATH . '/' . $sub;
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0775, true)) {
            output_line("Created subfolder: {$sub}/", 'success');
        } else {
            output_line("Could not create subfolder: {$sub}/", 'warning');
        }
    }
}

// 3. Create symlink: ROOT_PATH/uploads -> UPLOADS_PATH
$uploadsLink = ROOT_PATH . '/uploads';
if (UPLOADS_PATH === ROOT_PATH . '/assets/uploads') {
    output_line('Using default repository directory (assets/uploads). No external symlink required.', 'info');
} else {
    if (file_exists($uploadsLink) || is_link($uploadsLink)) {
        if (is_link($uploadsLink)) {
            $target = @readlink($uploadsLink);
            if ($target === UPLOADS_PATH) {
                output_line('Symlink already exists and correctly targets UPLOADS_PATH: ' . $uploadsLink, 'success');
            } else {
                output_line("Symlink exists but points to '{$target}'. Re-linking to '" . UPLOADS_PATH . "'...", 'warning');
                @unlink($uploadsLink);
                if (@symlink(UPLOADS_PATH, $uploadsLink)) {
                    output_line('Symlink successfully updated.', 'success');
                } else {
                    output_line('Failed to update symlink. Try running with elevated permissions.', 'error');
                }
            }
        } else {
            output_line("Path '{$uploadsLink}' exists as a normal file/directory, not a symlink.", 'warning');
        }
    } else {
        if (function_exists('symlink') && @symlink(UPLOADS_PATH, $uploadsLink)) {
            output_line("Successfully created symbolic link: [{$uploadsLink}] -> [" . UPLOADS_PATH . "]", 'success');
        } else {
            output_line("Could not create symlink automatically. You can manually run: ln -s " . escapeshellarg(UPLOADS_PATH) . " " . escapeshellarg($uploadsLink), 'warning');
        }
    }
}

// 4. Test writeability
$testFile = UPLOADS_PATH . '/.perm_test_' . time() . '.tmp';
if (@file_put_contents($testFile, 'test') !== false) {
    @unlink($testFile);
    output_line('Storage directory is writable by PHP process.', 'success');
} else {
    output_line('Storage directory is NOT writable by PHP process! Run: chown -R www-data:www-data ' . escapeshellarg(UPLOADS_PATH) . ' && chmod -R 775 ' . escapeshellarg(UPLOADS_PATH), 'error');
}

output_line('Storage provision check complete.', 'success');
