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
expire_pending_payments($isAdmin ? null : (int) $user['id']);
$order = get_order_with_payment($orderId);

if (!$order) {
    http_response_code(404);
    exit('Pesanan tidak ditemukan. <a href="index.php">Kembali</a>');
}

if (!$isAdmin && (int) $order['user_id'] !== (int) $user['id']) {
    http_response_code(403);
    exit('Akses ditolak. <a href="index.php">Kembali</a>');
}

// Sinkronkan invoice jika user kembali dari halaman Xendit.
$payment = latest_payment_for_order($orderId);
if ($payment && ($payment['status'] ?? '') === 'PENDING' && !xendit_is_mock()) {
    $sync = sync_xendit_invoice($payment);
    if ($sync['ok']) {
        $order = get_order_with_payment($orderId);
        $payment = latest_payment_for_order($orderId);
    }
}

$flash = $_SESSION['payment_flash'] ?? null;
unset($_SESSION['payment_flash']);

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success' && in_array($order['status'], ['paid', 'confirmed', 'completed'], true)) {
        $flash = 'Pembayaran berhasil. Terima kasih!';
    } elseif ($_GET['status'] === 'expired') {
        $flash = 'Invoice sudah kedaluwarsa.';
    } elseif ($_GET['status'] === 'failed') {
        $flash = 'Pembayaran belum selesai. Silakan coba lagi.';
    }
}

$statusLabels = order_status_labels();
$status = (string) $order['status'];
$statusLabel = $statusLabels[$status] ?? $status;
$canPay = can_pay_order($order);
$secondsLeft = 0;
if ($status === 'pending_payment' && !empty($order['payment_expires_at'])) {
    $secondsLeft = max(0, strtotime((string) $order['payment_expires_at']) - time());
}

$total = (int) $order['total'];
$priceBreakdown = order_price_breakdown($order);
$base = (int) $priceBreakdown['base_total'];
$discountTotal = (int) $priceBreakdown['discount_total'];
$fee = (int) $priceBreakdown['service_fee'];
$pricingDetail = is_array($priceBreakdown['pricing_detail']) ? $priceBreakdown['pricing_detail'] : null;
$guestSummary = is_array($pricingDetail['guests'] ?? null) ? (string) ($pricingDetail['guest_summary'] ?? '') : '';
$destinationLabel = (string) ($pricingDetail['destination']['label'] ?? '');
$checkinTime = (string) ($pricingDetail['destination']['checkin_time'] ?? '');
$checkoutTime = (string) ($pricingDetail['destination']['checkout_time'] ?? '');

$paymentChannel = payment_method_label(
    $order['payment_method'] ?? null,
    $order['payment_channel'] ?? null
);
$paidAt = $order['paid_at'] ? date('d M Y H:i', strtotime((string) $order['paid_at'])) : null;
$expiresAt = $order['payment_expires_at'] ? date('d M Y H:i', strtotime((string) $order['payment_expires_at'])) : null;
$isMock = xendit_is_mock();

$timeline = [
    ['key' => 'created', 'label' => 'Pesanan dibuat', 'icon' => 'fa-receipt'],
    ['key' => 'payment', 'label' => 'Pembayaran', 'icon' => 'fa-credit-card'],
    ['key' => 'confirm', 'label' => 'Dikonfirmasi owner', 'icon' => 'fa-user-check'],
    ['key' => 'done', 'label' => 'Selesai', 'icon' => 'fa-flag-checkered'],
];
$timelineState = 0;
if (in_array($status, ['paid', 'confirmed', 'completed'], true)) {
    $timelineState = 1;
}
if (in_array($status, ['confirmed', 'completed'], true)) {
    $timelineState = 2;
}
if ($status === 'completed') {
    $timelineState = 3;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran #<?php echo (int) $order['id']; ?> - DAFF AIRBNB</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo e((string) filemtime(__DIR__ . '/styles.css')); ?>">
    <link rel="stylesheet" href="payment.css?v=<?php echo e((string) filemtime(__DIR__ . '/payment.css')); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="payment-body">
    <header class="payment-header">
        <a href="index.php" class="logo" aria-label="DAFF AIRBNB">
            <img src="image-removebg-preview.png" alt="DAFF AIRBNB" class="logo-img">
            <span class="logo-word">DAFF <span>AIRBNB</span></span>
        </a>
        <div class="payment-header-actions">
            <a href="index.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Kembali ke Situs</a>
            <span class="user-chip"><i class="fas fa-user-circle"></i> <?php echo e($user['full_name']); ?></span>
        </div>
    </header>

    <main class="payment-shell">
        <div class="payment-title-row">
            <div>
                <span class="section-eyebrow">Pembayaran aman</span>
                <h1>Selesaikan Pembayaran</h1>
                <p>Pesanan #<?php echo (int) $order['id']; ?> &middot; <?php echo e($order['property_name']); ?></p>
            </div>
            <span class="order-status status-<?php echo e($status); ?>"><?php echo e($statusLabel); ?></span>
        </div>

        <?php if ($flash): ?>
            <div class="payment-flash"><i class="fas fa-circle-info"></i> <?php echo e($flash); ?></div>
        <?php endif; ?>

        <?php if ($isMock): ?>
            <div class="payment-mock-note">
                <i class="fas fa-flask"></i>
                <div>
                    <strong>Mode simulasi Xendit</strong>
                    <span>Secret key Xendit belum diisi. Pembayaran akan disimulasikan secara lokal agar alur tetap bisa diuji.</span>
                </div>
            </div>
        <?php endif; ?>

        <div class="payment-grid">
            <section class="payment-card payment-summary">
                <div class="payment-property">
                    <img src="<?php echo e($order['property_image']); ?>" alt="<?php echo e($order['property_name']); ?>">
                    <div>
                        <h2><?php echo e($order['property_name']); ?></h2>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo e($order['property_location']); ?></p>
                    </div>
                </div>

                <div class="payment-meta">
                    <div><span>Check-in / Check-out</span><strong><?php echo e(format_stay_range_id((string) $order['start_date'], (int) $order['days'])); ?></strong></div>
                    <div><span>Durasi</span><strong><?php echo (int) $order['days']; ?> malam</strong></div>
                    <?php if ($destinationLabel !== ''): ?>
                        <div><span>Destinasi</span><strong><?php echo e($destinationLabel); ?></strong></div>
                    <?php endif; ?>
                    <?php if ($guestSummary !== ''): ?>
                        <div><span>Tamu</span><strong><?php echo e($guestSummary); ?></strong></div>
                    <?php endif; ?>
                    <?php if ($checkinTime !== '' && $checkoutTime !== ''): ?>
                        <div><span>Waktu</span><strong>Check-in <?php echo e($checkinTime); ?> &middot; Check-out <?php echo e($checkoutTime); ?></strong></div>
                    <?php endif; ?>
                    <div><span>Pemesan</span><strong><?php echo e($order['user_name']); ?></strong></div>
                    <div><span>Email</span><strong><?php echo e($order['user_email']); ?></strong></div>
                </div>

                <div class="payment-breakdown">
                    <div class="payment-row"><span>Biaya sewa</span><strong>Rp <?php echo number_format($base, 0, ',', '.'); ?></strong></div>
                    <?php if ($discountTotal > 0): ?>
                        <div class="payment-row"><span>Diskon durasi</span><strong>-Rp <?php echo number_format($discountTotal, 0, ',', '.'); ?></strong></div>
                    <?php endif; ?>
                    <div class="payment-row"><span>Biaya layanan (5%)</span><strong>Rp <?php echo number_format($fee, 0, ',', '.'); ?></strong></div>
                    <div class="payment-row payment-total"><span>Total pembayaran</span><strong>Rp <?php echo number_format($total, 0, ',', '.'); ?></strong></div>
                </div>

                <div class="payment-security">
                    <i class="fas fa-shield-halved"></i>
                    <span>Pembayaran diproses oleh Xendit. DAFF AIRBNB tidak menyimpan data kartu atau PIN Anda.</span>
                </div>
            </section>

            <section class="payment-card payment-action">
                <?php if ($status === 'pending_payment'): ?>
                    <div class="payment-action-head">
                        <span class="payment-step">Langkah 2 dari 4</span>
                        <h2>Pilih metode pembayaran</h2>
                        <p>Gunakan QRIS, Virtual Account bank, atau e-wallet melalui halaman Xendit.</p>
                    </div>

                    <div class="countdown-box">
                        <span>Batas waktu pembayaran</span>
                        <strong id="countdown" data-seconds="<?php echo (int) $secondsLeft; ?>">--:--:--</strong>
                        <small>Bayar sebelum <?php echo e($expiresAt ?? '-'); ?></small>
                    </div>

                    <?php if (!$canPay): ?>
                        <div class="payment-alert payment-alert-danger">
                            <i class="fas fa-clock"></i> Batas waktu pembayaran sudah habis.
                        </div>
                        <a href="index.php#properties" class="btn-payment-primary"><i class="fas fa-search"></i> Pesan Lagi</a>
                    <?php else: ?>
                        <button type="button" class="btn-payment-primary" id="payNowBtn">
                            <i class="fas fa-credit-card"></i> Bayar Sekarang
                        </button>
                        <button type="button" class="btn-payment-secondary" id="checkStatusBtn">
                            <i class="fas fa-rotate"></i> Cek Status Pembayaran
                        </button>
                        <p class="payment-small-note">
                            Anda akan diarahkan ke halaman pembayaran Xendit. Setelah berhasil, status pesanan
                            otomatis diperbarui.
                        </p>
                    <?php endif; ?>

                <?php elseif ($status === 'paid'): ?>
                    <div class="payment-result payment-result-success">
                        <i class="fas fa-circle-check"></i>
                        <h2>Pembayaran diterima</h2>
                        <p>Pembayaran Anda sudah terverifikasi<?php echo $paymentChannel ? ' via ' . e($paymentChannel) : ''; ?>.</p>
                        <?php if ($paidAt): ?><small>Dibayar pada <?php echo e($paidAt); ?></small><?php endif; ?>
                    </div>
                    <div class="payment-alert">
                        <i class="fas fa-hourglass-half"></i> Menunggu owner mengonfirmasi pesanan Anda.
                    </div>
                    <button type="button" class="btn-payment-secondary" id="checkStatusBtn">
                        <i class="fas fa-rotate"></i> Cek Status
                    </button>
                    <a href="invoice.php?order_id=<?php echo $orderId; ?>" class="btn-payment-secondary">
                        <i class="fas fa-file-invoice"></i> Lihat Invoice
                    </a>

                <?php elseif ($status === 'confirmed'): ?>
                    <div class="payment-result payment-result-success">
                        <i class="fas fa-house-circle-check"></i>
                        <h2>Pesanan dikonfirmasi</h2>
                        <p>Owner sudah menerima pesanan Anda. Sampai jumpa di properti!</p>
                        <?php if ($paidAt): ?><small>Dibayar pada <?php echo e($paidAt); ?></small><?php endif; ?>
                    </div>
                    <a href="index.php" class="btn-payment-primary"><i class="fas fa-house"></i> Kembali ke Beranda</a>
                    <a href="invoice.php?order_id=<?php echo $orderId; ?>" class="btn-payment-secondary">
                        <i class="fas fa-file-invoice"></i> Lihat Invoice
                    </a>

                <?php elseif ($status === 'completed'): ?>
                    <div class="payment-result payment-result-success">
                        <i class="fas fa-flag-checkered"></i>
                        <h2>Pesanan selesai</h2>
                        <p>Terima kasih sudah menginap bersama DAFF AIRBNB.</p>
                    </div>
                    <a href="index.php#properties" class="btn-payment-primary"><i class="fas fa-search"></i> Cari Properti Lain</a>
                    <a href="invoice.php?order_id=<?php echo $orderId; ?>" class="btn-payment-secondary">
                        <i class="fas fa-file-invoice"></i> Lihat Invoice
                    </a>

                <?php elseif ($status === 'expired'): ?>
                    <div class="payment-result payment-result-muted">
                        <i class="fas fa-clock"></i>
                        <h2>Pembayaran kedaluwarsa</h2>
                        <p>Batas waktu pembayaran sudah habis. Silakan buat pesanan baru untuk mengunci tanggal sewa.</p>
                    </div>
                    <a href="index.php#properties" class="btn-payment-primary"><i class="fas fa-rotate-right"></i> Pesan Lagi</a>

                <?php elseif ($status === 'rejected'): ?>
                    <div class="payment-result payment-result-danger">
                        <i class="fas fa-circle-xmark"></i>
                        <h2>Pesanan ditolak</h2>
                        <p>Owner menolak pesanan ini.<?php echo !empty($order['admin_note']) ? ' Catatan: ' . e($order['admin_note']) : ''; ?></p>
                    </div>
                    <a href="index.php#properties" class="btn-payment-primary"><i class="fas fa-search"></i> Cari Properti Lain</a>

                <?php else: ?>
                    <div class="payment-result payment-result-muted">
                        <i class="fas fa-circle-info"></i>
                        <h2><?php echo e($statusLabel); ?></h2>
                        <p>Pesanan ini tidak lagi aktif.</p>
                    </div>
                    <a href="index.php#properties" class="btn-payment-primary"><i class="fas fa-search"></i> Cari Properti Lain</a>
                <?php endif; ?>
            </section>
        </div>

        <section class="payment-card payment-timeline-card">
            <h2>Status Pesanan</h2>
            <div class="payment-timeline">
                <?php foreach ($timeline as $index => $step): ?>
                    <div class="timeline-step <?php echo $index <= $timelineState ? 'is-active' : ''; ?> <?php echo $index === $timelineState ? 'is-current' : ''; ?>">
                        <span class="timeline-icon"><i class="fas <?php echo e($step['icon']); ?>"></i></span>
                        <span class="timeline-label"><?php echo e($step['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <script>
        const paymentOrderId = <?php echo (int) $order['id']; ?>;
        const paymentStatus = <?php echo json_encode($status); ?>;
        let secondsLeft = <?php echo (int) $secondsLeft; ?>;
        const countdownEl = document.getElementById('countdown');
        const payNowBtn = document.getElementById('payNowBtn');
        const checkStatusBtn = document.getElementById('checkStatusBtn');

        function renderCountdown() {
            if (!countdownEl) return;
            if (secondsLeft <= 0) {
                countdownEl.textContent = '00:00:00';
                countdownEl.classList.add('is-expired');
                return;
            }
            const hours = Math.floor(secondsLeft / 3600);
            const minutes = Math.floor((secondsLeft % 3600) / 60);
            const seconds = secondsLeft % 60;
            countdownEl.textContent = [hours, minutes, seconds].map(v => String(v).padStart(2, '0')).join(':');
            secondsLeft -= 1;
        }
        if (countdownEl) {
            renderCountdown();
            setInterval(renderCountdown, 1000);
        }

        async function checkPaymentStatus(showAlert = true) {
            const res = await fetch('api/check_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: paymentOrderId })
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (showAlert) alert(data.error || 'Gagal mengecek status pembayaran.');
                return;
            }
            if (data.status !== paymentStatus) {
                window.location.reload();
                return;
            }
            if (showAlert) alert(data.message || 'Status pembayaran belum berubah.');
        }

        if (payNowBtn) {
            payNowBtn.addEventListener('click', async () => {
                payNowBtn.disabled = true;
                payNowBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyiapkan pembayaran...';
                try {
                    const res = await fetch('api/create_invoice.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ order_id: paymentOrderId })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.invoice_url) {
                        alert(data.error || 'Gagal membuat invoice pembayaran.');
                        payNowBtn.disabled = false;
                        payNowBtn.innerHTML = '<i class="fas fa-credit-card"></i> Bayar Sekarang';
                        return;
                    }
                    window.location.href = data.invoice_url;
                } catch (err) {
                    alert('Tidak dapat terhubung ke server.');
                    payNowBtn.disabled = false;
                    payNowBtn.innerHTML = '<i class="fas fa-credit-card"></i> Bayar Sekarang';
                }
            });
        }

        if (checkStatusBtn) {
            checkStatusBtn.addEventListener('click', () => checkPaymentStatus(true));
        }

        if (paymentStatus === 'pending_payment') {
            setInterval(() => checkPaymentStatus(false), 20000);
        }
    </script>
</body>
</html>
