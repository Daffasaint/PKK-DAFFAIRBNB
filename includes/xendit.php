<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Helper integrasi Xendit Invoice.
 * Tidak membutuhkan Composer/SDK — cukup ekstensi cURL.
 */

function xendit_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/xendit.php';
    }
    return $config;
}

function xendit_is_mock(): bool
{
    return (bool) (xendit_config()['mock_mode'] ?? true);
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) xendit_config()['app_url'], '/');
    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function xendit_log(string $message, array $context = []): void
{
    try {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        @file_put_contents($dir . '/xendit.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Logging tidak boleh mengganggu proses pembayaran.
    }
}

/**
 * Request ke API Xendit.
 *
 * @return array{ok: bool, status: int, data: array, error: ?string}
 */
function xendit_request(string $method, string $path, ?array $payload = null): array
{
    $config = xendit_config();
    $url = rtrim((string) $config['base_url'], '/') . '/' . ltrim($path, '/');

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Gagal membuat koneksi cURL.'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERPWD        => (string) $config['secret_key'] . ':',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch) ?: null;
    curl_close($ch);

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        $data = [];
    }

    $ok = $error === null && $status >= 200 && $status < 300;

    if (!$ok) {
        xendit_log('API request gagal', [
            'method' => $method,
            'path'   => $path,
            'status' => $status,
            'error'  => $error,
            'body'   => $data,
        ]);
    }

    return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error];
}

/**
 * Buat invoice Xendit untuk sebuah order.
 *
 * @return array{ok: bool, status: int, data: array, error: ?string}
 */
function xendit_create_invoice(array $order, array $user): array
{
    $config = xendit_config();
    $orderId = (int) $order['id'];
    $externalId = 'DAFF-ORDER-' . $orderId . '-' . time() . '-' . bin2hex(random_bytes(2));

    $description = sprintf(
        'Sewa %s selama %d hari',
        (string) ($order['property_name'] ?? 'properti'),
        (int) ($order['days'] ?? 1)
    );

    $customer = [
        'given_names' => (string) ($user['full_name'] ?? 'Pelanggan'),
        'email'       => (string) ($user['email'] ?? ''),
    ];
    if (!empty($user['phone'])) {
        $customer['mobile_number'] = (string) $user['phone'];
    }

    $payload = [
        'external_id'          => $externalId,
        'amount'               => (int) $order['total'],
        'payer_email'          => (string) ($user['email'] ?? ''),
        'description'          => $description,
        'invoice_duration'     => (int) $config['invoice_duration'],
        'currency'             => 'IDR',
        'success_redirect_url' => app_url('api/xendit_return.php?order_id=' . $orderId . '&status=success'),
        'failure_redirect_url' => app_url('api/xendit_return.php?order_id=' . $orderId . '&status=failed'),
        'should_send_email'    => false,
        'items'                => [[
            'name'     => mb_substr($description, 0, 100),
            'quantity' => 1,
            'price'    => (int) $order['total'],
        ]],
        'customer'             => $customer,
        'metadata'             => [
            'order_id' => $orderId,
            'user_id'  => (int) ($user['id'] ?? 0),
        ],
    ];

    $result = xendit_request('POST', '/v2/invoices', $payload);
    if ($result['ok']) {
        $result['data']['external_id'] = $result['data']['external_id'] ?? $externalId;
    }

    return $result;
}

/**
 * Ambil status invoice dari Xendit.
 */
function xendit_get_invoice(string $invoiceId): array
{
    return xendit_request('GET', '/v2/invoices/' . rawurlencode($invoiceId));
}

function xendit_expire_invoice(string $invoiceId): array
{
    return xendit_request('POST', '/invoices/' . rawurlencode($invoiceId) . '/expire!');
}

function xendit_callback_token_valid(?string $token): bool
{
    $expected = trim((string) (xendit_config()['callback_token'] ?? ''));
    if ($expected === '') {
        return false;
    }
    return $token !== null && hash_equals($expected, $token);
}
