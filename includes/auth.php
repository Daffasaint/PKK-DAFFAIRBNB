<?php
declare(strict_types=1);

require_session();

function json_response(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function require_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function current_user(): ?array
{
    require_session();
    return $_SESSION['user'] ?? null;
}

// Wajib terautentikasi. Hentikan dengan 401 jika belum login.
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(401, ['error' => 'Silakan masuk terlebih dahulu.']);
    }
    return $user;
}

// Wajib role admin/owner. Hentikan dengan 403 jika bukan owner.
function require_admin(): array
{
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        json_response(403, ['error' => 'Akses khusus owner.']);
    }
    return $user;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}