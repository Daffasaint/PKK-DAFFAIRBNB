<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/xendit.php';
require_once __DIR__ . '/../includes/destination_rules.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$user = require_login();

$data = body();
$propertyId = (int) ($data['property_id'] ?? 0);
$startDate  = trim((string) ($data['start_date'] ?? ''));
$endDateInput = trim((string) ($data['end_date'] ?? ''));
$days       = (int) ($data['days'] ?? 0);

if ($propertyId <= 0 || $startDate === '') {
    json_response(422, ['error' => 'Data pemesanan tidak lengkap.']);
}

if (!valid_ymd($startDate)) {
    json_response(422, ['error' => 'Tanggal check-in tidak valid.']);
}

$today = app_today();
if ($startDate < $today) {
    json_response(422, [
        'error' => 'Tanggal check-in tidak boleh sebelum hari ini (' . format_date_id($today) . ').',
    ]);
}

// Check-out dipakai sebagai sumber utama durasi. Field days tetap didukung
// untuk kompatibilitas dengan klien lama.
if ($endDateInput !== '') {
    if (!valid_ymd($endDateInput)) {
        json_response(422, ['error' => 'Tanggal check-out tidak valid.']);
    }
    if ($endDateInput <= $startDate) {
        json_response(422, ['error' => 'Tanggal check-out harus setelah tanggal check-in.']);
    }

    $days = (int) ymd_to_date($startDate)->diff(ymd_to_date($endDateInput))->days;
} elseif ($days <= 0) {
    $days = 1;
}

if ($days < 1 || $days > 365) {
    json_response(422, ['error' => 'Durasi menginap tidak valid.']);
}

$expiryHours = max(1, (int) ceil(((int) xendit_config()['invoice_duration']) / 3600));
$pdo = db();

try {
    $pdo->beginTransaction();

    // Kunci baris properti agar dua user tidak bisa memesan tanggal yang
    // sama secara bersamaan (race condition).
    $stmt = $pdo->prepare(
        'SELECT id, name, location, category, guests, price, weekend_price,
                long_stay_min_days, long_stay_discount_percent, stock, is_active
         FROM properties
         WHERE id = ?
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$propertyId]);
    $property = $stmt->fetch();

    if (!$property || !(int) $property['is_active']) {
        $pdo->rollBack();
        json_response(404, ['error' => 'Rumah tidak ditemukan.']);
    }

    $rules = booking_destination_rules_for_property($property);
    $destinationLabel = (string) $rules['label'];

    if ($days < (int) $rules['min_nights']) {
        $pdo->rollBack();
        json_response(422, [
            'error' => 'Minimal menginap di ' . $destinationLabel . ' adalah ' . (int) $rules['min_nights'] . ' malam.',
        ]);
    }

    if ($days > (int) $rules['max_nights']) {
        $pdo->rollBack();
        json_response(422, [
            'error' => 'Maksimal menginap di ' . $destinationLabel . ' adalah ' . (int) $rules['max_nights'] . ' malam.',
        ]);
    }

    // Rincian tamu. Field boleh tidak dikirim oleh klien lama.
    $adults = isset($data['adults'])
        ? max(0, (int) $data['adults'])
        : min(2, (int) $rules['max_guests']);
    $children = isset($data['children']) ? max(0, (int) $data['children']) : 0;
    $infants  = isset($data['infants']) ? max(0, (int) $data['infants']) : 0;
    $pets     = isset($data['pets']) ? max(0, (int) $data['pets']) : 0;

    if ($adults < 1) {
        $pdo->rollBack();
        json_response(422, ['error' => 'Pesanan harus memiliki minimal 1 tamu dewasa.']);
    }

    if ($adults + $children > (int) $rules['max_guests']) {
        $pdo->rollBack();
        json_response(422, [
            'error' => 'Jumlah tamu melebihi kapasitas ' . $destinationLabel . '. Maksimal '
                . (int) $rules['max_guests'] . ' tamu dewasa dan anak (tidak termasuk bayi).',
        ]);
    }

    if ($infants > (int) $rules['max_infants']) {
        $pdo->rollBack();
        json_response(422, [
            'error' => 'Jumlah bayi melebihi ketentuan ' . $destinationLabel . '. Maksimal '
                . (int) $rules['max_infants'] . ' bayi.',
        ]);
    }

    if ($pets > 0 && !$rules['pets_allowed']) {
        $pdo->rollBack();
        json_response(422, [
            'error' => 'Hewan peliharaan tidak diizinkan untuk destinasi ' . $destinationLabel . '.',
        ]);
    }

    if ($pets > (int) $rules['max_pets']) {
        $pdo->rollBack();
        json_response(422, [
            'error' => 'Jumlah hewan peliharaan melebihi ketentuan ' . $destinationLabel . '. Maksimal '
                . (int) $rules['max_pets'] . ' hewan.',
        ]);
    }

    $guests = [
        'adults'   => $adults,
        'children' => $children,
        'infants'  => $infants,
        'pets'     => $pets,
    ];

    $endDate = booking_end_date($startDate, $days);
    $checkoutDate = booking_end_exclusive($startDate, $days);

    $availability = get_property_availability($propertyId, $startDate, $days);

    if ($availability === null || !$availability['available']) {
        $pdo->rollBack();

        $blocked = $availability['blocked_dates'] ?? [];
        $blockedLabels = array_map(
            static fn (string $date): string => format_date_id($date, false),
            array_slice($blocked, 0, 3)
        );
        $extra = count($blocked) > 3 ? ' dan ' . (count($blocked) - 3) . ' tanggal lainnya' : '';

        $message = $blocked !== []
            ? 'Tanggal berikut sudah tidak tersedia: ' . implode(', ', $blockedLabels) . $extra . '. Silakan pilih tanggal lain.'
            : 'Tanggal yang dipilih sudah tidak tersedia. Silakan pilih tanggal lain.';

        json_response(409, [
            'error'         => $message,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'checkout_date' => $checkoutDate,
            'days'          => $days,
            'blocked_dates' => $blocked,
            'stock'         => (int) ($availability['stock'] ?? max(1, (int) $property['stock'])),
        ]);
    }

    $pricing = calculate_booking_price($property, $startDate, $days);
    $totalWithFee = (int) $pricing['total'];

    // Simpan ketentuan & rincian tamu sebagai snapshot agar invoice dan
    // riwayat pesanan tetap menampilkan data saat pemesanan dibuat.
    $pricing['nights']        = $days;
    $pricing['checkin_date']  = $startDate;
    $pricing['checkout_date'] = $checkoutDate;
    $pricing['guests']        = $guests;
    $pricing['guest_summary'] = booking_guest_summary($guests);
    $pricing['destination']   = [
        'key'           => (string) $rules['key'],
        'label'         => $destinationLabel,
        'checkin_time'  => (string) $rules['checkin_time'],
        'checkout_time' => (string) $rules['checkout_time'],
        'min_nights'    => (int) $rules['min_nights'],
        'max_nights'    => (int) $rules['max_nights'],
        'max_guests'    => (int) $rules['max_guests'],
        'max_infants'   => (int) $rules['max_infants'],
        'pets_allowed'  => (bool) $rules['pets_allowed'],
        'max_pets'      => (int) $rules['max_pets'],
    ];

    $insert = $pdo->prepare(
        "INSERT INTO orders
            (user_id, property_id, start_date, days, total, base_total,
             discount_total, service_fee, pricing_detail, status, payment_expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', DATE_ADD(NOW(), INTERVAL ? HOUR))"
    );
    $insert->execute([
        $user['id'],
        $propertyId,
        $startDate,
        $days,
        $totalWithFee,
        (int) $pricing['base_total'],
        (int) $pricing['discount_total'],
        (int) $pricing['service_fee'],
        json_encode($pricing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $expiryHours,
    ]);

    $orderId = (int) $pdo->lastInsertId();
    $pdo->commit();

    json_response(201, [
        'message'       => 'Pesanan berhasil dibuat. Silakan lakukan pembayaran dalam ' . $expiryHours . ' jam.',
        'order_id'      => $orderId,
        'total'         => $totalWithFee,
        'base_total'    => (int) $pricing['base_total'],
        'discount_total'=> (int) $pricing['discount_total'],
        'service_fee'   => (int) $pricing['service_fee'],
        'pricing'       => $pricing,
        'start_date'    => $startDate,
        'end_date'      => $endDate,
        'checkout_date' => $checkoutDate,
        'days'          => $days,
        'guests'        => $guests,
        'guest_summary' => booking_guest_summary($guests),
        'destination'   => $rules['label'],
        'rules'         => $rules,
        'stock'         => (int) $availability['stock'],
        'remaining'     => max(0, (int) $availability['remaining'] - 1),
        'payment_url'   => 'pembayaran.php?order_id=' . $orderId,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    xendit_log('Gagal membuat pesanan', [
        'property_id' => $propertyId,
        'start_date'  => $startDate,
        'end_date'    => $endDateInput,
        'days'        => $days,
        'error'       => $e->getMessage(),
    ]);
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
