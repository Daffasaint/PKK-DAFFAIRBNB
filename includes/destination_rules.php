<?php
declare(strict_types=1);

/**
 * Ketentuan menginap per destinasi.
 *
 * Nilai max_guests di sini adalah batas maksimum destinasi. Kapasitas akhir
 * tiap properti tetap mengikuti kolom properties.guests (mana yang lebih kecil
 * yang dipakai). Aturan ini dipakai oleh API properti, ketersediaan, dan
 * pembuatan pesanan agar UI dan validasi server selalu sama.
 */
function booking_destination_catalog(): array
{
    static $catalog = null;

    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'bali' => [
            'label'           => 'Bali',
            'max_guests'      => 12,
            'max_infants'     => 3,
            'min_nights'      => 2,
            'max_nights'      => 30,
            'pets_allowed'    => true,
            'max_pets'        => 2,
            'checkin_time'    => '15:00',
            'checkout_time'   => '12:00',
            'note'            => 'Check-in lebih awal dapat diminta dan tergantung ketersediaan unit.',
        ],
        'lembang' => [
            'label'           => 'Lembang',
            'max_guests'      => 10,
            'max_infants'     => 2,
            'min_nights'      => 1,
            'max_nights'      => 30,
            'pets_allowed'    => true,
            'max_pets'        => 2,
            'checkin_time'    => '14:00',
            'checkout_time'   => '11:00',
            'note'            => 'Hewan peliharaan diperbolehkan di area taman dengan pengawasan pemilik.',
        ],
        'dieng' => [
            'label'           => 'Dieng',
            'max_guests'      => 6,
            'max_infants'     => 1,
            'min_nights'      => 1,
            'max_nights'      => 14,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '14:00',
            'checkout_time'   => '11:00',
            'note'            => 'Suhu malam bisa sangat dingin. Bawa pakaian hangat dan konfirmasi jam check-in lebih awal.',
        ],
        'yogyakarta' => [
            'label'           => 'Yogyakarta',
            'max_guests'      => 8,
            'max_infants'     => 2,
            'min_nights'      => 1,
            'max_nights'      => 30,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '14:00',
            'checkout_time'   => '12:00',
            'note'            => 'Kawasan budaya; mohon menjaga ketenangan lingkungan sekitar pada malam hari.',
        ],
        'bromo' => [
            'label'           => 'Bromo',
            'max_guests'      => 6,
            'max_infants'     => 1,
            'min_nights'      => 2,
            'max_nights'      => 14,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '13:00',
            'checkout_time'   => '11:00',
            'note'            => 'Untuk sunrise trip, tamu disarankan check-in sebelum pukul 20.00.',
        ],
        'lombok' => [
            'label'           => 'Lombok',
            'max_guests'      => 8,
            'max_infants'     => 2,
            'min_nights'      => 2,
            'max_nights'      => 30,
            'pets_allowed'    => true,
            'max_pets'        => 1,
            'checkin_time'    => '14:00',
            'checkout_time'   => '12:00',
            'note'            => 'Antar jemput bandara tersedia dengan biaya tambahan.',
        ],
        'labuan-bajo' => [
            'label'           => 'Labuan Bajo',
            'max_guests'      => 6,
            'max_infants'     => 1,
            'min_nights'      => 2,
            'max_nights'      => 21,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '14:00',
            'checkout_time'   => '11:00',
            'note'            => 'Jadwal trip Komodo sebaiknya dikonfirmasi sebelum memesan tanggal.',
        ],
        'raja-ampat' => [
            'label'           => 'Raja Ampat',
            'max_guests'      => 6,
            'max_infants'     => 1,
            'min_nights'      => 3,
            'max_nights'      => 21,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '12:00',
            'checkout_time'   => '10:00',
            'note'            => 'Akses menggunakan kapal. Check-in disesuaikan dengan jadwal penyeberangan.',
        ],
        'karimunjawa' => [
            'label'           => 'Karimunjawa',
            'max_guests'      => 6,
            'max_infants'     => 2,
            'min_nights'      => 2,
            'max_nights'      => 21,
            'pets_allowed'    => true,
            'max_pets'        => 1,
            'checkin_time'    => '14:00',
            'checkout_time'   => '11:00',
            'note'            => 'Jadwal kapal menuju Karimunjawa terbatas; pesan tiket lebih awal.',
        ],
        'danau-toba' => [
            'label'           => 'Danau Toba',
            'max_guests'      => 8,
            'max_infants'     => 2,
            'min_nights'      => 2,
            'max_nights'      => 21,
            'pets_allowed'    => true,
            'max_pets'        => 1,
            'checkin_time'    => '14:00',
            'checkout_time'   => '11:00',
            'note'            => 'Akses tepi danau bisa licin saat hujan. Konfirmasi jadwal perahu sebelum tiba.',
        ],
        'belitung' => [
            'label'           => 'Belitung',
            'max_guests'      => 6,
            'max_infants'     => 2,
            'min_nights'      => 2,
            'max_nights'      => 21,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '14:00',
            'checkout_time'   => '12:00',
            'note'            => 'Musim ombak tinggi biasanya berlangsung Desember–Februari. Ikuti arahan petugas pantai.',
        ],
        'sumba' => [
            'label'           => 'Sumba',
            'max_guests'      => 8,
            'max_infants'     => 2,
            'min_nights'      => 3,
            'max_nights'      => 30,
            'pets_allowed'    => true,
            'max_pets'        => 2,
            'checkin_time'    => '15:00',
            'checkout_time'   => '11:00',
            'note'            => 'Perjalanan menuju villa melewati jalan pedesaan. Sewa mobil dengan pengemudi disarankan.',
        ],
        'wakatobi' => [
            'label'           => 'Wakatobi',
            'max_guests'      => 6,
            'max_infants'     => 1,
            'min_nights'      => 3,
            'max_nights'      => 21,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '12:00',
            'checkout_time'   => '10:00',
            'note'            => 'Akses ke Wakatobi menggunakan kapal. Check-in disesuaikan dengan jadwal penyeberangan.',
        ],
        'indonesia' => [
            'label'           => 'Indonesia',
            'max_guests'      => 8,
            'max_infants'     => 2,
            'min_nights'      => 1,
            'max_nights'      => 30,
            'pets_allowed'    => false,
            'max_pets'        => 0,
            'checkin_time'    => '14:00',
            'checkout_time'   => '12:00',
            'note'            => 'Hubungi tuan rumah jika membutuhkan kebutuhan khusus selama menginap.',
        ],
    ];

    return $catalog;
}

/**
 * Tentukan destinasi dari lokasi/nama properti.
 */
function booking_destination_key(array $property): string
{
    $haystack = strtolower(
        (string) ($property['location'] ?? '') . ' ' .
        (string) ($property['name'] ?? '') . ' ' .
        (string) ($property['category'] ?? '')
    );

    // Urutan penting: destinasi yang lebih spesifik diperiksa lebih dulu.
    $keywords = [
        'raja ampat'   => 'raja-ampat',
        'labuan bajo'  => 'labuan-bajo',
        'karimunjawa'  => 'karimunjawa',
        'danau toba'   => 'danau-toba',
        'toba'         => 'danau-toba',
        'belitung'     => 'belitung',
        'sumba'        => 'sumba',
        'wakatobi'     => 'wakatobi',
        'dieng'        => 'dieng',
        'jawa tengah'  => 'karimunjawa',
        'yogyakarta'   => 'yogyakarta',
        'jogja'        => 'yogyakarta',
        'bromo'        => 'bromo',
        'lombok'       => 'lombok',
        'lembang'      => 'lembang',
        'bali'         => 'bali',
    ];

    foreach ($keywords as $keyword => $key) {
        if (str_contains($haystack, $keyword)) {
            return $key;
        }
    }

    return 'indonesia';
}

/**
 * Ambil ketentuan destinasi untuk properti tertentu.
 *
 * @param array<string,mixed> $property
 * @return array<string,mixed>
 */
function booking_destination_rules_for_property(array $property): array
{
    $catalog = booking_destination_catalog();
    $key = booking_destination_key($property);
    $rules = $catalog[$key] ?? $catalog['indonesia'];

    $propertyCapacity = max(1, (int) ($property['guests'] ?? 1));
    $maxGuests = max(1, min((int) $rules['max_guests'], $propertyCapacity));

    $rules['key'] = $key;
    $rules['max_guests'] = $maxGuests;
    $rules['max_infants'] = max(0, (int) $rules['max_infants']);
    $rules['max_pets'] = max(0, (int) $rules['max_pets']);
    $rules['pets_allowed'] = (bool) $rules['pets_allowed'] && $rules['max_pets'] > 0;

    return $rules;
}

/**
 * Ringkasan tamu untuk disimpan pada snapshot pesanan.
 *
 * @param array{adults:int,children:int,infants:int,pets:int} $guests
 */
function booking_guest_summary(array $guests): string
{
    $parts = [(int) $guests['adults'] . ' dewasa'];

    if ((int) $guests['children'] > 0) {
        $parts[] = (int) $guests['children'] . ' anak';
    }
    if ((int) $guests['infants'] > 0) {
        $parts[] = (int) $guests['infants'] . ' bayi';
    }
    if ((int) $guests['pets'] > 0) {
        $parts[] = (int) $guests['pets'] . ' hewan';
    }

    return implode(' · ', $parts);
}
