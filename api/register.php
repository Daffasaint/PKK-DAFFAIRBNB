<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$data = body();
$fullName = trim((string) ($data['full_name'] ?? ''));
$email    = trim((string) ($data['email'] ?? ''));
$phone    = trim((string) ($data['phone'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($fullName === '' || $email === '' || $password === '') {
    json_response(422, ['error' => 'Nama, email, dan password wajib diisi.']);
}

if (strlen($password) < 6) {
    json_response(422, ['error' => 'Password minimal 6 karakter.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(422, ['error' => 'Format email tidak valid.']);
}

try {
    $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        json_response(409, ['error' => 'Email sudah terdaftar.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        'INSERT INTO users (full_name, email, phone, password, role) VALUES (?, ?, ?, ?, "user")'
    );
    $stmt->execute([$fullName, $email, $phone ?: null, $hash]);

    $id = (int) db()->lastInsertId();
    $_SESSION['user'] = [
        'id'        => $id,
        'full_name' => $fullName,
        'email'     => $email,
        'phone'     => $phone ?: null,
        'role'      => 'user',
    ];

    json_response(201, ['message' => 'Akun berhasil dibuat.', 'user' => $_SESSION['user']]);
} catch (PDOException $e) {
    json_response(500, ['error' => 'Terjadi kesalahan server.']);
}