<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

$user = require_login();

if (!xendit_is_mock()) {
    http_response_code(403);
    exit('Mode simulasi tidak aktif.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$paymentId = (int) ($_POST['payment_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($paymentId <= 0 || !in_array($action, ['paid', 'expired'], true)) {
    http_response_code(422);
    exit('Data simulasi tidak valid.');
}

try {
    $stmt = db()->prepare(
        'SELECT pay.*, o.user_id, o.total AS order_total
         FROM payments pay
         JOIN orders o ON o.id = pay.order_id
         WHERE pay.id = ? AND o.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$paymentId, $user['id']]);
    $payment = $stmt->fetch();

    if (!$payment) {
        http_response_code(404);
        exit('Pembayaran tidak ditemukan.');
    }

    if (($payment['status'] ?? '') === 'PENDING') {
        if ($action === 'paid') {
            mark_payment_paid($payment, [
                'paid_amount' => (int) $payment['order_total'],
                'payment_method' => 'MOCK',
                'payment_channel' => 'MOCK_QRIS',
                'paid_at' => date('c'),
            ]);
            xendit_log('Simulasi pembayaran berhasil', [
                'payment_id' => $paymentId,
                'order_id' => (int) $payment['order_id'],
            ]);
        } else {
            mark_payment_expired($payment);
            xendit_log('Simulasi invoice kedaluwarsa', [
                'payment_id' => $paymentId,
                'order_id' => (int) $payment['order_id'],
            ]);
        }
    }

    header('Location: ../pembayaran.php?order_id=' . (int) $payment['order_id'] . '&status=' . ($action === 'paid' ? 'success' : 'expired'));
    exit;
} catch (Throwable $e) {
    xendit_log('Simulasi pembayaran gagal', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
    http_response_code(500);
    exit('Terjadi kesalahan saat memproses simulasi pembayaran.');
}
