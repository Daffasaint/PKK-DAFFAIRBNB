<?php
declare(strict_types=1);

/**
 * Konfigurasi Xendit.
 *
 * Untuk mode produksi/test Xendit asli, isi XENDIT_SECRET_KEY dan
 * XENDIT_CALLBACK_TOKEN melalui environment variable, atau buat file
 * config/xendit.local.php yang mengembalikan array dengan struktur sama.
 *
 * Selama secret key masih kosong/placeholder, aplikasi otomatis memakai
 * mode simulasi lokal agar alur pembayaran tetap bisa didemokan.
 */

$localFile = __DIR__ . '/xendit.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $config = array_replace([
            'secret_key'       => '',
            'callback_token'   => '',
            'base_url'         => 'https://api.xendit.co',
            'invoice_duration' => 86400,
            'auto_confirm'     => false,
            'app_url'          => 'http://localhost/PKK',
        ], $local);

        if (!isset($config['mock_mode'])) {
            $localKey = trim((string) $config['secret_key']);
            $config['mock_mode'] = $localKey === ''
                || str_contains($localKey, 'YOUR_')
                || str_contains($localKey, 'REPLACE')
                || str_contains($localKey, 'XXXX');
        }

        return $config;
    }
}

$secretKey = trim((string) (getenv('XENDIT_SECRET_KEY') ?: ''));
$placeholder = $secretKey === ''
    || str_contains($secretKey, 'YOUR_')
    || str_contains($secretKey, 'REPLACE')
    || str_contains($secretKey, 'XXXX');

return [
    'secret_key'       => $secretKey,
    'callback_token'   => trim((string) (getenv('XENDIT_CALLBACK_TOKEN') ?: '')),
    'base_url'         => rtrim((string) (getenv('XENDIT_BASE_URL') ?: 'https://api.xendit.co'), '/'),
    'invoice_duration' => max(300, (int) (getenv('XENDIT_INVOICE_DURATION') ?: 86400)),
    'auto_confirm'     => filter_var(getenv('XENDIT_AUTO_CONFIRM') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'app_url'          => rtrim((string) (getenv('APP_URL') ?: 'http://localhost/PKK'), '/'),
    'mock_mode'        => $placeholder,
];
