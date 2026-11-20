<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$user = require_login();
$isAdmin = ($user['role'] ?? '') === 'admin';

expire_pending_payments($isAdmin ? null : (int) $user['id']);

try {
    $sql = "SELECT o.id, o.start_date, o.days, o.total, o.base_total,
                   o.discount_total, o.service_fee, o.pricing_detail, o.status, o.created_at,
                   o.payment_expires_at, o.paid_at, o.admin_note,
                   o.property_id, p.name AS property_name, p.location AS property_location,
                   p.image_main AS property_image,
                   u.full_name AS user_name, u.email AS user_email,
                   pay.status AS payment_status, pay.payment_method, pay.payment_channel,
                   pay.invoice_url, pay.paid_at AS payment_paid_at
            FROM orders o
            JOIN properties p ON p.id = o.property_id
            JOIN users u      ON u.id = o.user_id
            LEFT JOIN payments pay ON pay.id = (
                SELECT p2.id FROM payments p2 WHERE p2.order_id = o.id ORDER BY p2.id DESC LIMIT 1
            )";
    $params = [];

    if (!$isAdmin) {
        $sql .= ' WHERE o.user_id = ?';
        $params[] = $user['id'];
    }

    $sql .= ' ORDER BY o.created_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $labels = order_status_labels();
    $paymentLabels = payment_status_labels();

    foreach ($rows as &$row) {
        $row['id']    = (int) $row['id'];
        $row['days']  = (int) $row['days'];
        $row['total'] = (int) $row['total'];
        $row['base_total'] = $row['base_total'] !== null ? (int) $row['base_total'] : (int) round($row['total'] / 1.05);
        $row['discount_total'] = (int) $row['discount_total'];
        $row['service_fee'] = $row['service_fee'] !== null ? (int) $row['service_fee'] : $row['total'] - $row['base_total'];
        $row['end_date'] = booking_end_date((string) $row['start_date'], (int) $row['days']);
        $row['checkout_date'] = booking_end_exclusive((string) $row['start_date'], (int) $row['days']);
        $row['start_date_label'] = format_date_id((string) $row['start_date'], false);
        $row['end_date_label'] = format_date_id((string) $row['end_date'], false);
        $row['checkout_date_label'] = format_date_id((string) $row['checkout_date'], false);
        $row['status_label'] = $labels[$row['status']] ?? $row['status'];
        $row['payment_status_label'] = $paymentLabels[$row['payment_status'] ?? ''] ?? null;
        $row['payment_method_label'] = $row['payment_status'] === 'PAID'
            ? payment_method_label($row['payment_method'] ?? null, $row['payment_channel'] ?? null)
            : null;
        $row['can_pay'] = can_pay_order($row);
        $row['payment_url'] = order_payment_url((int) $row['id']);
        $row['seconds_left'] = 0;
        if ($row['status'] === 'pending_payment' && !empty($row['payment_expires_at'])) {
            $row['seconds_left'] = max(0, strtotime((string) $row['payment_expires_at']) - time());
        }

        $pricingDetail = null;
        if (!empty($row['pricing_detail'])) {
            $decoded = json_decode((string) $row['pricing_detail'], true);
            if (is_array($decoded)) {
                $pricingDetail = $decoded;
            }
        }

        $row['guests'] = $pricingDetail['guests'] ?? null;
        $row['guest_summary'] = $pricingDetail['guest_summary'] ?? null;
        $row['destination_label'] = $pricingDetail['destination']['label'] ?? null;
        $row['checkin_time'] = $pricingDetail['destination']['checkin_time'] ?? null;
        $row['checkout_time'] = $pricingDetail['destination']['checkout_time'] ?? null;
        unset($row['pricing_detail']);
    }

    json_response(200, ['orders' => $rows]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}
