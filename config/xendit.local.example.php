<?php
declare(strict_types=1);

/**
 * Salin file ini menjadi config/xendit.local.php, lalu isi kredensial
 * dari dashboard Xendit (Settings > Developers > API Keys / Callbacks).
 *
 * Setelah secret key diisi, mode simulasi otomatis nonaktif.
 */
return [
    'secret_key'       => 'xnd_development_XXXXXXXXXXXXXXXXXXXXXXXX',
    'callback_token'   => 'ISI_CALLBACK_VERIFICATION_TOKEN',
    'base_url'         => 'https://api.xendit.co',
    'invoice_duration' => 86400,
    'auto_confirm'     => false,
    'app_url'          => 'http://localhost/PKK',
];
