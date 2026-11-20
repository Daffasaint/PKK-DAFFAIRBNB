<?php
declare(strict_types=1);

/**
 * Perhitungan harga terpusat untuk booking, ketersediaan, pembayaran,
 * dan invoice. Harga akhir pekan berlaku Sabtu dan Minggu.
 */

function booking_weekend_days(): array
{
    return [6, 7]; // 6 = Sabtu, 7 = Minggu (ISO-8601)
}

function property_is_weekend(string $date): bool
{
    $day = (int) (new DateTimeImmutable($date . ' 00:00:00'))->format('N');
    return in_array($day, booking_weekend_days(), true);
}

function property_effective_daily_price(array $property, string $date): int
{
    $basePrice = max(0, (int) ($property['price'] ?? 0));
    $weekendPrice = isset($property['weekend_price']) && $property['weekend_price'] !== null
        ? (int) $property['weekend_price']
        : 0;

    if ($weekendPrice > 0 && property_is_weekend($date)) {
        return $weekendPrice;
    }

    return $basePrice;
}

/**
 * Hitung rincian harga untuk rentang tanggal tertentu.
 *
 * @return array<string,mixed>
 */
function calculate_booking_price(array $property, string $startDate, int $days): array
{
    $days = max(1, $days);
    $cursor = new DateTimeImmutable($startDate . ' 00:00:00');
    $daily = [];
    $baseTotal = 0;

    for ($i = 0; $i < $days; $i++) {
        $date = $cursor->format('Y-m-d');
        $price = property_effective_daily_price($property, $date);
        $isWeekend = property_is_weekend($date);

        $daily[] = [
            'date'       => $date,
            'price'      => $price,
            'is_weekend' => $isWeekend,
        ];

        $baseTotal += $price;
        $cursor = $cursor->modify('+1 day');
    }

    $minDays = isset($property['long_stay_min_days']) && $property['long_stay_min_days'] !== null
        ? max(0, (int) $property['long_stay_min_days'])
        : 0;
    $discountPercent = isset($property['long_stay_discount_percent'])
        ? (float) $property['long_stay_discount_percent']
        : 0.0;
    $discountPercent = max(0.0, min(100.0, $discountPercent));

    $discountTotal = 0;
    $longStayApplied = $minDays > 0 && $days >= $minDays && $discountPercent > 0;
    if ($longStayApplied) {
        $discountTotal = (int) round($baseTotal * ($discountPercent / 100));
    }

    $subtotal = max(0, $baseTotal - $discountTotal);
    $serviceFee = (int) round($subtotal * 0.05);

    return [
        'days'                      => $days,
        'base_total'                => $baseTotal,
        'discount_total'            => $discountTotal,
        'discount_percent'          => $discountPercent,
        'long_stay_min_days'        => $minDays,
        'long_stay_applied'         => $longStayApplied,
        'subtotal'                  => $subtotal,
        'service_fee'               => $serviceFee,
        'total'                     => $subtotal + $serviceFee,
        'daily'                     => $daily,
    ];
}

/**
 * Rincian harga dari snapshot order. Order lama yang belum punya
 * snapshot akan dihitung dari total (biaya layanan 5%).
 *
 * @return array<string,mixed>
 */
function order_price_breakdown(array $order): array
{
    $total = (int) ($order['total'] ?? 0);
    $baseTotal = isset($order['base_total']) && $order['base_total'] !== null
        ? (int) $order['base_total']
        : (int) round($total / 1.05);
    $discountTotal = (int) ($order['discount_total'] ?? 0);
    $serviceFee = isset($order['service_fee']) && $order['service_fee'] !== null
        ? (int) $order['service_fee']
        : $total - $baseTotal;

    $detail = null;
    if (!empty($order['pricing_detail'])) {
        $decoded = json_decode((string) $order['pricing_detail'], true);
        if (is_array($decoded)) {
            $detail = $decoded;
        }
    }

    return [
        'base_total'      => $baseTotal,
        'discount_total'  => $discountTotal,
        'service_fee'     => $serviceFee,
        'total'           => $total,
        'pricing_detail'  => $detail,
    ];
}
