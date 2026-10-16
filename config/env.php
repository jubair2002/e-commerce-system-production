<?php
/**
 * Simple, zero-dependency .env file loader for plain PHP 7.4+.
 */

if (!function_exists('load_env')) {
    function load_env(string $path): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Ignore empty lines or comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Split on first '='
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = trim($parts[0]);
            $value = trim($parts[1]);

            // Strip inline comments if value is not quoted
            $len = strlen($value);
            if ($len >= 2 && (
                ($value[0] === '"' && $value[$len - 1] === '"') ||
                ($value[0] === "'" && $value[$len - 1] === "'")
            )) {
                $value = substr($value, 1, -1);
            } else {
                // If there's an unquoted # comment at the end
                if (($commentPos = strpos($value, ' #')) !== false) {
                    $value = trim(substr($value, 0, $commentPos));
                }
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        $lower = strtolower((string)$val);
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;
        if ($lower === 'null')  return null;
        return $val;
    }
}

// Auto-load root .env if present
load_env(dirname(__DIR__) . '/.env');
