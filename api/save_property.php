<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

require_admin();

function upload_property_image(string $field, ?string $existing = null): ?string
{
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }

    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gagal mengunggah foto. Silakan coba lagi.');
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 2 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Foto harus berformat JPG, PNG, atau WebP.');
    }

    $directory = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'properties';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder upload tidak dapat dibuat.');
    }

    $filename = 'property_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        throw new RuntimeException('Foto gagal disimpan ke server.');
    }

    return 'uploads/properties/' . $filename;
}

$propertyId = (int) ($_POST['property_id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$location = trim((string) ($_POST['location'] ?? ''));
$category = trim((string) ($_POST['category'] ?? 'rumah'));
$badge = trim((string) ($_POST['badge'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$featuresRaw = trim((string) ($_POST['features'] ?? ''));

$price = (int) ($_POST['price'] ?? 0);
$weekendPriceRaw = trim((string) ($_POST['weekend_price'] ?? ''));
$weekendPrice = $weekendPriceRaw === '' ? null : (int) $weekendPriceRaw;
$minDaysRaw = trim((string) ($_POST['long_stay_min_days'] ?? ''));
$minDays = $minDaysRaw === '' ? null : (int) $minDaysRaw;
$discountPercent = (float) ($_POST['long_stay_discount_percent'] ?? 0);

$guests = (int) ($_POST['guests'] ?? 1);
$stock = (int) ($_POST['stock'] ?? 1);
$bedrooms = (int) ($_POST['bedrooms'] ?? 1);
$bathrooms = (int) ($_POST['bathrooms'] ?? 1);

if ($name === '' || $location === '') {
    json_response(422, ['error' => 'Nama dan lokasi properti wajib diisi.']);
}
if (mb_strlen($name) > 150 || mb_strlen($location) > 150 || mb_strlen($category) > 50) {
    json_response(422, ['error' => 'Nama, lokasi, atau kategori terlalu panjang.']);
}
if (mb_strlen($badge) > 60) {
    json_response(422, ['error' => 'Badge maksimal 60 karakter.']);
}
if ($price < 1) {
    json_response(422, ['error' => 'Harga per hari wajib diisi dengan benar.']);
}
if ($weekendPrice !== null && $weekendPrice < 1) {
    json_response(422, ['error' => 'Harga weekend tidak valid.']);
}
if ($minDays !== null && ($minDays < 1 || $minDays > 30)) {
    json_response(422, ['error' => 'Minimal hari diskon harus antara 1 sampai 30.']);
}
if ($discountPercent < 0 || $discountPercent > 100) {
    json_response(422, ['error' => 'Diskon harus antara 0 sampai 100%.']);
}
if ($minDays === null && $discountPercent > 0) {
    json_response(422, ['error' => 'Isi minimal hari jika ingin memberikan diskon durasi.']);
}
if ($minDays !== null && $discountPercent <= 0) {
    json_response(422, ['error' => 'Isi persentase diskon jika mengatur minimal hari.']);
}
if ($guests < 1 || $guests > 100 || $stock < 1 || $stock > 99 || $bedrooms < 1 || $bedrooms > 50 || $bathrooms < 1 || $bathrooms > 50) {
    json_response(422, ['error' => 'Kapasitas, stok, atau jumlah kamar tidak valid.']);
}

$features = array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    preg_split('/\r?\n|,/', $featuresRaw) ?: []
), static fn (string $item): bool => $item !== ''));

$pdo = db();

try {
    $pdo->beginTransaction();

    $existing = null;
    if ($propertyId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM properties WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$propertyId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            $pdo->rollBack();
            json_response(404, ['error' => 'Properti tidak ditemukan.']);
        }
    }

    $imageMain = upload_property_image('image_main', $existing['image_main'] ?? null);
    $image2 = upload_property_image('image_2', $existing['image_2'] ?? null);
    $image3 = upload_property_image('image_3', $existing['image_3'] ?? null);

    if ($imageMain === null || $imageMain === '') {
        throw new RuntimeException('Foto utama wajib diunggah untuk properti baru.');
    }

    $fields = [
        'name' => $name,
        'location' => $location,
        'category' => $category !== '' ? $category : 'rumah',
        'price' => $price,
        'weekend_price' => $weekendPrice,
        'long_stay_min_days' => $minDays,
        'long_stay_discount_percent' => $discountPercent,
        'guests' => $guests,
        'stock' => $stock,
        'bedrooms' => $bedrooms,
        'bathrooms' => $bathrooms,
        'badge' => $badge !== '' ? $badge : null,
        'image_main' => $imageMain,
        'image_2' => $image2,
        'image_3' => $image3,
        'description' => $description !== '' ? $description : null,
        'features' => json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];

    if ($propertyId > 0) {
        $set = implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($fields)));
        $stmt = $pdo->prepare('UPDATE properties SET ' . $set . ' WHERE id = ?');
        $stmt->execute([...array_values($fields), $propertyId]);
        $savedId = $propertyId;
        $status = 200;
        $message = 'Properti berhasil diperbarui.';
    } else {
        $columns = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $pdo->prepare('INSERT INTO properties (' . $columns . ') VALUES (' . $placeholders . ')');
        $stmt->execute(array_values($fields));
        $savedId = (int) $pdo->lastInsertId();
        $status = 201;
        $message = 'Properti berhasil ditambahkan.';
    }

    $pdo->commit();

    json_response($status, [
        'message' => $message,
        'property_id' => $savedId,
    ]);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(500, ['error' => 'Terjadi kesalahan server saat menyimpan properti.']);
}
