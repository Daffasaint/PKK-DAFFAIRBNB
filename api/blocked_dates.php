<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/availability.php';

require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $stmt = db()->query(
            "SELECT b.id, b.property_id, b.start_date, b.end_date, b.reason,
                    p.name AS property_name
             FROM property_blocked_dates b
             JOIN properties p ON p.id = b.property_id
             WHERE b.end_date >= CURDATE()
             ORDER BY b.start_date ASC, b.id ASC"
        );

        json_response(200, ['blocked_dates' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $data = body();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $startDate = trim((string) ($data['start_date'] ?? ''));
        $endDate = trim((string) ($data['end_date'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($propertyId <= 0) {
            json_response(422, ['error' => 'Pilih properti terlebih dahulu.']);
        }
        if (!valid_ymd($startDate) || !valid_ymd($endDate)) {
            json_response(422, ['error' => 'Tanggal blokir tidak valid.']);
        }
        if ($endDate < $startDate) {
            json_response(422, ['error' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
        }
        if ($startDate < app_today()) {
            json_response(422, ['error' => 'Tanggal blokir tidak boleh di masa lalu.']);
        }
        if (mb_strlen($reason) > 255) {
            json_response(422, ['error' => 'Alasan maksimal 255 karakter.']);
        }

        $start = ymd_to_date($startDate);
        $end = ymd_to_date($endDate);
        $days = (int) $start->diff($end)->days + 1;
        if ($days > 365) {
            json_response(422, ['error' => 'Rentang blokir maksimal 365 hari.']);
        }

        $endExclusive = booking_end_exclusive($startDate, $days);
        $pdo = db();

        try {
            $pdo->beginTransaction();

            // Kunci baris properti agar blokir tanggal tidak berlomba dengan
            // proses booking yang sedang mengecek ketersediaan.
            $propertyCheck = $pdo->prepare('SELECT id FROM properties WHERE id = ? LIMIT 1 FOR UPDATE');
            $propertyCheck->execute([$propertyId]);
            if (!$propertyCheck->fetch()) {
                $pdo->rollBack();
                json_response(404, ['error' => 'Properti tidak ditemukan.']);
            }

            $activeOrders = active_order_ranges_for_property($propertyId, $startDate, $endExclusive);
            if ($activeOrders !== []) {
                $pdo->rollBack();
                json_response(409, [
                    'error' => 'Rentang tanggal beririsan dengan pesanan aktif. Selesaikan atau batalkan pesanan tersebut terlebih dahulu.',
                ]);
            }

            $overlap = $pdo->prepare(
                'SELECT id FROM property_blocked_dates
                 WHERE property_id = ?
                   AND start_date < ?
                   AND DATE_ADD(end_date, INTERVAL 1 DAY) > ?
                 LIMIT 1'
            );
            $overlap->execute([$propertyId, $endExclusive, $startDate]);
            if ($overlap->fetch()) {
                $pdo->rollBack();
                json_response(409, ['error' => 'Rentang tanggal sudah diblokir sebelumnya.']);
            }

            $insert = $pdo->prepare(
                'INSERT INTO property_blocked_dates (property_id, start_date, end_date, reason)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$propertyId, $startDate, $endDate, $reason !== '' ? $reason : null]);
            $blockId = (int) $pdo->lastInsertId();

            $pdo->commit();

            json_response(201, [
                'message' => 'Tanggal berhasil diblokir.',
                'id' => $blockId,
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($method === 'DELETE') {
        $data = body();
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(422, ['error' => 'Blokir tanggal tidak valid.']);
        }

        $stmt = db()->prepare('DELETE FROM property_blocked_dates WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            json_response(404, ['error' => 'Blokir tanggal tidak ditemukan.']);
        }

        json_response(200, ['message' => 'Blokir tanggal dihapus.']);
    }

    json_response(405, ['error' => 'Method not allowed.']);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
