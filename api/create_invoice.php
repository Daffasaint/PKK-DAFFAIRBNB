<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$user = require_login();
$data = body();
$orderId = (int) ($data['order_id'] ?? 0);

if ($orderId <= 0) {
    json_response(422, ['error' => 'Order tidak valid.']);
}

expire_pending_payments((int) $user['id']);

try {
    $stmt = db()->prepare(
        'SELECT o.*, p.name AS property_name
         FROM orders o
         JOIN properties p ON p.id = o.property_id
         WHERE o.id = ? AND o.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$orderId, $user['id']]);
    $order = $stmt->fetch();

    if (!$order) {
        json_response(404, ['error' => 'Pesanan tidak ditemukan.']);
    }

    if (($order['status'] ?? '') !== 'pending_payment') {
        $labels = order_status_labels();
        json_response(409, [
            'error' => 'Pesanan tidak dapat dibayar karena statusnya: ' . ($labels[$order['status']] ?? $order['status']) . '.',
        ]);
    }

    if (!can_pay_order($order)) {
        $expire = db()->prepare("UPDATE orders SET status = 'expired' WHERE id = ? AND status = 'pending_payment'");
        $expire->execute([$orderId]);
        json_response(410, ['error' => 'Batas waktu pembayaran sudah habis. Silakan buat pesanan baru.']);
    }

    // Gunakan invoice yang masih aktif agar klik berulang tidak membuat invoice baru.
    $existing = latest_payment_for_order($orderId);
    if ($existing && ($existing['status'] ?? '') === 'PENDING' && !empty($existing['invoice_url'])) {
        $duration = max(300, (int) xendit_config()['invoice_duration']);
        $createdAt = strtotime((string) $existing['created_at']);
        if ($createdAt !== false && ($createdAt + $duration) > time()) {
            json_response(200, [
                'message' => 'Lanjutkan pembayaran pesanan Anda.',
                'invoice_url' => $existing['invoice_url'],
                'invoice_id' => $existing['invoice_id'],
                'mock' => xendit_is_mock(),
                'reused' => true,
                'expires_at' => $order['payment_expires_at'],
            ]);
        }

        if (!xendit_is_mock() && !empty($existing['invoice_id'])) {
            // Cegah invoice lama tetap bisa dibayar setelah diganti.
            xendit_expire_invoice((string) $existing['invoice_id']);
        }
        mark_payment_expired($existing);
    }

    $externalId = 'DAFF-ORDER-' . $orderId . '-' . time() . '-' . bin2hex(random_bytes(2));
    $invoiceId = null;
    $invoiceUrl = null;
    $rawPayload = null;

    if (xendit_is_mock()) {
        $invoiceId = 'mock_' . bin2hex(random_bytes(8));
        $rawPayload = json_encode([
            'mock' => true,
            'order_id' => $orderId,
            'amount' => (int) $order['total'],
            'created_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $result = xendit_create_invoice($order, $user);
        if (!$result['ok']) {
            $message = (string) ($result['data']['message'] ?? $result['error'] ?? 'Gagal membuat invoice Xendit.');
            json_response(502, ['error' => $message]);
        }

        $response = $result['data'];
        $invoiceId = (string) ($response['id'] ?? '');
        $invoiceUrl = (string) ($response['invoice_url'] ?? '');
        $externalId = (string) ($response['external_id'] ?? $externalId);
        $rawPayload = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($invoiceId === '' || $invoiceUrl === '') {
            json_response(502, ['error' => 'Respons Xendit tidak lengkap.']);
        }
    }

    $insert = db()->prepare(
        'INSERT INTO payments
            (order_id, provider, external_id, invoice_id, invoice_url, amount, status, raw_payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $orderId,
        'xendit',
        $externalId,
        $invoiceId,
        $invoiceUrl,
        (int) $order['total'],
        'PENDING',
        $rawPayload,
    ]);
    $paymentId = (int) db()->lastInsertId();

    if (xendit_is_mock()) {
        $invoiceUrl = 'mock_xendit.php?payment_id=' . $paymentId;
        $update = db()->prepare('UPDATE payments SET invoice_url = ? WHERE id = ?');
        $update->execute([$invoiceUrl, $paymentId]);

        xendit_log('Invoice simulasi dibuat', [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'amount' => (int) $order['total'],
        ]);
    }

    json_response(200, [
        'message' => xendit_is_mock()
            ? 'Mode simulasi aktif. Lanjutkan ke halaman pembayaran simulasi.'
            : 'Invoice Xendit berhasil dibuat.',
        'invoice_url' => $invoiceUrl,
        'invoice_id' => $invoiceId,
        'mock' => xendit_is_mock(),
        'reused' => false,
        'expires_at' => $order['payment_expires_at'],
    ]);
} catch (PDOException $e) {
    xendit_log('Gagal membuat invoice', ['order_id' => $orderId, 'error' => $e->getMessage()]);
    json_response(500, ['error' => 'Terjadi kesalahan server saat membuat invoice.']);
}
