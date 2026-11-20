-- ============================================================
-- DAFF AIRBNB - Database Schema + Seed Data
-- Run via: mysql -u root < database/daff_airbnb.sql
--
-- CATATAN KEAMANAN: akun demo di bawah memakai password default.
-- Segera ganti password setelah import sebelum dipakai di server publik.
-- ============================================================

CREATE DATABASE IF NOT EXISTS daff_airbnb
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE daff_airbnb;

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- USERS
-- role: 'admin'  -> pemilik (owner)
--       'user'   -> pengguna biasa
-- ------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  phone         VARCHAR(30)  DEFAULT NULL,
  password      VARCHAR(255) NOT NULL,
  role          ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PROPERTIES (katalog rumah sewa)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS properties;
CREATE TABLE properties (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150) NOT NULL,
  location      VARCHAR(150) NOT NULL,
  category      VARCHAR(50)  DEFAULT 'rumah',
  price         DECIMAL(12,0) NOT NULL,
  weekend_price DECIMAL(12,0) DEFAULT NULL,
  long_stay_min_days INT UNSIGNED DEFAULT NULL,
  long_stay_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  rating        DECIMAL(3,2)  NOT NULL DEFAULT 4.80,
  reviews       INT UNSIGNED NOT NULL DEFAULT 0,
  guests        INT UNSIGNED NOT NULL DEFAULT 1,
  stock         INT UNSIGNED NOT NULL DEFAULT 1,  -- jumlah unit yang bisa disewa bersamaan per tanggal
  bedrooms      INT UNSIGNED NOT NULL DEFAULT 1,
  bathrooms     INT UNSIGNED NOT NULL DEFAULT 1,
  badge         VARCHAR(60)   DEFAULT NULL,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  image_main    VARCHAR(500)  NOT NULL,
  image_2       VARCHAR(500)  DEFAULT NULL,
  image_3       VARCHAR(500)  DEFAULT NULL,
  description   TEXT,
  features      TEXT,           -- JSON array string, e.g. ["Wi-Fi","AC"]
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PROPERTY BLOCKED DATES (blokir tanggal manual oleh owner)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS property_blocked_dates;
CREATE TABLE property_blocked_dates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  property_id INT UNSIGNED NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  reason      VARCHAR(255) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_blocked_property_range (property_id, start_date, end_date),
  CONSTRAINT fk_blocked_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ORDERS (pesanan sewa)
-- status: 'pending_payment' -> menunggu pembayaran
--         'paid'            -> pembayaran diterima, menunggu owner
--         'confirmed'       -> dikonfirmasi owner
--         'rejected'        -> ditolak owner
--         'expired'         -> pembayaran kedaluwarsa
--         'cancelled'       -> dibatalkan user/owner
--         'completed'       -> masa sewa selesai
-- ------------------------------------------------------------
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS orders;
CREATE TABLE orders (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  property_id        INT UNSIGNED NOT NULL,
  start_date         DATE NOT NULL,
  days               INT UNSIGNED NOT NULL DEFAULT 1,
  total              DECIMAL(12,0) NOT NULL,
  base_total         DECIMAL(12,0) DEFAULT NULL,
  discount_total     DECIMAL(12,0) NOT NULL DEFAULT 0,
  service_fee        DECIMAL(12,0) DEFAULT NULL,
  pricing_detail     JSON DEFAULT NULL,
  status             ENUM('pending_payment','paid','confirmed','rejected','expired','cancelled','completed') NOT NULL DEFAULT 'pending_payment',
  payment_expires_at DATETIME DEFAULT NULL,
  paid_at            DATETIME DEFAULT NULL,
  admin_note         VARCHAR(255) DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orders_user (user_id),
  KEY idx_orders_property (property_id),
  KEY idx_orders_availability (property_id, status, start_date),
  CONSTRAINT fk_orders_user     FOREIGN KEY (user_id)     REFERENCES users (id)     ON DELETE CASCADE,
  CONSTRAINT fk_orders_property FOREIGN KEY (property_id) REFERENCES properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PAYMENTS (invoice & riwayat pembayaran via Xendit)
-- ------------------------------------------------------------
CREATE TABLE payments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        INT UNSIGNED NOT NULL,
  provider        VARCHAR(30)  NOT NULL DEFAULT 'xendit',
  external_id     VARCHAR(100) NOT NULL,
  invoice_id      VARCHAR(100) DEFAULT NULL,
  invoice_url     VARCHAR(500) DEFAULT NULL,
  amount          DECIMAL(12,0) NOT NULL,
  status          ENUM('PENDING','PAID','EXPIRED','FAILED') NOT NULL DEFAULT 'PENDING',
  payment_method  VARCHAR(50)  DEFAULT NULL,
  payment_channel VARCHAR(50)  DEFAULT NULL,
  paid_at         DATETIME     DEFAULT NULL,
  raw_payload     JSON         DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_external_id (external_id),
  UNIQUE KEY uq_payments_invoice_id (invoice_id),
  KEY idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO users (full_name, email, phone, password, role) VALUES
('DAFF Owner', 'owner@daff.com', '081234567890',
 '$2y$10$X3x8bLVRRucLYwmAea5wG.8i0GzuM2pecbaUoflgkSmLfLc2y2EPK', 'admin'),
('Budi Santoso', 'user@daff.com', '081298765432',
 '$2y$10$h83ZBJYYGPuOvvX/I8MNqefqaR8YaSUthIqeIaJTlrLcORP7J6AG.', 'user');

INSERT INTO properties
  (name, location, category, price, rating, reviews, guests, bedrooms, bathrooms,
   badge, image_main, image_2, image_3, description, features) VALUES
('Rumah Tropis Seminyak', 'Seminyak, Bali', 'rumah', 285000, 4.92, 234, 8, 4, 3,
 'Guest Favorite',
 'images/properties/seminyak-1.jpg',
 'images/properties/seminyak-2.jpg',
 'images/properties/seminyak-3.jpg',
 'Rumah tropis di jantung Seminyak dengan kolam renang privat dan taman hijau. Dekat Pantai Seminyak, Eat Street, dan butik lokal; cocok untuk keluarga atau rombongan.',
 '["Kolam Renang Private","Wi-Fi Gratis","Dapur Lengkap","AC","Parkir Mobil","BBQ Area","Taman","Laundry"]'),

('Villa Sejuk Lembang', 'Lembang, Bandung', 'villa', 165000, 4.85, 187, 4, 2, 1,
 'Superhost',
 'images/properties/lembang-sejuk-1.jpg',
 'images/properties/lembang-sejuk-2.jpg',
 'images/properties/lembang-sejuk-3.jpg',
 'Villa kayu di dataran tinggi Lembang dengan udara sejuk dan pemandangan perbukitan hijau. Dekat Orchid Forest, Floating Market, dan Farmhouse; nyaman untuk liburan keluarga.',
 '["Pemandangan Pegunungan","Perapian","Wi-Fi Gratis","Teras Pribadi","Dapur","Taman","Parkir","Air Panas"]'),

('Villa Pesisir Raja Ampat', 'Raja Ampat, Papua Barat', 'villa', 150000, 4.88, 156, 6, 3, 2,
 'Unique Stay',
 'images/properties/raja-ampat-1.jpg',
 'images/properties/raja-ampat-2.jpg',
 'images/properties/raja-ampat-3.jpg',
 'Villa tepi laut dengan dermaga privat di Raja Ampat, menghadap pulau karst dan laut biru jernih. Lokasi ideal untuk snorkeling, diving, dan menikmati matahari terbenam.',
 '["Ocean View","Dermaga Pribadi","Wi-Fi","AC","Dapur","Teras","Snorkeling","Parkir"]'),

('Rumah Pegunungan Lembang', 'Lembang, Bandung', 'rumah', 250000, 4.95, 98, 10, 5, 4,
 'Premium',
 'images/properties/lembang-gunung-1.jpg',
 'images/properties/lembang-gunung-2.jpg',
 'images/properties/lembang-gunung-3.jpg',
 'Rumah luas di kawasan sejuk Lembang dengan pemandangan pegunungan dan taman bermain. Fasilitas lengkap untuk keluarga besar, dekat kawasan wisata Lembang.',
 '["Taman Bermain","Wi-Fi","Dapur","Carport","Perapian","Ruang Keluarga","Laundry","BBQ Area"]'),

('Rumah Nelayan', 'Nusa Penida, Bali', 'rumah', 200000, 4.82, 203, 4, 2, 2,
 'Ocean View',
 'images/properties/nusa-penida-1.jpg',
 'images/properties/nusa-penida-2.jpg',
 'images/properties/nusa-penida-3.jpg',
 'Rumah pesisir di Nusa Penida dengan balkon menghadap laut dan suasana tropis yang tenang. Dekat Pantai Diamond, Kelingking, dan spot snorkeling Manta Point.',
 '["Ocean View","Balkon","Wi-Fi","AC","Dapur","Teras","Parkir","Antar Jemput Pelabuhan"]'),

('Rumah Minimalis Modern', 'Sanur, Bali', 'rumah', 315000, 4.91, 178, 6, 3, 3,
 'Top Rated',
 'images/properties/sanur-1.jpg',
 'images/properties/sanur-2.jpg',
 'images/properties/sanur-3.jpg',
 'Rumah modern minimalis di Sanur yang tenang, dengan taman asri dan kolam renang. Dekat Pantai Sanur, promenade sepeda, dan fasilitas umum.',
 '["Kolam Renang","Taman","Wi-Fi","Smart Home","AC","Garasi","Dapur Modern","Keamanan 24 Jam"]'),

('Rumah Pantai Batu Bolong', 'Canggu, Bali', 'rumah', 415000, 4.96, 89, 12, 6, 5,
 'Luxury',
 'images/properties/canggu-1.jpg',
 'images/properties/canggu-2.jpg',
 'images/properties/canggu-3.jpg',
 'Rumah mewah di Canggu dengan kolam renang menghadap taman tropis dan akses dekat ke Pantai Batu Bolong. Kamar luas, area yoga, dan lingkungan eksklusif untuk keluarga besar.',
 '["Kolam Renang","Suite Mewah","Ruang Kerja","Spa","Gym","Yoga Deck","Garasi","Keamanan 24 Jam"]'),

('Rumah Pesisir Karimunjawa', 'Karimunjawa, Jepara, Jawa Tengah', 'rumah', 135000, 4.83, 156, 4, 2, 1,
 'Island Escape',
 'images/properties/karimunjawa-1.jpg',
 'images/properties/karimunjawa-2.jpg',
 'images/properties/karimunjawa-3.jpg',
 'Rumah tropis tepi pantai di Karimunjawa dengan teras menghadap laut. Dekat Pantai Tanjung Gelam dan spot snorkeling; cocok untuk liburan santai atau tinggal lebih lama.',
 '["Beachfront","Teras","Wi-Fi","Taman Tropis","Dapur","Parkir","Snorkeling","Dermaga"]'),

('Villa Sawah Ubud', 'Ubud, Gianyar, Bali', 'villa', 425000, 4.94, 168, 4, 2, 2,
 'Guest Favorite',
 'images/properties/ubud-1.jpg',
 'images/properties/ubud-2.jpg',
 'images/properties/ubud-3.jpg',
 'Villa di tepi sawah Ubud dengan kolam renang privat dan pemandangan hutan tropis. Sepuluh menit ke Tegalalang Rice Terrace dan 15 menit ke pusat Ubud.',
 '["Kolam Renang Private","Pemandangan Sawah","Wi-Fi Gratis","AC","Dapur","Teras","Parkir","Sarapan Lokal"]'),

('Villa Tebing Uluwatu', 'Uluwatu, Badung, Bali', 'villa', 675000, 4.97, 112, 6, 3, 3,
 'Luxury',
 'images/properties/uluwatu-1.jpg',
 'images/properties/uluwatu-2.jpg',
 'images/properties/uluwatu-3.jpg',
 'Villa di tebing Uluwatu dengan infinity pool dan pemandangan Samudra Hindia. Dekat Pantai Padang Padang dan Pura Luhur Uluwatu; tempat terbaik untuk menikmati sunset.',
 '["Infinity Pool","Ocean View","Wi-Fi","AC","Dapur","Teras","Parkir","Butler"]'),

('Villa Senggigi Lombok', 'Senggigi, Lombok Barat, Nusa Tenggara Barat', 'villa', 365000, 4.89, 134, 6, 3, 3,
 'Ocean View',
 'images/properties/lombok-1.jpg',
 'images/properties/lombok-2.jpg',
 'images/properties/lombok-3.jpg',
 'Villa di perbukitan Senggigi dengan pemandangan Selat Lombok dan taman tropis. Sepuluh menit ke Pantai Senggigi dan 30 menit dari Bandara Lombok.',
 '["Ocean View","Kolam Renang","Wi-Fi","AC","Dapur","Teras","Parkir","Antar Jemput Bandara"]'),

('Rumah Joglo Yogyakarta', 'Prawirotaman, Yogyakarta', 'rumah', 295000, 4.90, 121, 6, 3, 2,
 'Cultural Stay',
 'images/properties/yogyakarta-1.jpg',
 'images/properties/yogyakarta-2.jpg',
 'images/properties/yogyakarta-3.jpg',
 'Rumah joglo dengan sentuhan modern di Prawirotaman. Dekat Keraton Yogyakarta, Malioboro, dan kawasan kuliner; cocok untuk liburan budaya bersama keluarga.',
 '["Arsitektur Joglo","Kolam Renang","Wi-Fi","AC","Dapur","Teras","Parkir","Sepeda"]'),

('Villa Bukit Labuan Bajo', 'Labuan Bajo, Nusa Tenggara Timur', 'villa', 485000, 4.92, 98, 4, 2, 2,
 'Sunset View',
 'images/properties/labuan-bajo-1.jpg',
 'images/properties/labuan-bajo-2.jpg',
 'images/properties/labuan-bajo-3.jpg',
 'Villa di bukit Labuan Bajo dengan pemandangan laut dan sunset. Dekat Pelabuhan Labuan Bajo untuk trip Komodo, Padar, dan Pink Beach.',
 '["Ocean View","Sunset Deck","Kolam Renang","Wi-Fi","AC","Dapur","Teras","Parkir"]'),

('Rumah Kayu Bromo', 'Tosari, Bromo, Jawa Timur', 'rumah', 235000, 4.86, 142, 4, 2, 1,
 'Mountain Escape',
 'images/properties/bromo-1.jpg',
 'images/properties/bromo-2.jpg',
 'images/properties/bromo-3.jpg',
 'Rumah kayu di Tosari dengan pemandangan pegunungan Bromo. Sekitar 30 menit ke Penanjakan dan kawah Bromo; cocok untuk sunrise trip dan liburan udara dingin.',
 '["Pemandangan Gunung","Perapian","Wi-Fi","Air Panas","Dapur","Teras","Parkir","Pemandu Sunrise"]'),

('Villa Pantai Karimunjawa', 'Karimunjawa, Jepara, Jawa Tengah', 'villa', 285000, 4.88, 109, 4, 2, 1,
 'Beachfront',
 'images/properties/karimunjawa-villa-1.jpg',
 'images/properties/karimunjawa-villa-2.jpg',
 'images/properties/karimunjawa-villa-3.jpg',
 'Villa tepi pantai di Karimunjawa dengan akses langsung ke pasir putih dan taman tropis. Dekat spot snorkeling dan Pantai Tanjung Gelam.',
 '["Beachfront","Kolam Renang","Wi-Fi","AC","Dapur","Teras","Parkir","Snorkeling"]'),

('Rumah Kayu Dieng', 'Dieng, Wonosobo, Jawa Tengah', 'rumah', 215000, 4.87, 96, 4, 2, 1,
 'Highland Retreat',
 'images/properties/dieng-1.jpg',
 'images/properties/dieng-2.jpg',
 'images/properties/dieng-3.jpg',
 'Rumah kayu A-frame di dataran tinggi Dieng dengan udara dingin dan pemandangan perbukitan. Dekat Telaga Warna, Kawah Sikidang, dan Candi Arjuna.',
 '["Pemandangan Pegunungan","Perapian","Wi-Fi","Air Panas","Dapur","Teras","Parkir","Selimut Hangat"]'),

('Villa Tepi Danau Toba', 'Danau Toba, Sumatera Utara', 'villa', 315000, 4.91, 74, 8, 3, 2,
 'Lake View',
 'images/properties/danau-toba-1.jpg',
 'images/properties/danau-toba-2.jpg',
 'images/properties/danau-toba-3.jpg',
 'Villa tenang di tepi Danau Toba dengan teras luas menghadap perbukitan hijau. Cocok untuk liburan keluarga yang ingin menikmati udara sejuk dan matahari terbit di atas danau.',
 '["Pemandangan Danau","Dermaga Pribadi","Wi-Fi Gratis","Perapian","Dapur Lengkap","Teras","Parkir","Air Panas"]'),

('Rumah Pantai Belitung', 'Belitung, Bangka Belitung', 'rumah', 275000, 4.89, 118, 6, 3, 2,
 'Island Escape',
 'images/properties/belitung-1.jpg',
 'images/properties/belitung-2.jpg',
 'images/properties/belitung-3.jpg',
 'Rumah pantai di Belitung dengan akses langsung ke pasir putih dan batu granit ikonik. Dekat Pantai Tanjung Tinggi dan spot snorkeling; ideal untuk keluarga dan pencinta laut.',
 '["Akses Pantai","Wi-Fi Gratis","AC","Dapur","Teras","Parkir","Snorkeling","BBQ Area"]'),

('Villa Bukit Sumba', 'Sumba, Nusa Tenggara Timur', 'villa', 525000, 4.93, 61, 8, 4, 3,
 'Savanna View',
 'images/properties/sumba-1.jpg',
 'images/properties/sumba-2.jpg',
 'images/properties/sumba-3.jpg',
 'Villa di perbukitan Sumba dengan pemandangan savana dan laut biru. Nikmati matahari terbenam dari kolam renang privat serta suasana tenang khas Sumba.',
 '["Kolam Renang Private","Savanna View","Wi-Fi","AC","Dapur Lengkap","Teras","Parkir","Sarapan Lokal"]'),

('Rumah Pesisir Wakatobi', 'Wakatobi, Sulawesi Tenggara', 'rumah', 385000, 4.90, 83, 6, 2, 2,
 'Dive Retreat',
 'images/properties/wakatobi-1.jpg',
 'images/properties/wakatobi-2.jpg',
 'images/properties/wakatobi-3.jpg',
 'Rumah pesisir di Wakatobi dengan dermaga kecil dan laut jernih tepat di depan pintu. Lokasi favorit untuk snorkeling, diving, dan menikmati ketenangan pulau.',
 '["Ocean View","Dermaga Pribadi","Wi-Fi","AC","Dapur","Teras","Peralatan Snorkeling","Parkir"]');

-- ------------------------------------------------------------
-- SAMPLE ORDERS (contoh data pesanan)
-- ------------------------------------------------------------
INSERT INTO orders (user_id, property_id, start_date, days, total, status, payment_expires_at, paid_at) VALUES
(2, 1, '2026-10-01', 7, 2094750, 'pending_payment', DATE_ADD(NOW(), INTERVAL 24 HOUR), NULL), -- Rp 285.000 x 7 hari + 5%
(2, 4, '2026-09-15', 6, 1575000, 'confirmed', NULL, '2026-09-06 21:37:31'),                     -- Rp 250.000 x 6 hari + 5%
(1, 5, '2026-11-01', 6, 1260000, 'confirmed', NULL, '2026-09-06 21:37:31');                     -- Rp 200.000 x 6 hari + 5%

-- Riwayat pembayaran untuk order confirmed (data demo).
INSERT INTO payments
  (order_id, provider, external_id, invoice_id, invoice_url, amount, status,
   payment_method, payment_channel, paid_at)
VALUES
(2, 'legacy', 'LEGACY-ORDER-2', 'legacy-invoice-2', NULL, 1575000, 'PAID', 'LEGACY', 'MANUAL', '2026-09-06 21:37:31'),
(3, 'legacy', 'LEGACY-ORDER-3', 'legacy-invoice-3', NULL, 1260000, 'PAID', 'LEGACY', 'MANUAL', '2026-09-06 21:37:31');

-- Backfill snapshot harga untuk data demo.
UPDATE orders
SET base_total = ROUND(total / 1.05),
    service_fee = total - ROUND(total / 1.05)
WHERE base_total IS NULL;

SET FOREIGN_KEY_CHECKS = 1;