<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Use POST.']);
    exit;
}
if (!empty($_SESSION['login_locked_until']) && time() < (int) $_SESSION['login_locked_until']) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'message' => 'Too many attempts. Wait one minute and try again.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$passcode = is_array($data) ? (string) ($data['passcode'] ?? '') : '';
if ($passcode === '' || strlen($passcode) > 128) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Enter your admin passcode.']);
    exit;
}

try {
    $users = $pdo->query('SELECT id, passcode_hash FROM admin_users WHERE is_active IS TRUE AND passcode_hash IS NOT NULL')->fetchAll();
    $matched = null;
    foreach ($users as $user) {
        if (password_verify($passcode, (string) $user['passcode_hash'])) {
            $matched = $user;
            break;
        }
    }
    if (!$matched) {
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_locked_until'] = time() + 60;
            $_SESSION['login_attempts'] = 0;
        }
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Incorrect passcode.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = (string) $matched['id'];
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['login_attempts'] = 0;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    try {
        $log = $pdo->prepare('INSERT INTO admin_logs (admin_id, action, details) VALUES (:admin_id, :action, :details)');
        $log->execute([':admin_id' => $matched['id'], ':action' => 'login', ':details' => 'Admin session opened']);
    } catch (Throwable $ignored) {
        error_log('GreenPrint could not write admin login audit record.');
    }
    echo json_encode(['status' => 'success', 'csrf_token' => $_SESSION['csrf_token']]);
} catch (Throwable $e) {
    error_log('GreenPrint admin login failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Could not verify the admin passcode.']);
}
