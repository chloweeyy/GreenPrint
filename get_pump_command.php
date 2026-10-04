<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/db.php';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_response(['status' => 'error', 'message' => 'Method not allowed.'], 405);
if (!device_key_authorized()) json_response(['status' => 'error', 'message' => 'Unauthorized device.'], 401);
$zoneId = strtolower(trim((string)($_GET['zone_id'] ?? 'zone1')));
if (!in_array($zoneId, ['zone1', 'zone2'], true)) json_response(['status' => 'error', 'message' => 'Unknown zone.'], 400);
try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE device_commands SET status = 'SUPERSEDED' WHERE zone_id = :zone_id AND status = 'PENDING' AND id < (SELECT MAX(id) FROM device_commands WHERE zone_id = :latest_zone_id AND status = 'PENDING')")->execute([':zone_id' => $zoneId, ':latest_zone_id' => $zoneId]);
    $stmt = $pdo->prepare("SELECT id, command FROM device_commands WHERE zone_id = :zone_id AND status = 'PENDING' ORDER BY id DESC LIMIT 1 FOR UPDATE SKIP LOCKED");
    $stmt->execute([':zone_id' => $zoneId]);
    $command = $stmt->fetch();
    if ($command) {
        $pdo->prepare("UPDATE device_commands SET status = 'SENT', processed_at = NOW() WHERE id = :id")->execute([':id' => $command['id']]);
        $result = $command['command'];
    } else {
        $stmt = $pdo->prepare("SELECT command FROM device_commands WHERE zone_id = :zone_id AND status = 'SENT' ORDER BY id DESC LIMIT 1");
        $stmt->execute([':zone_id' => $zoneId]);
        $result = $stmt->fetchColumn() ?: 'OFF';
    }
    $pdo->commit();
    json_response(['status' => 'success', 'zone_id' => $zoneId, 'command' => $result]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('GreenPrint device command read failed: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Command queue is unavailable.'], 500);
}
