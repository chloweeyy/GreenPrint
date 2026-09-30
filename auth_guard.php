<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Admin session required.']);
    exit;
}

require_once __DIR__ . '/db.php';
$adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
if ($adminId === false || ($lastActivity > 0 && time() - $lastActivity > 21600)) {
    $_SESSION = [];
    session_destroy();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Admin session expired. Sign in again.']);
    exit;
}
$activeAdmin = $pdo->prepare('SELECT 1 FROM admin_users WHERE id = :id AND is_active IS TRUE');
$activeAdmin->execute([':id' => $adminId]);
if (!$activeAdmin->fetchColumn()) {
    $_SESSION = [];
    session_destroy();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Admin account is inactive. Sign in again.']);
    exit;
}
$_SESSION['admin_last_activity'] = time();

function require_greenprint_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Security token expired. Refresh the page and try again.']);
        exit;
    }
}
