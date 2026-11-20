<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability.php';

require_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'Method not allowed.']);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$propertyId = (int) ($_GET['property_id'] ?? 0);
if ($propertyId <= 0) {
    json_response(422, ['error' => 'Properti tidak valid.']);
}

try {
    if (isset($_GET['month']) && trim((string) $_GET['month']) !== '') {
        $month = trim((string) $_GET['month']);

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            json_response(422, ['error' => 'Format bulan tidak valid. Gunakan YYYY-MM.']);
        }

        $availability = get_property_month_availability($propertyId, $month);
        if ($availability === null) {
            json_response(404, ['error' => 'Properti tidak ditemukan.']);
        }

        json_response(200, $availability);
    }

    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $days = (int) ($_GET['days'] ?? 1);

    if (!valid_ymd($startDate)) {
        json_response(422, ['error' => 'Tanggal mulai tidak valid.']);
    }
    if ($days < 1 || $days > 30) {
        json_response(422, ['error' => 'Durasi harus antara 1 sampai 30 hari.']);
    }

    $availability = get_property_availability($propertyId, $startDate, $days, null, true);
    if ($availability === null) {
        json_response(404, ['error' => 'Properti tidak ditemukan.']);
    }

    json_response(200, $availability);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
