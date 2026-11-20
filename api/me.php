<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['error' => 'Method not allowed.']);
}

$user = current_user();
if ($user === null) {
    json_response(401, ['error' => 'Belum login.']);
}

json_response(200, ['user' => $user]);