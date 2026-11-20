-- ============================================================
-- DAFF AIRBNB - Migration: property management, pricing,
-- blocked dates, and invoice snapshots.
-- Jalankan sekali pada database yang sudah ada.
-- ============================================================

USE daff_airbnb;

-- Harga per properti.
ALTER TABLE properties
  ADD COLUMN weekend_price DECIMAL(12,0) DEFAULT NULL AFTER price,
  ADD COLUMN long_stay_min_days INT UNSIGNED DEFAULT NULL AFTER weekend_price,
  ADD COLUMN long_stay_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER long_stay_min_days,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER badge;

-- Tanggal yang diblokir manual oleh owner.
CREATE TABLE IF NOT EXISTS property_blocked_dates (
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

-- Snapshot rincian harga pada order, agar invoice tidak berubah
-- ketika harga properti diperbarui di kemudian hari.
ALTER TABLE orders
  ADD COLUMN base_total DECIMAL(12,0) DEFAULT NULL AFTER total,
  ADD COLUMN discount_total DECIMAL(12,0) NOT NULL DEFAULT 0 AFTER base_total,
  ADD COLUMN service_fee DECIMAL(12,0) DEFAULT NULL AFTER discount_total,
  ADD COLUMN pricing_detail JSON DEFAULT NULL AFTER service_fee;

-- Backfill order lama (sebelum fitur harga).
UPDATE orders
SET base_total = ROUND(total / 1.05),
    service_fee = total - ROUND(total / 1.05)
WHERE base_total IS NULL;
