<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function json_response(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) json_response(['status' => 'error', 'message' => 'Request body must be valid JSON.'], 400);
    return $data;
}

function require_http_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        json_response(['status' => 'error', 'message' => 'Method not allowed.'], 405);
    }
}

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function require_admin(PDO $pdo, bool $checkCsrf = false): array
{
    start_admin_session();
    $adminId = $_SESSION['admin_id'] ?? null;
    $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || !$adminId || ($lastActivity && time() - $lastActivity > 21600)) {
        $_SESSION = [];
        json_response(['status' => 'error', 'message' => 'Admin session expired. Sign in again.'], 401);
    }
    $columns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'admin_users'")->fetchAll(PDO::FETCH_COLUMN);
    $idColumn = in_array('id', $columns, true) ? 'id' : (in_array('admin_id', $columns, true) ? 'admin_id' : null);
    if ($idColumn === null || !in_array('is_active', $columns, true)) {
        json_response(['status' => 'error', 'message' => 'Admin account columns are missing.'], 503);
    }
    $roleColumn = in_array('role', $columns, true) ? 'role' : "'admin' AS role";
    $usernameColumn = in_array('username', $columns, true) ? 'username' : "'' AS username";
    $stmt = $pdo->prepare('SELECT "' . $idColumn . '" AS id, ' . $usernameColumn . ', ' . $roleColumn . ', is_active FROM admin_users WHERE "' . $idColumn . '" = :id');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch();
    $active = $admin && in_array($admin['is_active'], [true, 1, '1', 't', 'true'], true);
    if (!$active) {
        $_SESSION = [];
        json_response(['status' => 'error', 'message' => 'Admin session is no longer active. Sign in again.'], 401);
    }
    $_SESSION['admin_last_activity'] = time();
    if ($checkCsrf) {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)) {
            json_response(['status' => 'error', 'message' => 'Request token expired. Reload the page and try again.'], 403);
        }
    }
    return $admin;
}

function audit_admin(PDO $pdo, array $admin, string $action, ?string $entity = null, ?string $entityId = null, array $details = []): void
{
    $auditDetails = array_filter([
        'entity' => $entity,
        'entity_id' => $entityId,
        'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        'details' => $details,
    ], static fn($value) => $value !== null && $value !== []);
    $stmt = $pdo->prepare('INSERT INTO admin_logs (admin_id, action, details) VALUES (:admin_id, :action, :details)');
    $stmt->execute([
        ':admin_id' => $admin['id'],
        ':action' => $action,
        ':details' => json_encode($auditDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function device_key_authorized(): bool
{
    $expected = (string)(greenprint_config('DEVICE_KEY', '') ?: '');
    $provided = $_SERVER['HTTP_X_GREENPRINT_DEVICE_KEY'] ?? '';
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}
