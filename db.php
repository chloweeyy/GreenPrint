<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $host = (string) greenprint_config('DB_HOST', '');
    $port = (string) greenprint_config('DB_PORT', '5432');
    $database = (string) greenprint_config('DB_NAME', 'postgres');
    $user = (string) greenprint_config('DB_USER', '');
    $password = (string) greenprint_config('DB_PASSWORD', '');
    $sslmode = (string) greenprint_config('DB_SSLMODE', 'require');
    if (!in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true)) $sslmode = 'require';
    if ($host === '' || $user === '' || $password === '') {
        throw new RuntimeException('Database credentials are not configured.');
    }
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslmode}",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
} catch (Throwable $e) {
    error_log('GreenPrint database connection failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Database connection is unavailable. Check GreenPrint local configuration.']);
    exit;
}
