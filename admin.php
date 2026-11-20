<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';

require_session();
$user = require_login();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Akses khusus owner. <a href="index.php">Kembali</a>');
}

expire_pending_payments();
$statusLabels = order_status_labels();
$paymentLabels = payment_status_labels();

$orders = db()->query(
    "SELECT o.id, o.start_date, o.days, o.total, o.status, o.created_at,
            o.payment_expires_at, o.paid_at, o.admin_note, o.pricing_detail,
            p.name AS property_name, p.location AS property_location, p.image_main,
            u.full_name AS user_name, u.email AS user_email, u.phone AS user_phone,
            pay.status AS payment_status, pay.payment_method, pay.payment_channel,
            pay.invoice_id, pay.invoice_url
     FROM orders o
     JOIN properties p ON p.id = o.property_id
     JOIN users u      ON u.id = o.user_id
     LEFT JOIN payments pay ON pay.id = (
         SELECT p2.id FROM payments p2 WHERE p2.order_id = o.id ORDER BY p2.id DESC LIMIT 1
     )
     ORDER BY o.status = 'paid' DESC, o.created_at DESC"
)->fetchAll();

foreach ($orders as &$orderRow) {
    $orderRow['checkout_date'] = booking_end_exclusive((string) $orderRow['start_date'], (int) $orderRow['days']);
    $detail = !empty($orderRow['pricing_detail']) ? json_decode((string) $orderRow['pricing_detail'], true) : null;
    $orderRow['guest_summary'] = is_array($detail['guests'] ?? null)
        ? (string) ($detail['guest_summary'] ?? '')
        : '';
    $orderRow['destination_label'] = (string) ($detail['destination']['label'] ?? '');
    unset($orderRow['pricing_detail']);
}
unset($orderRow);

$pendingCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();

$users = db()->query(
    "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.created_at,
            COUNT(o.id) AS total_pesanan
     FROM users u
     LEFT JOIN orders o ON o.user_id = u.id
     GROUP BY u.id ORDER BY u.created_at DESC"
)->fetchAll();

$properties = db()->query(
    "SELECT id, name, location, category, price, weekend_price,
            long_stay_min_days, long_stay_discount_percent, rating, reviews,
            guests, stock, bedrooms, bathrooms, badge, is_active,
            image_main, image_2, image_3, description, features
     FROM properties ORDER BY name ASC"
)->fetchAll();

$blockedDates = db()->query(
    "SELECT b.id, b.property_id, b.start_date, b.end_date, b.reason,
            p.name AS property_name
     FROM property_blocked_dates b
     JOIN properties p ON p.id = b.property_id
     WHERE b.end_date >= CURDATE()
     ORDER BY b.start_date ASC, b.id ASC"
)->fetchAll();

$stats = [
    'total_orders'    => db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'pending_payment' => db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending_payment'")->fetchColumn(),
    'paid'            => $pendingCount,
    'confirmed'       => db()->query("SELECT COUNT(*) FROM orders WHERE status = 'confirmed'")->fetchColumn(),
    'revenue'         => (int) db()->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('paid','confirmed','completed')")->fetchColumn(),
    'properties'      => db()->query('SELECT COUNT(*) FROM properties')->fetchColumn(),
    'users'           => db()->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Owner - DAFF AIRBNB</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-body">
    <header class="admin-header">
        <a href="index.php" class="logo"><i class="fas fa-home"></i> DAFF AIRBNB</a>
        <div class="admin-header-right">
            <span class="user-chip"><i class="fas fa-crown"></i> <?php echo e($user['full_name']); ?> (Owner)</span>
            <a href="index.php" class="btn-outline"><i class="fas fa-arrow-left"></i> Kembali ke Situs</a>
            <button class="btn-primary" id="adminLogoutBtn">Keluar</button>
        </div>
    </header>

    <main class="admin-container">
        <h1 class="admin-title">Dashboard Owner</h1>
        <p class="admin-subtitle">Kelola pesanan, konfirmasi pembayaran, properti, harga, tanggal blokir, dan pengguna DAFF AIRBNB</p>

        <?php if ($pendingCount > 0): ?>
            <div class="notif-banner">
                <i class="fas fa-bell"></i>
                <strong><?php echo $pendingCount; ?> pesanan sudah dibayar</strong> dan menunggu konfirmasi Anda.
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stat-grid">
            <div class="stat-card">
                <i class="fas fa-file-invoice"></i>
                <div><span>Total Pesanan</span><strong><?php echo $stats['total_orders']; ?></strong></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock"></i>
                <div><span>Menunggu Bayar</span><strong><?php echo $stats['pending_payment']; ?></strong></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-money-check-dollar"></i>
                <div><span>Sudah Dibayar</span><strong><?php echo $stats['paid']; ?></strong></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <div><span>Dikonfirmasi</span><strong><?php echo $stats['confirmed']; ?></strong></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-dollar-sign"></i>
                <div><span>Pembayaran Masuk</span><strong><?php echo number_format($stats['revenue'], 0, ',', '.'); ?></strong></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-home"></i>
                <div><span>Properti</span><strong><?php echo $stats['properties']; ?></strong></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <div><span>Pengguna</span><strong><?php echo $stats['users']; ?></strong></div>
            </div>
        </div>

        <!-- Orders -->
        <section class="admin-section" id="orders">
            <h2><i class="fas fa-file-invoice"></i> Daftar Pesanan</h2>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Properti</th>
                            <th>Pemesan</th>
                            <th>Check-in / Check-out</th>
                            <th>Malam</th>
                            <th>Total</th>
                            <th>Pembayaran</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$orders): ?>
                            <tr><td colspan="9" class="empty-row">Belum ada pesanan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td>#<?php echo $o['id']; ?></td>
                                <td>
                                    <div class="cell-house">
                                        <img src="<?php echo e($o['image_main']); ?>" alt="">
                                        <div>
                                            <strong><?php echo e($o['property_name']); ?></strong>
                                            <small><?php echo e($o['property_location']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo e($o['user_name']); ?></strong>
                                    <small><?php echo e($o['user_email']); ?></small>
                                    <small><i class="fas fa-phone"></i> <?php echo $o['user_phone'] ? e($o['user_phone']) : '-'; ?></small>
                                </td>
                                <td>
                                    <?php echo e(format_date_short_id((string) $o['start_date'])); ?>
                                    <small>s/d <?php echo e(format_date_short_id((string) $o['checkout_date'])); ?></small>
                                    <?php if ($o['guest_summary'] !== ''): ?>
                                        <small><i class="fas fa-users"></i> <?php echo e($o['guest_summary']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $o['days']; ?> malam</td>
                                <td class="amount"><?php echo number_format((int) $o['total'], 0, ',', '.'); ?></td>
                                <td>
                                    <?php if (($o['payment_status'] ?? '') === 'PAID' || in_array($o['status'], ['paid', 'confirmed', 'completed'], true)): ?>
                                        <span class="payment-pill payment-paid"><i class="fas fa-circle-check"></i> Lunas</span>
                                        <small><?php echo e(payment_method_label($o['payment_method'] ?? null, $o['payment_channel'] ?? null)); ?></small>
                                        <?php if ($o['paid_at']): ?><small><?php echo e(date('d M Y H:i', strtotime((string) $o['paid_at']))); ?></small><?php endif; ?>
                                    <?php elseif ($o['status'] === 'pending_payment'): ?>
                                        <span class="payment-pill payment-pending"><i class="fas fa-clock"></i> Belum bayar</span>
                                        <?php if ($o['payment_expires_at']): ?><small>Batas <?php echo e(date('d M Y H:i', strtotime((string) $o['payment_expires_at']))); ?></small><?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted-cell">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="order-status status-<?php echo e($o['status']); ?>"><?php echo e($statusLabels[$o['status']] ?? $o['status']); ?></span>
                                    <?php if (!empty($o['admin_note'])): ?><small><?php echo e($o['admin_note']); ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <?php if ($o['status'] === 'paid'): ?>
                                            <button class="btn-action btn-accept" data-order="<?php echo (int) $o['id']; ?>">
                                                <i class="fas fa-check"></i> Terima
                                            </button>
                                            <button class="btn-action btn-reject" data-order="<?php echo (int) $o['id']; ?>" data-refund="1">
                                                <i class="fas fa-times"></i> Tolak
                                            </button>
                                        <?php elseif ($o['status'] === 'confirmed'): ?>
                                            <button class="btn-action btn-complete" data-order="<?php echo (int) $o['id']; ?>">
                                                <i class="fas fa-flag-checkered"></i> Selesaikan
                                            </button>
                                        <?php elseif ($o['status'] === 'pending_payment'): ?>
                                            <button class="btn-action btn-cancel" data-order="<?php echo (int) $o['id']; ?>">
                                                <i class="fas fa-ban"></i> Batalkan
                                            </button>
                                        <?php else: ?>
                                            <span class="muted-cell">Selesai</span>
                                        <?php endif; ?>
                                        <?php if (in_array($o['status'], ['paid', 'confirmed', 'completed'], true)): ?>
                                            <a href="invoice.php?order_id=<?php echo (int) $o['id']; ?>" class="btn-action btn-invoice" target="_blank" rel="noopener">
                                                <i class="fas fa-file-invoice"></i> Invoice
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Property management -->
        <section class="admin-section">
            <div class="admin-section-head">
                <div>
                    <h2><i class="fas fa-house-chimney"></i> Kelola Properti</h2>
                    <p class="admin-subtitle" style="margin-bottom: 0;">
                        Tambah, edit, atur harga, dan nonaktifkan properti. Properti nonaktif tidak tampil di katalog.
                    </p>
                </div>
                <button type="button" class="btn-primary" id="addPropertyBtn">
                    <i class="fas fa-plus"></i> Tambah Properti
                </button>
            </div>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Properti</th>
                            <th>Harga</th>
                            <th>Diskon Durasi</th>
                            <th>Stok Unit</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$properties): ?>
                            <tr><td colspan="7" class="empty-row">Belum ada properti.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($properties as $p): ?>
                            <tr>
                                <td>#<?php echo (int) $p['id']; ?></td>
                                <td>
                                    <div class="cell-house">
                                        <img src="<?php echo e($p['image_main']); ?>" alt="">
                                        <div>
                                            <strong><?php echo e($p['name']); ?></strong>
                                            <small><?php echo e($p['location']); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong>Rp <?php echo number_format((int) $p['price'], 0, ',', '.'); ?></strong>
                                    <small>/ hari</small>
                                    <?php if ($p['weekend_price'] !== null && (int) $p['weekend_price'] > 0): ?>
                                        <small>Weekend: Rp <?php echo number_format((int) $p['weekend_price'], 0, ',', '.'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $p['long_stay_min_days'] > 0 && (float) $p['long_stay_discount_percent'] > 0): ?>
                                        <?php echo e((string) $p['long_stay_discount_percent']); ?>%
                                        <small>min. <?php echo (int) $p['long_stay_min_days']; ?> hari</small>
                                    <?php else: ?>
                                        <span class="muted-cell">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        class="stock-input"
                                        min="1"
                                        max="99"
                                        value="<?php echo max(1, (int) $p['stock']); ?>"
                                        data-property="<?php echo (int) $p['id']; ?>"
                                        aria-label="Stok <?php echo e($p['name']); ?>"
                                    >
                                    <button
                                        type="button"
                                        class="btn-action btn-stock"
                                        data-property="<?php echo (int) $p['id']; ?>"
                                        title="Simpan stok"
                                        aria-label="Simpan stok <?php echo e($p['name']); ?>"
                                    >
                                        <i class="fas fa-floppy-disk"></i>
                                    </button>
                                </td>
                                <td>
                                    <span class="order-status <?php echo (int) $p['is_active'] ? 'status-confirmed' : 'status-cancelled'; ?>">
                                        <?php echo (int) $p['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button type="button" class="btn-action btn-edit-property" data-property="<?php echo (int) $p['id']; ?>">
                                            <i class="fas fa-pen"></i> Edit
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-action <?php echo (int) $p['is_active'] ? 'btn-deactivate' : 'btn-activate'; ?> btn-toggle-property"
                                            data-property="<?php echo (int) $p['id']; ?>"
                                            data-active="<?php echo (int) $p['is_active']; ?>"
                                        >
                                            <i class="fas <?php echo (int) $p['is_active'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                            <?php echo (int) $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Blocked dates -->
        <section class="admin-section">
            <h2><i class="fas fa-calendar-xmark"></i> Blokir Tanggal</h2>
            <p class="admin-subtitle" style="margin-bottom: 14px;">
                Tutup tanggal tertentu agar tidak bisa dipesan, misalnya untuk maintenance atau pemakaian pribadi.
            </p>

            <form id="blockDateForm" class="admin-block-form">
                <div class="form-group">
                    <label for="blockProperty">Properti</label>
                    <select id="blockProperty" required>
                        <option value="">Pilih properti</option>
                        <?php foreach ($properties as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>">
                                <?php echo e($p['name']); ?><?php echo (int) $p['is_active'] ? '' : ' (nonaktif)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="blockStart">Mulai</label>
                    <input type="date" id="blockStart" min="<?php echo e(app_today()); ?>" required>
                </div>
                <div class="form-group">
                    <label for="blockEnd">Selesai</label>
                    <input type="date" id="blockEnd" min="<?php echo e(app_today()); ?>" required>
                </div>
                <div class="form-group admin-block-reason">
                    <label for="blockReason">Alasan</label>
                    <input type="text" id="blockReason" maxlength="255" placeholder="Contoh: maintenance">
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-ban"></i> Blokir
                </button>
            </form>

            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Properti</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Alasan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="blockedDatesBody">
                        <?php if (!$blockedDates): ?>
                            <tr><td colspan="5" class="empty-row">Belum ada tanggal yang diblokir.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($blockedDates as $b): ?>
                            <tr>
                                <td><strong><?php echo e($b['property_name']); ?></strong></td>
                                <td><?php echo e(format_date_short_id((string) $b['start_date'])); ?></td>
                                <td><?php echo e(format_date_short_id((string) $b['end_date'])); ?></td>
                                <td><?php echo $b['reason'] !== null && $b['reason'] !== '' ? e((string) $b['reason']) : '-'; ?></td>
                                <td>
                                    <button type="button" class="btn-action btn-delete-block" data-block="<?php echo (int) $b['id']; ?>">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Users -->
        <section class="admin-section">
            <h2><i class="fas fa-users"></i> Daftar Pengguna</h2>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>No. HP</th>
                            <th>Role</th>
                            <th>Pesanan</th>
                            <th>Bergabung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>#<?php echo $u['id']; ?></td>
                                <td><strong><?php echo e($u['full_name']); ?></strong></td>
                                <td><?php echo e($u['email']); ?></td>
                                <td><?php echo $u['phone'] ? e($u['phone']) : '-'; ?></td>
                                <td>
                                    <span class="order-status <?php echo $u['role'] === 'admin' ? 'status-confirmed' : 'status-pending'; ?>">
                                        <?php echo $u['role'] === 'admin' ? 'Owner' : 'Pengguna'; ?>
                                    </span>
                                </td>
                                <td><?php echo $u['total_pesanan']; ?></td>
                                <td><?php echo date('d M Y', strtotime($u['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="modal-overlay" id="propertyFormModal">
        <div class="modal modal-large admin-modal">
            <button type="button" class="modal-close" id="closePropertyModal" aria-label="Tutup">&times;</button>
            <div class="modal-header">
                <h2 id="propertyModalTitle">Tambah Properti</h2>
                <p>Lengkapi data properti, harga, dan foto.</p>
            </div>
            <form id="propertyForm" class="modal-form admin-property-form" enctype="multipart/form-data">
                <input type="hidden" name="property_id" id="propertyId" value="">

                <div class="admin-form-grid">
                    <div class="form-group">
                        <label for="propertyName">Nama Properti</label>
                        <input type="text" name="name" id="propertyName" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label for="propertyLocation">Lokasi</label>
                        <input type="text" name="location" id="propertyLocation" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label for="propertyCategory">Kategori</label>
                        <input type="text" name="category" id="propertyCategory" maxlength="50" value="rumah">
                    </div>
                    <div class="form-group">
                        <label for="propertyBadge">Badge (opsional)</label>
                        <input type="text" name="badge" id="propertyBadge" maxlength="60" placeholder="Contoh: Populer">
                    </div>
                    <div class="form-group">
                        <label for="propertyPrice">Harga per Hari (Rp)</label>
                        <input type="number" name="price" id="propertyPrice" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="propertyWeekendPrice">Harga Weekend (Rp)</label>
                        <input type="number" name="weekend_price" id="propertyWeekendPrice" min="1" placeholder="Kosongkan jika sama">
                        <small class="form-hint">Berlaku Sabtu dan Minggu.</small>
                    </div>
                    <div class="form-group">
                        <label for="propertyMinDays">Minimal Hari Diskon</label>
                        <input type="number" name="long_stay_min_days" id="propertyMinDays" min="1" max="30" placeholder="Contoh: 7">
                    </div>
                    <div class="form-group">
                        <label for="propertyDiscount">Diskon Durasi (%)</label>
                        <input type="number" name="long_stay_discount_percent" id="propertyDiscount" min="0" max="100" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label for="propertyGuests">Kapasitas Tamu</label>
                        <input type="number" name="guests" id="propertyGuests" min="1" max="100" value="1">
                    </div>
                    <div class="form-group">
                        <label for="propertyStock">Stok Unit</label>
                        <input type="number" name="stock" id="propertyStock" min="1" max="99" value="1">
                    </div>
                    <div class="form-group">
                        <label for="propertyBedrooms">Kamar Tidur</label>
                        <input type="number" name="bedrooms" id="propertyBedrooms" min="1" max="50" value="1">
                    </div>
                    <div class="form-group">
                        <label for="propertyBathrooms">Kamar Mandi</label>
                        <input type="number" name="bathrooms" id="propertyBathrooms" min="1" max="50" value="1">
                    </div>
                </div>

                <div class="form-group">
                    <label for="propertyDescription">Deskripsi</label>
                    <textarea name="description" id="propertyDescription" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="propertyFeatures">Fasilitas</label>
                    <textarea name="features" id="propertyFeatures" rows="3" placeholder="Satu fasilitas per baris, contoh: Wi-Fi&#10;AC&#10;Kolam Renang"></textarea>
                </div>

                <div class="admin-image-fields">
                    <div class="form-group">
                        <label for="propertyImageMain">Foto Utama</label>
                        <input type="file" name="image_main" id="propertyImageMain" accept="image/jpeg,image/png,image/webp">
                        <small class="form-hint">Wajib untuk properti baru. Maks. 2 MB.</small>
                    </div>
                    <div class="form-group">
                        <label for="propertyImage2">Foto 2</label>
                        <input type="file" name="image_2" id="propertyImage2" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="form-group">
                        <label for="propertyImage3">Foto 3</label>
                        <input type="file" name="image_3" id="propertyImage3" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>

                <div class="property-image-preview" id="propertyImagePreview"></div>

                <button type="submit" class="btn-primary btn-block" id="savePropertyBtn">
                    <i class="fas fa-floppy-disk"></i> Simpan Properti
                </button>
            </form>
        </div>
    </div>

    <script>
        if (window.USER_DATA === undefined) window.USER_DATA = <?php echo json_encode($user); ?>;
        const adminProperties = <?php echo json_encode($properties, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        async function updateStatus(orderId, status, note = '') {
            const res = await fetch('api/update_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId, status, note })
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                window.location.reload();
            } else {
                alert(data.error || 'Gagal memperbarui status');
            }
        }

        document.querySelectorAll('.btn-accept').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Terima pesanan ini? Pastikan pembayaran sudah benar.')) {
                    updateStatus(parseInt(btn.dataset.order, 10), 'confirmed');
                }
            });
        });

        document.querySelectorAll('.btn-reject').forEach(btn => {
            btn.addEventListener('click', () => {
                const refund = btn.dataset.refund === '1';
                const note = prompt(refund
                    ? 'Pesanan sudah dibayar. Tulis alasan penolakan/refund manual:'
                    : 'Tulis alasan penolakan:');
                if (note === null) return;
                if (confirm(refund
                    ? 'Tolak pesanan ini? Refund harus diproses manual.'
                    : 'Yakin menolak pesanan ini?')) {
                    updateStatus(parseInt(btn.dataset.order, 10), 'rejected', note.trim());
                }
            });
        });

        document.querySelectorAll('.btn-complete').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Tandai pesanan ini sebagai selesai?')) {
                    updateStatus(parseInt(btn.dataset.order, 10), 'completed');
                }
            });
        });

        document.querySelectorAll('.btn-cancel').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Batalkan pesanan yang belum dibayar ini?')) {
                    updateStatus(parseInt(btn.dataset.order, 10), 'cancelled');
                }
            });
        });

        document.querySelectorAll('.btn-stock').forEach(btn => {
            btn.addEventListener('click', async () => {
                const propertyId = parseInt(btn.dataset.property, 10);
                const input = document.querySelector(`.stock-input[data-property="${propertyId}"]`);
                const stock = parseInt(input ? input.value : '', 10);

                if (!Number.isFinite(stock) || stock < 1 || stock > 99) {
                    alert('Stok harus berupa angka 1 sampai 99.');
                    return;
                }

                btn.disabled = true;
                const original = btn.innerHTML;

                try {
                    const res = await fetch('api/update_stock.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ property_id: propertyId, stock })
                    });
                    const data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        alert(data.error || 'Gagal menyimpan stok.');
                        return;
                    }

                    btn.innerHTML = '<i class="fas fa-check"></i> Tersimpan';
                    setTimeout(() => {
                        btn.innerHTML = original;
                    }, 1500);
                } catch (err) {
                    alert('Tidak dapat terhubung ke server.');
                } finally {
                    btn.disabled = false;
                }
            });
        });

        // ==================== MANAJEMEN PROPERTI ====================
        const propertyModal = document.getElementById('propertyFormModal');
        const propertyForm = document.getElementById('propertyForm');
        const propertyModalTitle = document.getElementById('propertyModalTitle');
        const propertyImagePreview = document.getElementById('propertyImagePreview');
        const savePropertyBtn = document.getElementById('savePropertyBtn');

        const propertyFieldIds = {
            name: 'propertyName',
            location: 'propertyLocation',
            category: 'propertyCategory',
            badge: 'propertyBadge',
            price: 'propertyPrice',
            weekend_price: 'propertyWeekendPrice',
            long_stay_min_days: 'propertyMinDays',
            long_stay_discount_percent: 'propertyDiscount',
            guests: 'propertyGuests',
            stock: 'propertyStock',
            bedrooms: 'propertyBedrooms',
            bathrooms: 'propertyBathrooms',
            description: 'propertyDescription',
            features: 'propertyFeatures'
        };

        function propertyFeaturesList(raw) {
            if (!raw) return [];
            try {
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (err) {
                return String(raw).split(/\r?\n|,/).map(v => v.trim()).filter(Boolean);
            }
        }

        function renderPropertyImages(property) {
            if (!propertyImagePreview) return;
            const images = property
                ? [property.image_main, property.image_2, property.image_3].filter(Boolean)
                : [];
            propertyImagePreview.innerHTML = images.length
                ? images.map((src, index) => `
                    <figure>
                        <img src="${src}" alt="Foto ${index + 1}">
                        <figcaption>${index === 0 ? 'Foto utama' : 'Foto ' + (index + 1)}</figcaption>
                    </figure>
                `).join('')
                : '<p class="muted-cell">Belum ada foto tersimpan.</p>';
        }

        function openPropertyModal(property = null) {
            propertyForm.reset();
            document.getElementById('propertyId').value = property ? property.id : '';
            propertyModalTitle.textContent = property ? 'Edit Properti' : 'Tambah Properti';
            renderPropertyImages(property);

            Object.entries(propertyFieldIds).forEach(([key, fieldId]) => {
                const field = document.getElementById(fieldId);
                if (!field) return;
                let value = property ? property[key] : '';
                if (key === 'features') {
                    value = propertyFeaturesList(property ? property.features : '').join('\n');
                }
                if (value === null || value === undefined) value = '';
                field.value = value;
            });

            if (!property) {
                document.getElementById('propertyCategory').value = 'rumah';
                document.getElementById('propertyDiscount').value = '0';
                document.getElementById('propertyGuests').value = '1';
                document.getElementById('propertyStock').value = '1';
                document.getElementById('propertyBedrooms').value = '1';
                document.getElementById('propertyBathrooms').value = '1';
            }

            propertyModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePropertyModal() {
            propertyModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        const addPropertyBtn = document.getElementById('addPropertyBtn');
        if (addPropertyBtn) addPropertyBtn.addEventListener('click', () => openPropertyModal());

        const closePropertyBtn = document.getElementById('closePropertyModal');
        if (closePropertyBtn) closePropertyBtn.addEventListener('click', closePropertyModal);

        if (propertyModal) {
            propertyModal.addEventListener('click', (event) => {
                if (event.target === propertyModal) closePropertyModal();
            });
        }

        document.querySelectorAll('.btn-edit-property').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.property, 10);
                const property = adminProperties.find(item => parseInt(item.id, 10) === id);
                if (property) openPropertyModal(property);
            });
        });

        if (propertyForm) {
            propertyForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                const isCreate = !document.getElementById('propertyId').value;
                const mainFile = document.getElementById('propertyImageMain').files[0];
                if (isCreate && !mainFile) {
                    alert('Foto utama wajib diunggah untuk properti baru.');
                    return;
                }

                const original = savePropertyBtn.innerHTML;
                savePropertyBtn.disabled = true;
                savePropertyBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Menyimpan...';

                try {
                    const res = await fetch('api/save_property.php', {
                        method: 'POST',
                        body: new FormData(propertyForm)
                    });
                    const data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        alert(data.error || 'Gagal menyimpan properti.');
                        return;
                    }

                    window.location.reload();
                } catch (err) {
                    alert('Tidak dapat terhubung ke server.');
                } finally {
                    savePropertyBtn.disabled = false;
                    savePropertyBtn.innerHTML = original;
                }
            });
        }

        document.querySelectorAll('.btn-toggle-property').forEach(btn => {
            btn.addEventListener('click', async () => {
                const propertyId = parseInt(btn.dataset.property, 10);
                const isActive = btn.dataset.active === '1';
                const message = isActive
                    ? 'Nonaktifkan properti ini? Properti tidak akan tampil di katalog.'
                    : 'Aktifkan kembali properti ini?';
                if (!confirm(message)) return;

                btn.disabled = true;
                try {
                    const res = await fetch('api/toggle_property.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ property_id: propertyId, active: !isActive })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        alert(data.error || 'Gagal memperbarui status properti.');
                        return;
                    }
                    window.location.reload();
                } catch (err) {
                    alert('Tidak dapat terhubung ke server.');
                } finally {
                    btn.disabled = false;
                }
            });
        });

        // ==================== BLOKIR TANGGAL ====================
        const blockDateForm = document.getElementById('blockDateForm');
        const blockStart = document.getElementById('blockStart');
        const blockEnd = document.getElementById('blockEnd');

        function syncBlockEndMin() {
            if (!blockStart || !blockEnd) return;
            blockEnd.min = blockStart.value || blockEnd.dataset.today || '';
            if (blockStart.value && blockEnd.value && blockEnd.value < blockStart.value) {
                blockEnd.value = blockStart.value;
            }
        }

        if (blockStart && blockEnd) {
            blockEnd.dataset.today = blockEnd.min;
            blockStart.addEventListener('change', syncBlockEndMin);
        }

        if (blockDateForm) {
            blockDateForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const submitBtn = blockDateForm.querySelector('button[type="submit"]');
                const original = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Menyimpan...';

                try {
                    const res = await fetch('api/blocked_dates.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            property_id: parseInt(document.getElementById('blockProperty').value, 10),
                            start_date: blockStart.value,
                            end_date: blockEnd.value,
                            reason: document.getElementById('blockReason').value.trim()
                        })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        alert(data.error || 'Gagal memblokir tanggal.');
                        return;
                    }
                    window.location.reload();
                } catch (err) {
                    alert('Tidak dapat terhubung ke server.');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = original;
                }
            });
        }

        document.querySelectorAll('.btn-delete-block').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Hapus blokir tanggal ini?')) return;
                btn.disabled = true;
                try {
                    const res = await fetch('api/blocked_dates.php', {
                        method: 'DELETE',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: parseInt(btn.dataset.block, 10) })
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        alert(data.error || 'Gagal menghapus blokir.');
                        return;
                    }
                    window.location.reload();
                } catch (err) {
                    alert('Tidak dapat terhubung ke server.');
                } finally {
                    btn.disabled = false;
                }
            });
        });

        document.getElementById('adminLogoutBtn').addEventListener('click', async () => {
            await fetch('api/logout.php', { method: 'POST' });
            window.location.href = 'index.php';
        });
    </script>
</body>
</html>