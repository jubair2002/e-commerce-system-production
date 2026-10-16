<?php
/**
 * Application encryption / decryption helpers using AES-256-CBC.
 */
require_once __DIR__ . '/../config/config.php';

function app_crypto_key(): string
{
    $key = defined('APP_KEY') ? APP_KEY : 'CHANGE_ME_TO_64_RANDOM_CHARS';
    return hash('sha256', $key, true);
}

function app_encrypt(string $plain): string
{
    $key = app_crypto_key();
    $iv  = random_bytes(16);
    $raw = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($raw === false) {
        return '';
    }
    return base64_encode($iv . $raw);
}

function app_decrypt(string $cipher): string
{
    $data = base64_decode($cipher, true);
    if ($data === false || strlen($data) <= 16) {
        return '';
    }
    $key = app_crypto_key();
    $iv  = substr($data, 0, 16);
    $raw = substr($data, 16);
    $plain = openssl_decrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
