<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

$user = require_login();
$orderId = (int) ($_GET['order_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

if ($orderId <= 0) {
    header('Location: ../index.php');
    exit;
}

$order = get_order_with_payment($orderId);
if (!$order || (int) $order['user_id'] !== (int) $user['id']) {
    header('Location: ../index.php');
    exit;
}

$payment = latest_payment_for_order($orderId);
$message = $status === 'failed'
    ? 'Pembayaran belum selesai. Anda dapat mencoba lagi.'
    : 'Pembayaran sedang diverifikasi.';

if ($payment) {
    $sync = sync_xendit_invoice($payment);
    if ($sync['ok'] && $sync['status'] === 'PAID') {
        $message = 'Pembayaran berhasil. Pesanan Anda menunggu konfirmasi owner.';
    } elseif ($sync['ok'] && $sync['status'] === 'EXPIRED') {
        $message = 'Invoice sudah kedaluwarsa. Silakan buat pesanan baru.';
    } elseif (!$sync['ok']) {
        $message = 'Pembayaran belum terkonfirmasi. Klik "Cek Status" beberapa saat lagi.';
    }
}

$_SESSION['payment_flash'] = $message;
header('Location: ../pembayaran.php?order_id=' . $orderId);
exit;
