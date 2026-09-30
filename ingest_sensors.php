<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/db.php';
require_http_method('POST');
$data = request_json();
$expectedKey = (string)(greenprint_config('DEVICE_KEY', '') ?: '');
$providedKey = (string)($_SERVER['HTTP_X_GREENPRINT_DEVICE_KEY'] ?? $data['device_key'] ?? '');
if ($expectedKey === '' || $providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    json_response(['status' => 'error', 'message' => 'Unauthorized device.'], 401);
}

$temperature = filter_var($data['temperature'] ?? null, FILTER_VALIDATE_FLOAT);
$humidity = filter_var($data['humidity'] ?? null, FILTER_VALIDATE_FLOAT);
$moisture = filter_var($data['soil_moisture'] ?? null, FILTER_VALIDATE_FLOAT);
$waterLevel = filter_var($data['water_level'] ?? 0, FILTER_VALIDATE_FLOAT);
$zoneId = strtolower(trim((string)($data['zone_id'] ?? 'zone1')));
if ($temperature === false || $temperature < -30 || $temperature > 80 || $humidity === false || $humidity < 0 || $humidity > 100 || $moisture === false || $moisture < 0 || $moisture > 100 || $waterLevel === false || $waterLevel < 0 || $waterLevel > 100 || !in_array($zoneId, ['zone1', 'zone2'], true)) {
    json_response(['status' => 'error', 'message' => 'Sensor values or zone are outside the accepted range.'], 422);
}

$zoneName = $zoneId === 'zone1' ? 'Zone 01 — Indoor' : 'Zone 02 — Outdoor';
try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO sensor_readings (zone_id, temperature, humidity, soil_moisture, water_level, reading_time, created_at) VALUES (:zone, :temperature, :humidity, :moisture, :water_level, NOW(), NOW())');
    $insert->execute([':zone' => $zoneId, ':temperature' => $temperature, ':humidity' => $humidity, ':moisture' => $moisture, ':water_level' => $waterLevel]);
    $conditions = [];
    if ($temperature > 35) $conditions[] = ['TEMPERATURE', 'High temperature detected: ' . $temperature . '°C'];
    if ($moisture < 30) $conditions[] = ['MOISTURE', 'Soil moisture is low: ' . $moisture . '%'];
    if ($waterLevel < 20) $conditions[] = ['WATER_TANK', 'The main water reservoir is low: ' . $waterLevel . '%'];
    $alertCheck = $pdo->prepare("SELECT 1 FROM system_alerts WHERE alert_type = :type AND is_resolved IS FALSE AND created_at > NOW() - INTERVAL '10 minutes' LIMIT 1");
    $alertInsert = $pdo->prepare('INSERT INTO system_alerts (alert_type, message) VALUES (:type, :message)');
    foreach ($conditions as [$type, $message]) {
        $alertCheck->execute([':type' => $type]);
        if (!$alertCheck->fetchColumn()) $alertInsert->execute([':type' => $type, ':message' => $zoneName . ': ' . $message]);
    }
    $pdo->commit();
    json_response(['status' => 'success', 'message' => 'Sensor data received.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('GreenPrint sensor ingestion failed: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Sensor data could not be saved.'], 500);
}
