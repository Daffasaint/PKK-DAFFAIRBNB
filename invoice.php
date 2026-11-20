<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/pricing.php';

require_session();
$user = require_login();
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(404);
    exit('Pesanan tidak ditemukan. <a href="index.php">Kembali</a>');
}

$isAdmin = ($user['role'] ?? '') === 'admin';
$order = get_order_with_payment($orderId);

if (!$order) {
    http_response_code(404);
    exit('Pesanan tidak ditemukan. <a href="index.php">Kembali</a>');
}

if (!$isAdmin && (int) $order['user_id'] !== (int) $user['id']) {
    http_response_code(403);
    exit('Akses ditolak. <a href="index.php">Kembali</a>');
}

$status = (string) $order['status'];
$paymentStatus = (string) ($order['payment_status'] ?? '');
$isPaid = in_array($status, ['paid', 'confirmed', 'completed'], true) || $paymentStatus === 'PAID';

if (!$isPaid) {
    http_response_code(403);
    exit('Invoice tersedia setelah pembayaran berhasil. <a href="pembayaran.php?order_id=' . $orderId . '">Kembali ke pembayaran</a>');
}

$statusLabels = order_status_labels();
$statusLabel = $statusLabels[$status] ?? $status;
$breakdown = order_price_breakdown($order);
$baseTotal = (int) $breakdown['base_total'];
$discountTotal = (int) $breakdown['discount_total'];
$serviceFee = (int) $breakdown['service_fee'];
$total = (int) $breakdown['total'];
$detail = is_array($breakdown['pricing_detail']) ? $breakdown['pricing_detail'] : null;
$daily = is_array($detail['daily'] ?? null) ? $detail['daily'] : [];
$guestSummary = is_array($detail['guests'] ?? null) ? (string) ($detail['guest_summary'] ?? '') : '';
$destinationLabel = (string) ($detail['destination']['label'] ?? '');
$checkinTime = (string) ($detail['destination']['checkin_time'] ?? '');
$checkoutTime = (string) ($detail['destination']['checkout_time'] ?? '');

$paidAtRaw = !empty($order['paid_at']) ? (string) $order['paid_at'] : (!empty($order['payment_paid_at']) ? (string) $order['payment_paid_at'] : null);
$invoiceDate = $paidAtRaw ?? (string) $order['created_at'];
$invoiceTimestamp = strtotime($invoiceDate) ?: time();
$invoiceNumber = 'INV/DAFF/' . date('Y', $invoiceTimestamp) . '/' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
$paidAtLabel = $paidAtRaw !== null ? date('d M Y H:i', strtotime($paidAtRaw)) : '-';
$paymentMethod = payment_method_label($order['payment_method'] ?? null, $order['payment_channel'] ?? null);
$paymentStatusLabel = payment_status_labels()[$paymentStatus] ?? $paymentStatus;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($invoiceNumber); ?> - DAFF AIRBNB</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo e((string) filemtime(__DIR__ . '/styles.css')); ?>">
    <link rel="stylesheet" href="invoice.css?v=<?php echo e((string) filemtime(__DIR__ . '/invoice.css')); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="invoice-body">
    <div class="invoice-actions">
        <a href="pembayaran.php?order_id=<?php echo $orderId; ?>" class="btn-outline invoice-back">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" class="btn-invoice-print" id="printInvoiceBtn">
            <i class="fas fa-print"></i> Cetak / Simpan PDF
        </button>
    </div>

    <main class="invoice-shell">
        <article class="invoice-card">
            <header class="invoice-head">
                <div class="invoice-brand">
                    <img src="image-removebg-preview.png" alt="DAFF AIRBNB">
                    <div>
                        <strong>DAFF AIRBNB</strong>
                        <span>Platform sewa rumah &amp; villa</span>
                        <span>Indonesia</span>
                    </div>
                </div>
                <div class="invoice-heading">
                    <span>INVOICE</span>
                    <strong><?php echo e($invoiceNumber); ?></strong>
                    <small>Tanggal: <?php echo e(date('d M Y', $invoiceTimestamp)); ?></small>
                </div>
            </header>

            <?php if ($isPaid): ?>
                <div class="invoice-paid-stamp"><i class="fas fa-circle-check"></i> LUNAS</div>
            <?php endif; ?>

            <section class="invoice-meta">
                <div>
                    <h3>Ditagihkan kepada</h3>
                    <p><strong><?php echo e($order['user_name']); ?></strong></p>
                    <p><?php echo e($order['user_email']); ?></p>
                    <p><?php echo !empty($order['user_phone']) ? e($order['user_phone']) : '-'; ?></p>
                </div>
                <div>
                    <h3>Detail Pesanan</h3>
                    <p><span>No. Pesanan</span><strong>#<?php echo $orderId; ?></strong></p>
                    <p><span>Properti</span><strong><?php echo e($order['property_name']); ?></strong></p>
                    <p><span>Lokasi</span><strong><?php echo e($order['property_location']); ?></strong></p>
                    <?php if ($destinationLabel !== ''): ?>
                        <p><span>Destinasi</span><strong><?php echo e($destinationLabel); ?></strong></p>
                    <?php endif; ?>
                    <p><span>Check-in / Check-out</span><strong><?php echo e(format_stay_range_id((string) $order['start_date'], (int) $order['days'])); ?></strong></p>
                    <p><span>Durasi</span><strong><?php echo (int) $order['days']; ?> malam</strong></p>
                    <?php if ($guestSummary !== ''): ?>
                        <p><span>Tamu</span><strong><?php echo e($guestSummary); ?></strong></p>
                    <?php endif; ?>
                    <?php if ($checkinTime !== '' && $checkoutTime !== ''): ?>
                        <p><span>Waktu</span><strong>Check-in <?php echo e($checkinTime); ?> &middot; Check-out <?php echo e($checkoutTime); ?></strong></p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3>Pembayaran</h3>
                    <p><span>Status pesanan</span><strong><?php echo e($statusLabel); ?></strong></p>
                    <p><span>Status bayar</span><strong><?php echo e($paymentStatusLabel !== '' ? $paymentStatusLabel : 'Lunas'); ?></strong></p>
                    <p><span>Metode</span><strong><?php echo e($paymentMethod); ?></strong></p>
                    <p><span>Dibayar pada</span><strong><?php echo e($paidAtLabel); ?></strong></p>
                </div>
            </section>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Deskripsi</th>
                        <th class="invoice-amount">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            Biaya sewa <?php echo e($order['property_name']); ?>
                            <small><?php echo (int) $order['days']; ?> malam &middot; <?php echo e(format_stay_range_id((string) $order['start_date'], (int) $order['days'])); ?></small>
                        </td>
                        <td class="invoice-amount">Rp <?php echo number_format($baseTotal, 0, ',', '.'); ?></td>
                    </tr>
                    <?php if ($discountTotal > 0): ?>
                        <tr>
                            <td>
                                Diskon durasi
                                <?php if (!empty($detail['discount_percent'])): ?>
                                    <small><?php echo e((string) $detail['discount_percent']); ?>%</small>
                                <?php endif; ?>
                            </td>
                            <td class="invoice-amount invoice-discount">-Rp <?php echo number_format($discountTotal, 0, ',', '.'); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Biaya layanan (5%)</td>
                        <td class="invoice-amount">Rp <?php echo number_format($serviceFee, 0, ',', '.'); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="invoice-amount">Rp <?php echo number_format($total, 0, ',', '.'); ?></td>
                    </tr>
                </tfoot>
            </table>

            <?php if ($daily): ?>
                <section class="invoice-daily">
                    <h3>Rincian Harga per Tanggal</h3>
                    <div class="invoice-daily-grid">
                        <?php foreach ($daily as $item): ?>
                            <div class="invoice-daily-item">
                                <span>
                                    <?php echo e(format_date_short_id((string) $item['date'])); ?>
                                    <small><?php echo !empty($item['is_weekend']) ? 'Akhir pekan' : 'Hari biasa'; ?></small>
                                </span>
                                <strong>Rp <?php echo number_format((int) $item['price'], 0, ',', '.'); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <footer class="invoice-foot">
                <p>Terima kasih telah mempercayakan penginapan Anda kepada DAFF AIRBNB.</p>
                <small>Dokumen ini dihasilkan otomatis dan sah sebagai bukti pesanan.</small>
            </footer>
        </article>
    </main>

    <script>
        document.getElementById('printInvoiceBtn').addEventListener('click', () => window.print());
    </script>
</body>
</html>
