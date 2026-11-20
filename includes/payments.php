<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/xendit.php';

function order_status_labels(): array
{
    return [
        'pending_payment' => 'Menunggu Pembayaran',
        'paid'            => 'Sudah Dibayar',
        'confirmed'       => 'Dikonfirmasi',
        'rejected'        => 'Ditolak',
        'expired'         => 'Kedaluwarsa',
        'cancelled'       => 'Dibatalkan',
        'completed'       => 'Selesai',
    ];
}

function payment_status_labels(): array
{
    return [
        'PENDING' => 'Menunggu',
        'PAID'    => 'Lunas',
        'EXPIRED' => 'Kedaluwarsa',
        'FAILED'  => 'Gagal',
    ];
}

function payment_method_label(?string $method, ?string $channel = null): string
{
    $channel = trim((string) $channel);
    if ($channel !== '') {
        $map = [
            'QRIS'          => 'QRIS',
            'BCA'           => 'VA BCA',
            'BNI'           => 'VA BNI',
            'BRI'           => 'VA BRI',
            'MANDIRI'       => 'VA Mandiri',
            'PERMATA'       => 'VA Permata',
            'OVO'           => 'OVO',
            'DANA'          => 'DANA',
            'SHOPEEPAY'     => 'ShopeePay',
            'LINKAJA'       => 'LinkAja',
            'ALFAMART'      => 'Alfamart',
            'INDOMARET'     => 'Indomaret',
            'MOCK_QRIS'     => 'QRIS (Simulasi)',
        ];
        return $map[strtoupper($channel)] ?? ucwords(strtolower(str_replace('_', ' ', $channel)));
    }

    $method = strtoupper(trim((string) $method));
    $map = [
        'QRIS'          => 'QRIS',
        'BANK_TRANSFER' => 'Transfer Bank',
        'EWALLET'       => 'E-Wallet',
        'CREDIT_CARD'   => 'Kartu Kredit',
        'RETAIL_OUTLET' => 'Gerai Retail',
        'MOCK'          => 'Simulasi',
        'LEGACY'        => 'Manual',
    ];

    return $map[$method] ?? ($method !== '' ? ucwords(strtolower(str_replace('_', ' ', $method))) : 'Xendit');
}

function to_mysql_datetime(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Tandai order pending_payment yang sudah melewati batas sebagai expired.
 */
function expire_pending_payments(?int $userId = null): void
{
    try {
        $sql = "UPDATE orders
                SET status = 'expired'
                WHERE status = 'pending_payment'
                  AND payment_expires_at IS NOT NULL
                  AND payment_expires_at < NOW()";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        // Jangan hentikan halaman hanya karena proses expiry gagal.
        xendit_log('Gagal expire pending payments', ['error' => $e->getMessage()]);
    }
}

function latest_payment_for_order(int $orderId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_order_with_payment(int $orderId): ?array
{
    $stmt = db()->prepare(
        "SELECT o.*, p.name AS property_name, p.location AS property_location,
                p.image_main AS property_image,
                u.full_name AS user_name, u.email AS user_email, u.phone AS user_phone,
                pay.id AS payment_row_id, pay.invoice_id, pay.invoice_url,
                pay.external_id AS payment_external_id, pay.status AS payment_status,
                pay.payment_method, pay.payment_channel, pay.paid_at AS payment_paid_at
         FROM orders o
         JOIN properties p ON p.id = o.property_id
         JOIN users u      ON u.id = o.user_id
         LEFT JOIN payments pay ON pay.id = (
             SELECT p2.id FROM payments p2 WHERE p2.order_id = o.id ORDER BY p2.id DESC LIMIT 1
         )
         WHERE o.id = ?
         LIMIT 1"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Terapkan status PAID dari Xendit (atau simulasi) ke payment + order.
 * Idempotent: aman dipanggil berulang kali.
 */
function mark_payment_paid(array $payment, array $xenditData = []): void
{
    $paymentId = (int) $payment['id'];
    $orderId = (int) $payment['order_id'];
    $paidAt = to_mysql_datetime(isset($xenditData['paid_at']) ? (string) $xenditData['paid_at'] : null)
        ?? date('Y-m-d H:i:s');
    $method = $xenditData['payment_method'] ?? null;
    $channel = $xenditData['payment_channel'] ?? null;
    $paidAmount = isset($xenditData['paid_amount']) ? (int) $xenditData['paid_amount'] : (int) $payment['amount'];

    if ($paidAmount < (int) $payment['amount']) {
        xendit_log('Nominal pembayaran tidak sesuai', [
            'payment_id' => $paymentId,
            'expected'   => (int) $payment['amount'],
            'paid'       => $paidAmount,
        ]);
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $updatePayment = $pdo->prepare(
            "UPDATE payments
             SET status = 'PAID',
                 payment_method = COALESCE(?, payment_method),
                 payment_channel = COALESCE(?, payment_channel),
                 paid_at = COALESCE(?, paid_at),
                 raw_payload = ?
             WHERE id = ? AND status <> 'PAID'"
        );
        $updatePayment->execute([
            $method,
            $channel,
            $paidAt,
            json_encode($xenditData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $paymentId,
        ]);

        // Jika order sudah kedaluwarsa (atau hold pembayarannya sudah lewat),
        // pastikan tanggalnya belum diambil user lain sebelum diaktifkan lagi.
        $orderStmt = $pdo->prepare(
            'SELECT status, property_id, start_date, days, payment_expires_at
             FROM orders WHERE id = ? LIMIT 1'
        );
        $orderStmt->execute([$orderId]);
        $orderRow = $orderStmt->fetch();

        if ($orderRow && in_array((string) $orderRow['status'], ['pending_payment', 'expired'], true)) {
            $holdStillActive = ((string) $orderRow['status'] === 'pending_payment')
                && (empty($orderRow['payment_expires_at'])
                    || strtotime((string) $orderRow['payment_expires_at']) > time());

            if (!$holdStillActive) {
                $availability = get_property_availability(
                    (int) $orderRow['property_id'],
                    (string) $orderRow['start_date'],
                    (int) $orderRow['days'],
                    $orderId
                );

                if ($availability === null || !$availability['available']) {
                    $noteStmt = $pdo->prepare(
                        "UPDATE orders
                         SET status = 'expired',
                             admin_note = 'Pembayaran diterima setelah batas waktu dan tanggal sudah terisi. Perlu refund manual.'
                         WHERE id = ?"
                    );
                    $noteStmt->execute([$orderId]);
                    $pdo->commit();

                    xendit_log('Pembayaran diterima tetapi tanggal sudah terisi', [
                        'payment_id' => $paymentId,
                        'order_id'   => $orderId,
                        'property_id' => (int) $orderRow['property_id'],
                        'start_date' => (string) $orderRow['start_date'],
                        'days'       => (int) $orderRow['days'],
                    ]);
                    return;
                }
            }
        }

        $autoConfirm = (bool) (xendit_config()['auto_confirm'] ?? false);
        $newStatus = $autoConfirm ? 'confirmed' : 'paid';

        $updateOrder = $pdo->prepare(
            "UPDATE orders
             SET status = ?,
                 paid_at = COALESCE(paid_at, ?)
             WHERE id = ?
               AND status IN ('pending_payment', 'expired')"
        );
        $updateOrder->execute([$newStatus, $paidAt, $orderId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        xendit_log('Gagal menyimpan pembayaran PAID', [
            'payment_id' => $paymentId,
            'order_id'   => $orderId,
            'error'      => $e->getMessage(),
        ]);
        throw $e;
    }
}

/**
 * Terapkan status EXPIRED dari Xendit (atau simulasi).
 */
function mark_payment_expired(array $payment): void
{
    $paymentId = (int) $payment['id'];
    $orderId = (int) $payment['order_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE payments SET status = 'EXPIRED' WHERE id = ? AND status = 'PENDING'");
        $stmt->execute([$paymentId]);

        $paidCheck = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE order_id = ? AND status = 'PAID'");
        $paidCheck->execute([$orderId]);

        if ((int) $paidCheck->fetchColumn() === 0) {
            $updateOrder = $pdo->prepare(
                "UPDATE orders SET status = 'expired' WHERE id = ? AND status = 'pending_payment'"
            );
            $updateOrder->execute([$orderId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        xendit_log('Gagal menyimpan pembayaran EXPIRED', [
            'payment_id' => $paymentId,
            'order_id'   => $orderId,
            'error'      => $e->getMessage(),
        ]);
        throw $e;
    }
}

/**
 * Sinkronkan status invoice Xendit ke database.
 *
 * @return array{ok: bool, status: string, error?: string}
 */
function sync_xendit_invoice(array $payment): array
{
    if (($payment['provider'] ?? '') !== 'xendit' || xendit_is_mock()) {
        return ['ok' => true, 'status' => (string) ($payment['status'] ?? 'PENDING')];
    }

    $invoiceId = trim((string) ($payment['invoice_id'] ?? ''));
    if ($invoiceId === '') {
        return ['ok' => false, 'status' => (string) ($payment['status'] ?? 'PENDING'), 'error' => 'Invoice ID kosong.'];
    }

    $result = xendit_get_invoice($invoiceId);
    if (!$result['ok']) {
        return [
            'ok'    => false,
            'status' => (string) ($payment['status'] ?? 'PENDING'),
            'error' => (string) ($result['data']['message'] ?? $result['error'] ?? 'Gagal menghubungi Xendit.'),
        ];
    }

    $data = $result['data'];
    $status = strtoupper((string) ($data['status'] ?? ''));
    if (in_array($status, ['PAID', 'SETTLED'], true)) {
        mark_payment_paid($payment, $data);
        return ['ok' => true, 'status' => 'PAID'];
    }
    if ($status === 'EXPIRED') {
        mark_payment_expired($payment);
        return ['ok' => true, 'status' => 'EXPIRED'];
    }

    return ['ok' => true, 'status' => $status !== '' ? $status : 'PENDING'];
}

function order_payment_url(int $orderId): string
{
    return 'pembayaran.php?order_id=' . $orderId;
}

function can_pay_order(array $order): bool
{
    if (($order['status'] ?? '') !== 'pending_payment') {
        return false;
    }
    $expires = $order['payment_expires_at'] ?? null;
    if ($expires === null || $expires === '') {
        return true;
    }
    return strtotime((string) $expires) > time();
}
