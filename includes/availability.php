<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/destination_rules.php';

/**
 * Waktu sekarang menurut zona waktu aplikasi (WIB), bukan jam perangkat user.
 */
function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function app_today(): string
{
    return app_now()->format('Y-m-d');
}

function valid_ymd(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(APP_TIMEZONE));
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function ymd_to_date(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone(APP_TIMEZONE));
}

/**
 * Tanggal terakhir menginap (inklusif).
 * Contoh: mulai 1 Nov, 3 hari -> 3 Nov.
 */
function booking_end_date(string $startDate, int $days): string
{
    $days = max(1, $days);
    return ymd_to_date($startDate)->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
}

/**
 * Batas eksklusif akhir sewa, dipakai untuk query overlap.
 * Contoh: mulai 1 Nov, 3 hari -> 4 Nov 00:00.
 */
function booking_end_exclusive(string $startDate, int $days): string
{
    $days = max(1, $days);
    return ymd_to_date($startDate)->modify('+' . $days . ' days')->format('Y-m-d');
}

/**
 * @return string[] daftar tanggal Y-m-d sebanyak $days, mulai dari $startDate.
 */
function booking_date_range(string $startDate, int $days): array
{
    $days = max(1, $days);
    $dates = [];
    $cursor = ymd_to_date($startDate);
    for ($i = 0; $i < $days; $i++) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}

function format_date_id(string $date, bool $withWeekday = true): string
{
    if (!valid_ymd($date)) {
        return $date;
    }

    $dt = ymd_to_date($date);
    $weekdays = [
        'Sunday'    => 'Minggu',
        'Monday'    => 'Senin',
        'Tuesday'   => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday'  => 'Kamis',
        'Friday'    => 'Jumat',
        'Saturday'  => 'Sabtu',
    ];
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $label = $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y');
    return $withWeekday ? $weekdays[$dt->format('l')] . ', ' . $label : $label;
}

function format_date_short_id(string $date): string
{
    if (!valid_ymd($date)) {
        return $date;
    }

    $dt = ymd_to_date($date);
    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    return $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y');
}

function format_date_range_id(string $startDate, int $days): string
{
    if ($days <= 1) {
        return format_date_short_id($startDate);
    }

    return format_date_short_id($startDate) . ' - ' . format_date_short_id(booking_end_date($startDate, $days));
}

/**
 * Rentang check-in s/d check-out. Tanggal check-out adalah hari kepulangan
 * (tidak termasuk malam menginap), sesuai dengan tampilan pemesanan.
 */
function format_stay_range_id(string $startDate, int $days): string
{
    $days = max(1, $days);
    return format_date_short_id($startDate) . ' - ' . format_date_short_id(booking_end_exclusive($startDate, $days));
}

/**
 * Ambil order yang masih mengunci tanggal pada rentang tertentu.
 *
 * Status yang mengunci:
 * - paid, confirmed, completed
 * - pending_payment selama batas pembayaran belum lewat
 *
 * Status rejected, cancelled, expired tidak mengunci tanggal.
 */
function active_order_ranges_for_property(
    int $propertyId,
    string $fromDate,
    string $toExclusive,
    ?int $excludeOrderId = null
): array {
    $sql = "SELECT id, start_date, days, status, payment_expires_at
            FROM orders
            WHERE property_id = ?
              AND start_date < ?
              AND DATE_ADD(start_date, INTERVAL `days` DAY) > ?
              AND (
                    status IN ('paid', 'confirmed', 'completed')
                    OR (
                        status = 'pending_payment'
                        AND (payment_expires_at IS NULL OR payment_expires_at > NOW())
                    )
                  )";
    $params = [$propertyId, $toExclusive, $fromDate];

    if ($excludeOrderId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeOrderId;
    }

    $sql .= ' ORDER BY start_date ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Ambil rentang tanggal yang diblokir manual oleh owner.
 *
 * @return array<int,array<string,mixed>>
 */
function active_blocked_ranges_for_property(
    int $propertyId,
    string $fromDate,
    string $toExclusive
): array {
    $stmt = db()->prepare(
        "SELECT id, start_date, end_date, reason
         FROM property_blocked_dates
         WHERE property_id = ?
           AND start_date < ?
           AND DATE_ADD(end_date, INTERVAL 1 DAY) > ?
         ORDER BY start_date ASC"
    );
    $stmt->execute([$propertyId, $toExclusive, $fromDate]);

    return $stmt->fetchAll();
}

/**
 * Hitung ketersediaan satu properti untuk rentang tanggal tertentu.
 *
 * @return array<string,mixed>|null null jika properti tidak ditemukan.
 */
function get_property_availability(
    int $propertyId,
    string $startDate,
    int $days,
    ?int $excludeOrderId = null,
    bool $includePricing = false
): ?array {
    if (!valid_ymd($startDate)) {
        return null;
    }

    $days = max(1, min(365, $days));

    $stmt = db()->prepare(
        'SELECT id, name, location, category, guests, price, weekend_price, long_stay_min_days,
                long_stay_discount_percent, stock
         FROM properties
         WHERE id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$propertyId]);
    $property = $stmt->fetch();

    if (!$property) {
        return null;
    }

    $stock = max(1, (int) $property['stock']);
    $endExclusive = booking_end_exclusive($startDate, $days);
    $orders = active_order_ranges_for_property($propertyId, $startDate, $endExclusive, $excludeOrderId);
    $blockedRanges = active_blocked_ranges_for_property($propertyId, $startDate, $endExclusive);

    // Precompute rentang order agar pengecekan per tanggal tidak berulang.
    $ranges = [];
    foreach ($orders as $order) {
        $orderStart = ymd_to_date((string) $order['start_date']);
        $orderDays = max(1, (int) $order['days']);
        $ranges[] = [
            'start' => $orderStart,
            'end'   => $orderStart->modify('+' . $orderDays . ' days'),
        ];
    }

    $blockedRangeObjects = [];
    foreach ($blockedRanges as $block) {
        $blockedRangeObjects[] = [
            'start'  => ymd_to_date((string) $block['start_date']),
            'end'    => ymd_to_date((string) $block['end_date']),
            'reason' => (string) ($block['reason'] ?? ''),
        ];
    }

    $dateDetails = [];
    $blockedDates = [];
    $minRemaining = $stock;
    $cursor = ymd_to_date($startDate);
    $end = ymd_to_date($endExclusive);

    while ($cursor < $end) {
        $booked = 0;
        foreach ($ranges as $range) {
            if ($range['start'] <= $cursor && $cursor < $range['end']) {
                $booked++;
            }
        }

        $blockReason = null;
        foreach ($blockedRangeObjects as $block) {
            if ($block['start'] <= $cursor && $cursor <= $block['end']) {
                $blockReason = $block['reason'];
                break;
            }
        }

        $remaining = $blockReason !== null ? 0 : max(0, $stock - $booked);
        $date = $cursor->format('Y-m-d');
        $dateDetails[] = [
            'date'         => $date,
            'booked'       => $booked,
            'remaining'    => $remaining,
            'available'    => $remaining > 0,
            'blocked'      => $blockReason !== null,
            'block_reason' => $blockReason,
        ];

        if ($remaining <= 0) {
            $blockedDates[] = $date;
        }
        $minRemaining = min($minRemaining, $remaining);
        $cursor = $cursor->modify('+1 day');
    }

    $result = [
        'property_id'   => (int) $property['id'],
        'property_name' => (string) $property['name'],
        'price'         => (int) $property['price'],
        'weekend_price' => $property['weekend_price'] !== null ? (int) $property['weekend_price'] : null,
        'stock'         => $stock,
        'days'          => $days,
        'start_date'    => $startDate,
        'end_date'      => booking_end_date($startDate, $days),
        'checkout_date' => booking_end_exclusive($startDate, $days),
        'available'     => $blockedDates === [],
        'blocked_dates' => $blockedDates,
        'remaining'     => $minRemaining,
        'dates'         => $dateDetails,
        'today'         => app_today(),
        'server_time'   => app_now()->format('c'),
        'timezone'      => APP_TIMEZONE,
        'rules'         => booking_destination_rules_for_property($property),
    ];

    if ($includePricing) {
        $result['pricing'] = calculate_booking_price($property, $startDate, $days);
    }

    return $result;
}

/**
 * Ketersediaan banyak properti sekaligus untuk satu rentang tanggal.
 *
 * Dipakai oleh katalog agar pencarian tanggal tidak melakukan query
 * ketersediaan satu per satu untuk setiap properti.
 *
 * @param array<int,array<string,mixed>> $properties
 * @return array<int,array{available:bool,remaining:int,blocked_dates:array<int,string>,manually_blocked:bool}>
 */
function get_properties_availability_batch(array $properties, string $startDate, int $days): array
{
    if (!valid_ymd($startDate)) {
        return [];
    }

    $days = max(1, min(365, $days));
    $propertyIds = [];
    $stocks = [];

    foreach ($properties as $property) {
        $id = (int) ($property['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $propertyIds[$id] = $id;
        $stocks[$id] = max(1, (int) ($property['stock'] ?? 1));
    }

    if ($propertyIds === []) {
        return [];
    }

    $endExclusive = booking_end_exclusive($startDate, $days);
    $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
    $baseParams = array_values($propertyIds);
    $rangeParams = array_merge($baseParams, [$endExclusive, $startDate]);

    $ordersByProperty = [];
    $orderStmt = db()->prepare(
        "SELECT property_id, start_date, `days`
         FROM orders
         WHERE property_id IN ($placeholders)
           AND start_date < ?
           AND DATE_ADD(start_date, INTERVAL `days` DAY) > ?
           AND (
                 status IN ('paid', 'confirmed', 'completed')
                 OR (
                     status = 'pending_payment'
                     AND (payment_expires_at IS NULL OR payment_expires_at > NOW())
                 )
               )
         ORDER BY start_date ASC"
    );
    $orderStmt->execute($rangeParams);

    foreach ($orderStmt->fetchAll() as $order) {
        $orderStart = ymd_to_date((string) $order['start_date']);
        $orderDays = max(1, (int) $order['days']);
        $ordersByProperty[(int) $order['property_id']][] = [
            'start' => $orderStart,
            'end'   => $orderStart->modify('+' . $orderDays . ' days'),
        ];
    }

    $blocksByProperty = [];
    $blockStmt = db()->prepare(
        "SELECT property_id, start_date, end_date
         FROM property_blocked_dates
         WHERE property_id IN ($placeholders)
           AND start_date < ?
           AND DATE_ADD(end_date, INTERVAL 1 DAY) > ?
         ORDER BY start_date ASC"
    );
    $blockStmt->execute($rangeParams);

    foreach ($blockStmt->fetchAll() as $block) {
        $blocksByProperty[(int) $block['property_id']][] = [
            'start' => ymd_to_date((string) $block['start_date']),
            'end'   => ymd_to_date((string) $block['end_date']),
        ];
    }

    $dateList = [];
    $cursor = ymd_to_date($startDate);
    for ($i = 0; $i < $days; $i++) {
        $dateList[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    $result = [];

    foreach ($propertyIds as $id) {
        $stock = $stocks[$id];
        $remaining = $stock;
        $blockedDates = [];
        $manuallyBlocked = false;

        foreach ($dateList as $date) {
            $dateCursor = ymd_to_date($date);
            $booked = 0;

            foreach ($ordersByProperty[$id] ?? [] as $orderRange) {
                if ($orderRange['start'] <= $dateCursor && $dateCursor < $orderRange['end']) {
                    $booked++;
                }
            }

            $isBlocked = false;
            foreach ($blocksByProperty[$id] ?? [] as $blockRange) {
                if ($blockRange['start'] <= $dateCursor && $dateCursor <= $blockRange['end']) {
                    $isBlocked = true;
                    break;
                }
            }

            $dayRemaining = $isBlocked ? 0 : max(0, $stock - $booked);
            if ($dayRemaining <= 0) {
                $blockedDates[] = $date;
                if ($isBlocked) {
                    $manuallyBlocked = true;
                }
            }

            $remaining = min($remaining, $dayRemaining);
        }

        $result[$id] = [
            'available'       => $remaining > 0,
            'remaining'       => $remaining,
            'blocked_dates'   => $blockedDates,
            'manually_blocked' => $manuallyBlocked,
        ];
    }

    return $result;
}

/**
 * Ketersediaan seluruh hari dalam satu bulan (YYYY-MM).
 *
 * @return array<string,mixed>|null
 */
function get_property_month_availability(int $propertyId, string $month): ?array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        return null;
    }

    $startDate = $month . '-01';
    $days = (int) ymd_to_date($startDate)->format('t');
    $result = get_property_availability($propertyId, $startDate, $days);

    if ($result !== null) {
        $result['month'] = $month;
    }

    return $result;
}
