<?php
// get_dashboard_data.php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

try {
    $dashboard_data = [];

    // 1. Get the most recent sensor reading (Current Garden Status)
    $stmt = $pdo->query("SELECT temperature, humidity, water_level, reading_time FROM sensor_readings ORDER BY reading_time DESC LIMIT 1");
    $dashboard_data['latest_reading'] = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Get sensor history for the line charts (Last 24 readings)
    $stmt = $pdo->query("
        SELECT temperature, humidity, water_level, reading_time 
        FROM (
            SELECT temperature, humidity, water_level, reading_time 
            FROM sensor_readings 
            ORDER BY reading_time DESC 
            LIMIT 24
        ) sub 
        ORDER BY reading_time ASC
    ");
    $dashboard_data['sensor_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get recent watering history (FR-11)
    $stmt = $pdo->query("SELECT zone_name, status, created_at FROM watering_logs ORDER BY created_at DESC LIMIT 10");
    $dashboard_data['watering_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Get active unacknowledged alerts (from the Backend A watchdog)
    $stmt = $pdo->query("SELECT id, alert_type, message, created_at FROM system_alerts WHERE is_resolved = FALSE ORDER BY created_at DESC");
    $dashboard_data['active_alerts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Get low stock inventory items (stock <= 10)
    $stmt = $pdo->query("SELECT name, stock, category FROM products WHERE stock <= 10 AND is_active = TRUE ORDER BY stock ASC");
    $dashboard_data['low_stock_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Send the massive payload to Frontend
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "data" => $dashboard_data
    ]);

} catch (Throwable $e) {
    error_log('GreenPrint dashboard query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed to load dashboard data."]);
}
?>
