-- ============================================================
-- MIGRASI: Stok kamar / ketersediaan properti per tanggal
-- Jalankan sekali pada database daff_airbnb yang sudah ada.
-- Aman dijalankan ulang (idempotent).
-- ============================================================

USE daff_airbnb;

-- 1. Setiap properti punya jumlah unit (stok) yang bisa disewa
--    bersamaan pada tanggal yang sama. Default 1 unit.
SET @stock_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'properties'
    AND COLUMN_NAME = 'stock'
);

SET @stock_sql := IF(
  @stock_exists = 0,
  'ALTER TABLE properties ADD COLUMN stock INT UNSIGNED NOT NULL DEFAULT 1 AFTER guests',
  'SELECT ''Kolom properties.stock sudah ada.'' AS info'
);

PREPARE stock_stmt FROM @stock_sql;
EXECUTE stock_stmt;
DEALLOCATE PREPARE stock_stmt;

-- 2. Index untuk pengecekan overlap tanggal pesanan.
SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_availability'
);

SET @index_sql := IF(
  @index_exists = 0,
  'ALTER TABLE orders ADD KEY idx_orders_availability (property_id, status, start_date)',
  'SELECT ''Index idx_orders_availability sudah ada.'' AS info'
);

PREPARE index_stmt FROM @index_sql;
EXECUTE index_stmt;
DEALLOCATE PREPARE index_stmt;
