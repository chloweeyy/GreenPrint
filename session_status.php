<?php
require __DIR__ . '/auth_guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
echo json_encode(['status' => 'success', 'csrf_token' => $_SESSION['csrf_token']]);
