<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$data = body();
$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($email === '' || $password === '') {
    json_response(422, ['error' => 'Email dan password wajib diisi.']);
}

try {
    $stmt = db()->prepare('SELECT id, full_name, email, phone, password, role FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_response(401, ['error' => 'Email atau password salah.']);
    }

    unset($user['password']);
    $_SESSION['user'] = $user;

    json_response(200, ['message' => 'Login berhasil.', 'user' => $user]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}