<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

require_admin();

$data = body();
$propertyId = (int) ($data['property_id'] ?? 0);
$stock = (int) ($data['stock'] ?? 0);

if ($propertyId <= 0) {
    json_response(422, ['error' => 'Properti tidak valid.']);
}

if ($stock < 1 || $stock > 99) {
    json_response(422, ['error' => 'Stok harus antara 1 sampai 99 unit.']);
}

try {
    $stmt = db()->prepare('UPDATE properties SET stock = ? WHERE id = ?');
    $stmt->execute([$stock, $propertyId]);

    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT id FROM properties WHERE id = ? LIMIT 1');
        $check->execute([$propertyId]);
        if (!$check->fetch()) {
            json_response(404, ['error' => 'Properti tidak ditemukan.']);
        }
    }

    json_response(200, [
        'message'     => 'Stok properti diperbarui.',
        'property_id' => $propertyId,
        'stock'       => $stock,
    ]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
