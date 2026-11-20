<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

// Hanya owner/admin yang boleh mengubah status pesanan.
require_admin();

$data = body();
$orderId = (int) ($data['order_id'] ?? 0);
$status  = trim((string) ($data['status'] ?? ''));
$note    = trim((string) ($data['note'] ?? ''));

$allowed = ['confirmed', 'rejected', 'cancelled', 'completed', 'expired'];
if ($orderId <= 0 || !in_array($status, $allowed, true)) {
    json_response(422, ['error' => 'Status tidak valid.']);
}

if (mb_strlen($note) > 255) {
    json_response(422, ['error' => 'Catatan maksimal 255 karakter.']);
}

try {
    $stmt = db()->prepare(
        'SELECT id, status, paid_at, property_id, start_date, days
         FROM orders WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        json_response(404, ['error' => 'Pesanan tidak ditemukan.']);
    }

    $current = (string) $order['status'];
    $transitions = [
        'pending_payment' => ['cancelled', 'expired', 'rejected'],
        'paid'            => ['confirmed', 'rejected'],
        'confirmed'       => ['completed', 'rejected'],
        'rejected'        => ['confirmed'],
        'expired'         => ['confirmed'],
        'cancelled'       => [],
        'completed'       => [],
    ];

    $next = $transitions[$current] ?? [];
    if (!in_array($status, $next, true)) {
        json_response(409, [
            'error' => 'Perubahan status tidak diizinkan dari "' . (order_status_labels()[$current] ?? $current) . '".',
        ]);
    }

    if ($status === 'confirmed' && $current === 'pending_payment') {
        json_response(409, ['error' => 'Pesanan belum dibayar.']);
    }

    // Saat mengaktifkan kembali pesanan rejected/expired, pastikan tanggalnya
    // belum diambil pesanan lain agar stok tidak melebihi kapasitas.
    if ($status === 'confirmed' && in_array($current, ['rejected', 'expired'], true)) {
        $availability = get_property_availability(
            (int) $order['property_id'],
            (string) $order['start_date'],
            (int) $order['days'],
            $orderId
        );

        if ($availability === null || !$availability['available']) {
            json_response(409, [
                'error' => 'Tanggal pesanan ini sudah diambil pesanan lain. Pesanan tidak dapat dikonfirmasi.',
            ]);
        }
    }

    $update = db()->prepare(
        'UPDATE orders SET status = ?, admin_note = COALESCE(NULLIF(?, \'\'), admin_note) WHERE id = ? AND status = ?'
    );
    $update->execute([$status, $note, $orderId, $current]);

    if ($update->rowCount() === 0) {
        json_response(409, ['error' => 'Status pesanan sudah berubah. Silakan muat ulang halaman.']);
    }

    json_response(200, [
        'message' => 'Status pesanan diperbarui.',
        'order_id' => $orderId,
        'status' => $status,
        'status_label' => order_status_labels()[$status] ?? $status,
    ]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
