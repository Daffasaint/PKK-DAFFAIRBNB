<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_session();
$user = require_login();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Akses khusus owner. <a href="index.php">Kembali</a>');
}

// Menu PESANANKU sudah digabung ke Dashboard Owner.
header('Location: admin.php#orders');
exit;
