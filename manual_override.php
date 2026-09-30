<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/db.php';
require_http_method('POST');
require_greenprint_csrf();
$data = request_json();
$zone = trim((string)($data['zone_name'] ?? ''));
$command = strtoupper(trim((string)($data['command'] ?? '')));
$zones = ['Zone 01 — Indoor' => 'zone1', 'Zone 02 — Outdoor' => 'zone2'];
if (!isset($zones[$zone]) || !in_array($command, ['ON', 'OFF'], true)) json_response(['status' => 'error', 'message' => 'Choose a valid zone and ON/OFF command.'], 400);
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO device_commands (zone_id, zone_name, command, status, issued_by) VALUES (:zone_id, :zone_name, :command, 'PENDING', :admin_id)");
    $stmt->execute([':zone_id' => $zones[$zone], ':zone_name' => $zone, ':command' => $command, ':admin_id' => $_SESSION['admin_id']]);
    $pdo->prepare('INSERT INTO watering_logs (zone_id, zone_name, status, admin_user_id) VALUES (:zone_id, :zone_name, :status, :admin_id)')->execute([':zone_id' => $zones[$zone], ':zone_name' => $zone, ':status' => 'MANUAL_' . $command, ':admin_id' => $_SESSION['admin_id']]);
    $pdo->commit();
    json_response(['status' => 'success', 'message' => 'Watering command queued.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('GreenPrint manual override failed: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Watering command could not be queued.'], 500);
}
