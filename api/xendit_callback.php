<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    xendit_log('Callback bukan JSON valid', ['raw' => mb_substr($raw, 0, 2000)]);
    json_response(400, ['error' => 'Invalid payload.']);
}

$token = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? null;
if (!xendit_callback_token_valid($token)) {
    xendit_log('Callback token tidak valid', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    json_response(401, ['error' => 'Invalid callback token.']);
}

$status = strtoupper((string) ($payload['status'] ?? ''));
$externalId = trim((string) ($payload['external_id'] ?? ''));
$invoiceId = trim((string) ($payload['id'] ?? ''));

xendit_log('Callback diterima', [
    'status' => $status,
    'external_id' => $externalId,
    'invoice_id' => $invoiceId,
]);

try {
    $payment = null;
    if ($externalId !== '') {
        $stmt = db()->prepare('SELECT * FROM payments WHERE external_id = ? LIMIT 1');
        $stmt->execute([$externalId]);
        $payment = $stmt->fetch() ?: null;
    }
    if (!$payment && $invoiceId !== '') {
        $stmt = db()->prepare('SELECT * FROM payments WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);
        $payment = $stmt->fetch() ?: null;
    }

    if (!$payment) {
        xendit_log('Callback untuk payment yang tidak dikenal', [
            'external_id' => $externalId,
            'invoice_id' => $invoiceId,
        ]);
        json_response(200, ['status' => 'ignored']);
    }

    if (in_array($status, ['PAID', 'SETTLED'], true)) {
        $payload['paid_amount'] = (int) ($payload['paid_amount'] ?? $payload['amount'] ?? $payment['amount']);
        mark_payment_paid($payment, $payload);
    } elseif ($status === 'EXPIRED') {
        mark_payment_expired($payment);
    } else {
        $update = db()->prepare('UPDATE payments SET raw_payload = ? WHERE id = ?');
        $update->execute([
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (int) $payment['id'],
        ]);
    }

    json_response(200, ['status' => 'ok']);
} catch (Throwable $e) {
    xendit_log('Callback gagal diproses', ['error' => $e->getMessage()]);
    json_response(500, ['error' => 'Callback processing failed.']);
}
