<?php
declare(strict_types=1);

// Zona waktu aplikasi: WIB (UTC+7). Dipakai untuk tanggal pemesanan,
// jam live, dan perhitungan stok kamar.
const APP_TIMEZONE = 'Asia/Jakarta';
date_default_timezone_set(APP_TIMEZONE);

// Konfigurasi database bisa dioverride lewat environment variable.
// Default mengikuti Laragon: root tanpa password.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'daff_airbnb');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Pastikan NOW()/CURDATE() MySQL sama persis dengan WIB,
            // bukan mengikuti zona waktu bawaan server.
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}