<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    json_response(405, ['error' => 'Method not allowed.']);
}

$user = require_login();
$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? body() : $_GET;
$orderId = (int) ($data['order_id'] ?? 0);

if ($orderId <= 0) {
    json_response(422, ['error' => 'Order tidak valid.']);
}

expire_pending_payments(($user['role'] ?? '') === 'admin' ? null : (int) $user['id']);

try {
    $order = get_order_with_payment($orderId);
    if (!$order) {
        json_response(404, ['error' => 'Pesanan tidak ditemukan.']);
    }

    $isAdmin = ($user['role'] ?? '') === 'admin';
    if (!$isAdmin && (int) $order['user_id'] !== (int) $user['id']) {
        json_response(403, ['error' => 'Akses ditolak.']);
    }

    $payment = latest_payment_for_order($orderId);
    if ($payment && ($payment['status'] ?? '') === 'PENDING') {
        $sync = sync_xendit_invoice($payment);
        if (!$sync['ok']) {
            xendit_log('Sinkronisasi invoice gagal', [
                'order_id' => $orderId,
                'payment_id' => (int) $payment['id'],
                'error' => $sync['error'] ?? null,
            ]);
        }
        $order = get_order_with_payment($orderId);
    }

    $labels = order_status_labels();
    $status = (string) ($order['status'] ?? '');
    $message = match ($status) {
        'pending_payment' => 'Pesanan belum dibayar. Silakan lanjutkan pembayaran.',
        'paid' => 'Pembayaran berhasil. Menunggu konfirmasi owner.',
        'confirmed' => 'Pesanan sudah dikonfirmasi owner.',
        'expired' => 'Batas waktu pembayaran sudah habis.',
        'cancelled' => 'Pesanan dibatalkan.',
        'rejected' => 'Pesanan ditolak.',
        'completed' => 'Pesanan selesai.',
        default => 'Status pesanan diperbarui.',
    };

    json_response(200, [
        'order_id' => $orderId,
        'status' => $status,
        'status_label' => $labels[$status] ?? $status,
        'message' => $message,
        'payment_status' => $order['payment_status'] ?? null,
        'payment_method' => $order['payment_method'] ?? null,
        'payment_channel' => $order['payment_channel'] ?? null,
        'invoice_url' => $order['invoice_url'] ?? null,
        'can_pay' => can_pay_order($order),
        'seconds_left' => (!empty($order['payment_expires_at']) && $status === 'pending_payment')
            ? max(0, strtotime((string) $order['payment_expires_at']) - time())
            : 0,
        'paid_at' => $order['paid_at'] ?? null,
    ]);
} catch (PDOException $e) {
    xendit_log('Gagal cek pembayaran', ['order_id' => $orderId, 'error' => $e->getMessage()]);
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
