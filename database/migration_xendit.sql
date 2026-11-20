-- ============================================================
-- MIGRASI: Pembayaran Xendit
-- Jalankan sekali pada database daff_airbnb yang sudah ada:
--   mysql -u root daff_airbnb < database/migration_xendit.sql
-- ============================================================

USE daff_airbnb;

-- 1. Tambahkan status baru tanpa menghapus status lama.
ALTER TABLE orders
  MODIFY status ENUM('pending','pending_payment','paid','confirmed','rejected','expired','cancelled','completed')
  NOT NULL DEFAULT 'pending';

-- 2. Mapping data lama: pending -> pending_payment.
UPDATE orders SET status = 'pending_payment' WHERE status = 'pending';

-- 3. Finalisasi enum + kolom pembayaran.
ALTER TABLE orders
  MODIFY status ENUM('pending_payment','paid','confirmed','rejected','expired','cancelled','completed')
  NOT NULL DEFAULT 'pending_payment',
  ADD COLUMN payment_expires_at DATETIME NULL AFTER status,
  ADD COLUMN paid_at DATETIME NULL AFTER payment_expires_at,
  ADD COLUMN admin_note VARCHAR(255) NULL AFTER paid_at;

-- 4. Tabel riwayat invoice/pembayaran.
CREATE TABLE IF NOT EXISTS payments (
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

-- 5. Beri waktu pembayaran untuk order lama yang belum dibayar.
UPDATE orders
SET payment_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
WHERE status = 'pending_payment' AND payment_expires_at IS NULL;

-- 6. Backfill riwayat pembayaran untuk order confirmed lama (data demo).
INSERT INTO payments
  (order_id, provider, external_id, invoice_id, invoice_url, amount, status,
   payment_method, payment_channel, paid_at, created_at, updated_at)
SELECT
  o.id,
  'legacy',
  CONCAT('LEGACY-ORDER-', o.id),
  CONCAT('legacy-invoice-', o.id),
  NULL,
  o.total,
  'PAID',
  'LEGACY',
  'MANUAL',
  o.created_at,
  o.created_at,
  o.created_at
FROM orders o
WHERE o.status = 'confirmed'
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id);
