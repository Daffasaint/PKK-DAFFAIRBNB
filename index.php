<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_session();
$user = current_user();
$isLoggedIn = $user !== null;
$isAdmin    = $isLoggedIn && ($user['role'] === 'admin');

$pendingOrders = 0;
$unpaidOrders = 0;
if ($isAdmin) {
    // Pesanan yang sudah dibayar dan menunggu konfirmasi owner.
    $pendingOrders = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
} elseif ($isLoggedIn) {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE user_id = ? AND status = 'pending_payment'
           AND (payment_expires_at IS NULL OR payment_expires_at > NOW())"
    );
    $stmt->execute([$user['id']]);
    $unpaidOrders = (int) $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAFF AIRBNB - Sewa Rumah &amp; Villa Terbaik</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo e((string) filemtime(__DIR__ . '/styles.css')); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <script>
        window.USER_DATA = <?php echo json_encode($user); ?>;
        window.APP_TIMEZONE = <?php echo json_encode(APP_TIMEZONE); ?>;
        window.SERVER_NOW = <?php echo json_encode(date('c')); ?>;
    </script>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="logo" aria-label="DAFF AIRBNB">
                <img src="image-removebg-preview.png" alt="DAFF AIRBNB Logo" class="logo-img">
                <span class="logo-word">DAFF <span>AIRBNB</span></span>
            </a>
            <div class="search-bar">
                <input type="text" placeholder="Cari rumah, villa, atau lokasi..." id="searchInput">
                <button type="button" class="search-btn" aria-label="Cari rumah"><i class="fas fa-search"></i></button>
            </div>
            <div class="nav-links" id="navLinks">
                <a href="#properties" class="nav-link" id="browseBtn"><i class="fas fa-search-location"></i> Cari Rumah</a>
                <?php if ($isLoggedIn): ?>
                    <?php if ($isAdmin): ?>
                        <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Owner
                            <?php if ($pendingOrders > 0): ?><span class="nav-badge"><?php echo $pendingOrders; ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$isAdmin): ?>
                        <a href="#" class="nav-link" id="myOrdersBtn"><i class="fas fa-receipt"></i> Pesanan Saya
                            <?php if ($unpaidOrders > 0): ?><span class="nav-badge"><?php echo $unpaidOrders; ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <span class="user-chip"><i class="fas fa-user-circle"></i> <?php echo e($user['full_name']); ?></span>
                    <button type="button" class="btn-primary" id="logoutBtn">Keluar</button>
                <?php else: ?>
                    <a href="#" class="nav-link nav-login" id="loginBtn"><i class="fas fa-user-circle"></i> Masuk</a>
                    <button type="button" class="btn-primary nav-cta" id="registerBtn">Daftar</button>
                <?php endif; ?>
            </div>
            <button type="button" class="hamburger" id="hamburger" aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>

    <!-- Mobile Menu -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="#properties" class="nav-link">Cari Rumah</a>
        <?php if ($isLoggedIn): ?>
            <?php if ($isAdmin): ?>
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Owner
                    <?php if ($pendingOrders > 0): ?><span class="nav-badge"><?php echo $pendingOrders; ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <?php if (!$isAdmin): ?>
                <a href="#" class="nav-link" id="mobileOrdersBtn"><i class="fas fa-receipt"></i> Pesanan Saya
                    <?php if ($unpaidOrders > 0): ?><span class="nav-badge"><?php echo $unpaidOrders; ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <span class="nav-link"><i class="fas fa-user-circle"></i> <?php echo e($user['full_name']); ?></span>
            <button type="button" class="btn-primary" id="mobileLogoutBtn">Keluar</button>
        <?php else: ?>
            <a href="#" class="nav-link" id="mobileLoginBtn">Masuk</a>
            <button type="button" class="btn-primary" id="mobileRegisterBtn">Daftar</button>
        <?php endif; ?>
    </div>

    <div class="app-shell">
        <!-- Sidebar kategori -->
        <aside class="sidebar" aria-label="Kategori rumah dan villa">
            <div class="sidebar-heading">Kategori</div>
            <div class="category-list">
                <button type="button" class="category-item active" data-category="all">
                    <i class="fas fa-th-large"></i>
                    <span>Semua</span>
                </button>
                <button type="button" class="category-item" data-category="bali">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Bali</span>
                </button>
                <button type="button" class="category-item" data-category="lembang">
                    <i class="fas fa-mountain"></i>
                    <span>Lembang</span>
                </button>
                <button type="button" class="category-item" data-category="dieng">
                    <i class="fas fa-tree"></i>
                    <span>Dieng</span>
                </button>
                <button type="button" class="category-item" data-category="jawa-tengah">
                    <i class="fas fa-leaf"></i>
                    <span>Jawa Tengah</span>
                </button>
                <button type="button" class="category-item" data-category="yogyakarta">
                    <i class="fas fa-landmark"></i>
                    <span>Yogyakarta</span>
                </button>
                <button type="button" class="category-item" data-category="bromo">
                    <i class="fas fa-fire"></i>
                    <span>Bromo</span>
                </button>
                <button type="button" class="category-item" data-category="lombok">
                    <i class="fas fa-sun"></i>
                    <span>Lombok</span>
                </button>
                <button type="button" class="category-item" data-category="labuan-bajo">
                    <i class="fas fa-ship"></i>
                    <span>Labuan Bajo</span>
                </button>
                <button type="button" class="category-item" data-category="raja-ampat">
                    <i class="fas fa-water"></i>
                    <span>Raja Ampat</span>
                </button>
                <button type="button" class="category-item" data-category="danau-toba">
                    <i class="fas fa-water"></i>
                    <span>Danau Toba</span>
                </button>
                <button type="button" class="category-item" data-category="belitung">
                    <i class="fas fa-umbrella-beach"></i>
                    <span>Belitung</span>
                </button>
                <button type="button" class="category-item" data-category="sumba">
                    <i class="fas fa-horse"></i>
                    <span>Sumba</span>
                </button>
                <button type="button" class="category-item" data-category="wakatobi">
                    <i class="fas fa-fish"></i>
                    <span>Wakatobi</span>
                </button>
            </div>

            <div class="sidebar-promo">
                <i class="fas fa-headset"></i>
                <strong>Butuh bantuan?</strong>
                <p>Tim DAFF AIRBNB siap membantu Anda kapan saja, 24/7.</p>
            </div>
        </aside>

        <!-- Konten utama -->
        <main class="main-content">
            <!-- Hero Section -->
            <section class="hero-wrap">
                <div class="hero">
                    <div class="hero-overlay"></div>
                    <div class="hero-content">
                        <span class="hero-eyebrow"><i class="fas fa-house-chimney"></i> Sewa rumah &amp; villa terpercaya di Indonesia</span>
                        <h1>Temukan <span class="highlight">Rumah &amp; Villa</span> Impian Anda</h1>
                        <p>Jelajahi katalog rumah dan villa terbaik di destinasi favorit Indonesia</p>
                        <div class="hero-badges">
                            <span class="hero-badge"><i class="fas fa-shield-halved"></i> Aman &amp; Terpercaya</span>
                            <span class="hero-badge"><i class="fas fa-tags"></i> Harga Terbaik</span>
                            <span class="hero-badge"><i class="fas fa-headset"></i> Dukungan 24/7</span>
                        </div>
                    </div>

                    <!-- Ornamen batik: motif kawung -->
                    <div class="batik-ornament batik-ornament--kiri-atas" aria-hidden="true">
                        <svg viewBox="0 0 200 200">
                            <g fill="none" stroke="currentColor" stroke-width="1.6">
                                <circle cx="100" cy="100" r="96"/>
                                <circle cx="100" cy="100" r="86" stroke-dasharray="3 8"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(45 100 100)"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(90 100 100)"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(135 100 100)"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(180 100 100)"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(225 100 100)"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(270 100 100)"/>
                                <ellipse cx="100" cy="56" rx="14" ry="26" transform="rotate(315 100 100)"/>
                                <circle cx="100" cy="100" r="17"/>
                                <ellipse cx="100" cy="84" rx="9" ry="14"/>
                                <ellipse cx="116" cy="100" rx="9" ry="14"/>
                                <ellipse cx="100" cy="116" rx="9" ry="14"/>
                                <ellipse cx="84" cy="100" rx="9" ry="14"/>
                            </g>
                        </svg>
                    </div>
                    <div class="batik-ornament batik-ornament--kanan-bawah" aria-hidden="true">
                        <svg viewBox="0 0 200 200">
                            <g fill="none" stroke="currentColor" stroke-width="1.6">
                                <circle cx="100" cy="100" r="96"/>
                                <circle cx="100" cy="100" r="86" stroke-dasharray="3 8"/>
                                <path d="M100 4 L118 82 L196 100 L118 118 L100 196 L82 118 L4 100 L82 82 Z"/>
                                <circle cx="100" cy="100" r="24"/>
                                <path d="M100 76 L112 88 L124 100 L112 112 L100 124 L88 112 L76 100 L88 88 Z"/>
                                <circle cx="100" cy="100" r="6"/>
                            </g>
                        </svg>
                    </div>
                </div>

                <!-- Search Card -->
                <div class="hero-search">
                    <div class="hero-search-main">
                        <div class="search-group search-group-location">
                            <label for="heroLocation"><i class="fas fa-map-marker-alt"></i> Lokasi</label>
                            <input type="text" placeholder="Kota atau lokasi" id="heroLocation">
                        </div>
                        <div class="search-group search-group-dates" id="heroDateGroup">
                            <button type="button" class="search-date-trigger" id="heroDateBtn" aria-haspopup="dialog" aria-controls="searchCalendarOverlay">
                                <span class="search-group-label"><i class="fas fa-calendar-days"></i> Tanggal</span>
                                <strong id="heroDateValue">Tambahkan tanggal</strong>
                                <small id="heroDateHint">Check-in &ndash; check-out</small>
                            </button>
                            <button type="button" class="search-date-clear" id="heroDateClear" aria-label="Hapus tanggal pencarian" hidden>
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                        <div class="search-group">
                            <label for="heroGuests"><i class="fas fa-users"></i> Tamu</label>
                            <select id="heroGuests">
                                <option value="0">Semua Tamu</option>
                                <option value="1">1+ Orang</option>
                                <option value="2">2+ Orang</option>
                                <option value="3">3+ Orang</option>
                                <option value="4">4+ Orang</option>
                                <option value="5">5+ Orang</option>
                            </select>
                        </div>
                        <button type="button" class="search-hero-btn" id="heroSearchBtn">
                            <i class="fas fa-search"></i> Cari
                        </button>
                    </div>
                    <div class="hero-search-sub">
                        <div class="search-group">
                            <label for="heroBedrooms"><i class="fas fa-bed"></i> Kamar Tidur</label>
                            <select id="heroBedrooms">
                                <option value="0">Semua Kamar</option>
                                <option value="1">1+ Kamar</option>
                                <option value="2">2+ Kamar</option>
                                <option value="3">3+ Kamar</option>
                                <option value="4">4+ Kamar</option>
                            </select>
                        </div>
                        <div class="search-group">
                            <label for="heroBudget"><i class="fas fa-money-bill-wave"></i> Anggaran / malam</label>
                            <select id="heroBudget">
                                <option value="all">Semua Harga</option>
                                <option value="under-250">Di bawah Rp 250 rb</option>
                                <option value="250-400">Rp 250 - 400 rb</option>
                                <option value="400-600">Rp 400 - 600 rb</option>
                                <option value="over-600">Di atas Rp 600 rb</option>
                            </select>
                        </div>
                        <p class="hero-search-note" id="heroSearchNote">
                            <i class="fas fa-circle-info"></i>
                            Pilih tanggal untuk melihat properti yang masih tersedia.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Featured Properties -->
            <section class="section properties-section" id="properties">
                <div class="section-header section-header--left">
                    <div>
                        <span class="section-eyebrow">Katalog pilihan</span>
                        <h2>Rumah &amp; Villa Unggulan</h2>
                        <p>Pilihan rumah dan villa terverifikasi di destinasi favorit Indonesia</p>
                    </div>
                </div>
                <div class="catalog-meta" id="catalogMeta" aria-live="polite"></div>
                <div class="property-grid" id="propertyGrid">
                    <!-- Properties will be loaded dynamically -->
                </div>
            </section>

            <div class="batik-divider" aria-hidden="true"></div>

            <!-- Why Choose Us -->
            <section class="section features-section">
                <div class="section-header section-header--left">
                    <div>
                        <span class="section-eyebrow">Kenapa memilih kami</span>
                        <h2>Mengapa DAFF AIRBNB?</h2>
                        <p>Keunggulan yang membuat kami berbeda</p>
                    </div>
                </div>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>Aman &amp; Terpercaya</h3>
                        <p>Semua rumah dan villa telah diverifikasi dan didampingi agen untuk kenyamanan Anda</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <h3>Harga Terbaik</h3>
                        <p>Jaminan harga terbaik tanpa biaya tersembunyi. Bandingkan dan temukan properti yang paling sesuai</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-headset"></i>
                        </div>
                        <h3>Dukungan 24/7</h3>
                        <p>Tim customer service kami siap membantu Anda kapan saja selama 24 jam penuh</p>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                        <h3>Lokasi Eksklusif</h3>
                        <p>Akses ke rumah dan villa di lokasi strategis serta kawasan populer di Indonesia</p>
                    </div>
                </div>
            </section>

            <!-- CTA Section -->
            <section class="cta-section">
                <div class="cta-content">
                    <h2>Siap Menemukan Rumah &amp; Villa Impian?</h2>
                    <p>Temukan ratusan rumah dan villa di seluruh Indonesia yang sesuai dengan kebutuhan Anda</p>
                    <a href="#properties" class="btn-primary btn-large" id="ctaBrowseBtn">Cari Rumah &amp; Villa</a>
                </div>
            </section>
        </main>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="index.php" class="logo">
                        <i class="fas fa-home"></i>
                        <span>DAFF AIRBNB</span>
                    </a>
                    <p>Platform sewa rumah dan villa terbaik di Indonesia. Temukan properti impian Anda dengan proses yang mudah dan terpercaya.</p>
                </div>
                <div class="footer-links">
                    <h4>DAFF AIRBNB</h4>
                    <a href="#">Tentang Kami</a>
                    <a href="#">Cara Kerja</a>
                    <a href="#">Karir</a>
                    <a href="#">Press</a>
                </div>
                <div class="footer-links">
                    <h4>Dukungan</h4>
                    <a href="#">Pusat Bantuan</a>
                    <a href="#">Hubungi Kami</a>
                    <a href="#">Kebijakan Privasi</a>
                    <a href="#">Syarat &amp; Ketentuan</a>
                </div>
                <div class="footer-links">
                    <h4>Jelajahi</h4>
                    <a href="#">Indonesia</a>
                    <a href="#">Bali</a>
                    <a href="#">Lembang</a>
                    <a href="#">Raja Ampat</a>
                    <a href="#">Danau Toba</a>
                    <a href="#">Belitung</a>
                    <a href="#">Sumba</a>
                    <a href="#">Wakatobi</a>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 DAFF AIRBNB. Semua hak dilindungi.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privasi</a>
                    <a href="#">Ketentuan</a>
                    <a href="#">Sitemap</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Login Modal -->
    <div class="modal-overlay" id="loginModal">
        <div class="modal">
            <button type="button" class="modal-close" id="closeLogin">&times;</button>
            <div class="modal-header">
                <h2>Selamat Datang di DAFF AIRBNB</h2>
                <p>Masuk untuk melanjutkan pemesanan</p>
            </div>
            <form class="modal-form" id="loginForm">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="contoh@email.com" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Masukkan password" required>
                </div>
                <div class="form-error" id="loginError"></div>
                <button type="submit" class="btn-primary btn-full">Masuk</button>
                <p class="form-footer">Belum punya akun? <a href="#" id="switchToRegister">Daftar</a></p>
            </form>
        </div>
    </div>

    <!-- Register Modal -->
    <div class="modal-overlay" id="registerModal">
        <div class="modal">
            <button type="button" class="modal-close" id="closeRegister">&times;</button>
            <div class="modal-header">
                <h2>Buat Akun Baru</h2>
                <p>Daftar untuk mulai menyewa rumah impian Anda</p>
            </div>
            <form class="modal-form" id="registerForm">
                <div class="form-group">
                    <label>Nama Lengkap</label>
                    <input type="text" name="full_name" placeholder="Nama lengkap" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="contoh@email.com" required>
                </div>
                <div class="form-group">
                    <label>No. HP</label>
                    <input type="text" name="phone" placeholder="08xxxxxxxxxx">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Minimal 6 karakter" required>
                </div>
                <div class="form-error" id="registerError"></div>
                <button type="submit" class="btn-primary btn-full">Daftar</button>
                <div class="form-divider"><span>atau</span></div>
                <button type="button" class="btn-google">
                    <i class="fab fa-google"></i> Daftar dengan Google
                </button>
                <p class="form-footer">Sudah punya akun? <a href="#" id="switchToLogin">Masuk</a></p>
            </form>
        </div>
    </div>

    <!-- Property Detail Modal -->
    <div class="modal-overlay" id="propertyModal">
        <div class="modal modal-large">
            <button type="button" class="modal-close" id="closeProperty">&times;</button>
            <div id="propertyDetail">
                <!-- Detail content loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- My Orders Modal -->
    <div class="modal-overlay" id="ordersModal">
        <div class="modal modal-large">
            <button type="button" class="modal-close" id="closeOrders">&times;</button>
            <div class="modal-header">
                <h2>Pesanan Saya</h2>
                <p>Pantau status pembayaran dan konfirmasi pesanan Anda</p>
            </div>
            <div class="modal-form" id="ordersList">
                <!-- Orders loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Booking Success Toast -->
    <div class="toast" id="toast">
        <i class="fas fa-check-circle"></i>
        <span id="toastMessage">Berhasil!</span>
    </div>

    <script src="script.js?v=<?php echo e((string) filemtime(__DIR__ . '/script.js')); ?>"></script>
</body>
</html>
