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
$active = !empty($data['active']);

if ($propertyId <= 0) {
    json_response(422, ['error' => 'Properti tidak valid.']);
}

try {
    $stmt = db()->prepare('UPDATE properties SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $propertyId]);

    $check = db()->prepare('SELECT id FROM properties WHERE id = ? LIMIT 1');
    $check->execute([$propertyId]);
    if (!$check->fetch()) {
        json_response(404, ['error' => 'Properti tidak ditemukan.']);
    }

    json_response(200, [
        'message' => $active ? 'Properti diaktifkan.' : 'Properti dinonaktifkan.',
        'property_id' => $propertyId,
        'active' => $active,
    ]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
