<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

require_session();
$user = require_login();

if (!xendit_is_mock()) {
    http_response_code(403);
    exit('Mode simulasi tidak aktif. <a href="index.php">Kembali</a>');
}

$paymentId = (int) ($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Pembayaran tidak ditemukan. <a href="index.php">Kembali</a>');
}

$stmt = db()->prepare(
    'SELECT pay.*, o.user_id, o.status AS order_status, o.days, o.start_date, o.total AS order_total,
            o.pricing_detail,
            p.name AS property_name, p.location AS property_location
     FROM payments pay
     JOIN orders o ON o.id = pay.order_id
     JOIN properties p ON p.id = o.property_id
     WHERE pay.id = ? AND o.user_id = ?
     LIMIT 1'
);
$stmt->execute([$paymentId, $user['id']]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Pembayaran tidak ditemukan. <a href="index.php">Kembali</a>');
}

$mockPricingDetail = !empty($payment['pricing_detail']) ? json_decode((string) $payment['pricing_detail'], true) : null;
$mockGuestSummary = is_array($mockPricingDetail['guests'] ?? null)
    ? (string) ($mockPricingDetail['guest_summary'] ?? '')
    : '';

if (($payment['status'] ?? '') !== 'PENDING') {
    header('Location: pembayaran.php?order_id=' . (int) $payment['order_id']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Pembayaran - DAFF AIRBNB</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="payment.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="payment-body">
    <header class="payment-header">
        <a href="index.php" class="logo">
            <img src="image-removebg-preview.png" alt="DAFF AIRBNB" class="logo-img">
            <span class="logo-word">DAFF <span>AIRBNB</span></span>
        </a>
        <span class="mock-badge"><i class="fas fa-flask"></i> Mock Xendit</span>
    </header>

    <main class="payment-shell mock-shell">
        <div class="payment-card mock-card">
            <div class="mock-header">
                <span class="mock-logo"><i class="fas fa-bolt"></i> Xendit <span>simulasi</span></span>
                <span class="order-status status-pending_payment">Menunggu Pembayaran</span>
            </div>

            <h1>Simulasi Checkout</h1>
            <p class="mock-subtitle">Halaman ini menggantikan checkout Xendit saat secret key belum dikonfigurasi.</p>

            <div class="mock-order">
                <div><span>Pesanan</span><strong>#<?php echo (int) $payment['order_id']; ?> &middot; <?php echo e($payment['property_name']); ?></strong></div>
                <div><span>Durasi</span><strong><?php echo (int) $payment['days']; ?> malam</strong></div>
                <div><span>Check-in / Check-out</span><strong><?php echo e(format_stay_range_id((string) $payment['start_date'], (int) $payment['days'])); ?></strong></div>
                <?php if ($mockGuestSummary !== ''): ?>
                    <div><span>Tamu</span><strong><?php echo e($mockGuestSummary); ?></strong></div>
                <?php endif; ?>
                <div class="mock-total"><span>Total</span><strong>Rp <?php echo number_format((int) $payment['order_total'], 0, ',', '.'); ?></strong></div>
            </div>

            <div class="mock-methods">
                <span><i class="fas fa-qrcode"></i> QRIS</span>
                <span><i class="fas fa-building-columns"></i> Virtual Account</span>
                <span><i class="fas fa-wallet"></i> E-Wallet</span>
            </div>

            <form method="post" action="api/mock_payment.php" class="mock-actions">
                <input type="hidden" name="payment_id" value="<?php echo (int) $payment['id']; ?>">
                <button type="submit" name="action" value="paid" class="btn-payment-primary">
                    <i class="fas fa-circle-check"></i> Simulasikan Pembayaran Berhasil
                </button>
                <button type="submit" name="action" value="expired" class="btn-payment-secondary">
                    <i class="fas fa-clock"></i> Simulasikan Kedaluwarsa
                </button>
            </form>

            <a href="pembayaran.php?order_id=<?php echo (int) $payment['order_id']; ?>" class="mock-back">
                <i class="fas fa-arrow-left"></i> Kembali ke halaman pembayaran
            </a>
        </div>
    </main>
</body>
</html>
