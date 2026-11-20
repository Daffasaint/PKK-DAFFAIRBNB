<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/destination_rules.php';
require_once __DIR__ . '/../includes/availability.php';

require_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'Method not allowed.']);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$hasStayFilter = $startDate !== '' || $endDate !== '';
$nights = 0;

if ($hasStayFilter) {
    if (!valid_ymd($startDate) || !valid_ymd($endDate)) {
        json_response(422, ['error' => 'Format tanggal pencarian tidak valid.']);
    }

    if ($startDate < app_today()) {
        json_response(422, ['error' => 'Tanggal check-in tidak boleh sebelum hari ini.']);
    }

    if ($endDate <= $startDate) {
        json_response(422, ['error' => 'Tanggal check-out harus setelah tanggal check-in.']);
    }

    $nights = (int) ymd_to_date($startDate)->diff(ymd_to_date($endDate))->days;
    if ($nights < 1 || $nights > 30) {
        json_response(422, ['error' => 'Durasi pencarian harus antara 1 sampai 30 malam.']);
    }
}

try {
    $stmt = db()->query(
        'SELECT id, name, location, category, price, weekend_price,
                long_stay_min_days, long_stay_discount_percent, rating, reviews, guests, stock,
                bedrooms, bathrooms, badge, image_main, image_2, image_3, description, features
         FROM properties
         WHERE is_active = 1
         ORDER BY rating DESC'
    );
    $rows = $stmt->fetchAll();

    $properties = [];
    foreach ($rows as $row) {
        $row['id']       = (int) $row['id'];
        $row['price']    = (int) $row['price'];
        $row['weekend_price'] = $row['weekend_price'] !== null ? (int) $row['weekend_price'] : null;
        $row['long_stay_min_days'] = $row['long_stay_min_days'] !== null ? (int) $row['long_stay_min_days'] : null;
        $row['long_stay_discount_percent'] = (float) $row['long_stay_discount_percent'];
        $row['rating']   = (float) $row['rating'];
        $row['reviews']  = (int) $row['reviews'];
        $row['guests']   = (int) $row['guests'];
        $row['stock']    = max(1, (int) $row['stock']);
        $row['bedrooms'] = (int) $row['bedrooms'];
        $row['bathrooms'] = (int) $row['bathrooms'];
        $row['features'] = json_decode((string) $row['features'], true) ?: [];
        $row['images']   = array_values(array_filter([
            $row['image_main'], $row['image_2'], $row['image_3'],
        ]));
        $row['destination_key'] = booking_destination_key($row);
        $row['rules'] = booking_destination_rules_for_property($row);
        unset($row['image_main'], $row['image_2'], $row['image_3']);
        $properties[] = $row;
    }

    if ($hasStayFilter) {
        $availability = get_properties_availability_batch($properties, $startDate, $nights);
        $checkoutDate = ymd_to_date($startDate)->modify('+' . $nights . ' days')->format('Y-m-d');
        $availableProperties = [];

        foreach ($properties as $property) {
            $rules = $property['rules'];
            $minNights = max(1, (int) $rules['min_nights']);
            $maxNights = max($minNights, (int) $rules['max_nights']);

            if ($nights < $minNights || $nights > $maxNights) {
                continue;
            }

            $stayAvailability = $availability[$property['id']] ?? null;
            if ($stayAvailability === null || !$stayAvailability['available']) {
                continue;
            }

            $property['stay'] = [
                'start_date'    => $startDate,
                'checkout_date' => $checkoutDate,
                'nights'        => $nights,
                'remaining'     => $stayAvailability['remaining'],
            ];
            $availableProperties[] = $property;
        }

        $properties = $availableProperties;
    }

    json_response(200, [
        'properties' => $properties,
        'search'     => $hasStayFilter ? [
            'start_date'    => $startDate,
            'checkout_date' => $checkoutDate,
            'nights'        => $nights,
            'count'         => count($properties),
            'today'         => app_today(),
        ] : null,
    ]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
