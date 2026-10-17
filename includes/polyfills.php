<?php
/**
 * Polyfills for PHP 8.0+ functions (str_contains, str_starts_with, str_ends_with)
 * Ensures robust execution across PHP 7.4 and 8.x.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || mb_strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || mb_substr($haystack, 0, mb_strlen($needle)) === $needle;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        $diff = mb_strlen($haystack) - mb_strlen($needle);
        return $diff >= 0 && mb_substr($haystack, $diff) === $needle;
    }
}
