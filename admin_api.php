<?php
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$currentAdmin = require_admin($pdo, strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
if (!in_array($currentAdmin['role'] ?? '', ['owner', 'admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'This account cannot manage the store.']);
    exit;
}

$action = (string) ($_GET['action'] ?? '');
$respond = static function (array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($action) {
            case 'products':
                $data = $pdo->query('SELECT id, sku, name, category, price, stock, is_active, notes, image_url FROM products ORDER BY id')->fetchAll();
                break;
            case 'low_stock':
                $data = $pdo->query('SELECT id, name, stock, category FROM products WHERE stock <= 5 AND is_active = TRUE ORDER BY stock, name')->fetchAll();
                break;
            case 'inventory_logs':
                $data = $pdo->query('SELECT il.created_at, p.name AS product_name, il.change_amount, il.reason FROM inventory_logs il LEFT JOIN products p ON p.id = il.product_id ORDER BY il.created_at DESC LIMIT 100')->fetchAll();
                break;
            case 'schedules':
                $data = $pdo->query('SELECT zone_id, schedule_time FROM watering_schedules ORDER BY zone_id')->fetchAll();
                break;
            case 'transactions':
                $data = $pdo->query('SELECT * FROM transactions ORDER BY created_at DESC NULLS LAST LIMIT 500')->fetchAll();
                break;
            case 'alerts':
                $data = $pdo->query('SELECT id, alert_type, message, created_at FROM system_alerts WHERE is_resolved = FALSE ORDER BY created_at DESC LIMIT 100')->fetchAll();
                break;
            case 'sensor_history':
                $data = $pdo->query('SELECT temperature, humidity, water_level, created_at FROM sensor_readings ORDER BY created_at DESC LIMIT 24')->fetchAll();
                break;
            case 'latest_sensor':
                $data = $pdo->query('SELECT temperature, humidity, water_level, created_at FROM sensor_readings ORDER BY created_at DESC LIMIT 1')->fetchAll();
                break;
            case 'watering_history':
                $data = $pdo->query('SELECT zone_name, status, created_at FROM watering_logs ORDER BY created_at DESC LIMIT 8')->fetchAll();
                break;
            default:
                $respond(['status' => 'error', 'message' => 'Unknown admin data request.'], 404);
                exit;
        }
        $respond(['status' => 'success', 'data' => $data]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $respond(['status' => 'error', 'message' => 'Use GET or POST.'], 405);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $respond(['status' => 'error', 'message' => 'Invalid request body.'], 400);
        exit;
    }

    switch ($action) {
        case 'save_product':
            $rawId = $input['id'] ?? null;
            $id = $rawId === null || $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $name = trim((string) ($input['name'] ?? ''));
            $category = strtolower(trim((string) ($input['category'] ?? '')));
            $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
            $categories = ['indoor', 'outdoor', 'pots', 'pebbles', 'supplies'];
            if ($id === false || $name === '' || strlen($name) > 160 || !in_array($category, $categories, true) || $price === false || $price < 0 || $price > 9999999999.99 || $stock === false) {
                $respond(['status' => 'error', 'message' => 'Check the product name, category, price, and stock.'], 422);
                exit;
            }
            $pdo->beginTransaction();
            if ($id !== null) {
                $before = $pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
                $before->execute([':id' => $id]);
                $oldStock = $before->fetchColumn();
                if ($oldStock === false) throw new InvalidArgumentException('Product was not found.');
                $stmt = $pdo->prepare('UPDATE products SET name = :name, category = :category, price = :price, stock = :stock, updated_at = NOW() WHERE id = :id');
                $stmt->execute([':name' => $name, ':category' => $category, ':price' => $price, ':stock' => $stock, ':id' => $id]);
                if ((int) $oldStock !== $stock) {
                    $inventory = $pdo->prepare('INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES (:id, :change, :reason)');
                    $inventory->execute([':id' => $id, ':change' => $stock - (int) $oldStock, ':reason' => 'Admin stock adjustment']);
                }
                $targetId = $id;
                $detail = 'Updated product ' . $name;
            } else {
                $prefixMap = ['indoor' => 'IN', 'outdoor' => 'OUT', 'pots' => 'POT', 'pebbles' => 'PBL', 'supplies' => 'SUP'];
                $sku = 'KGG-' . $prefixMap[$category] . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $stmt = $pdo->prepare('INSERT INTO products (sku, name, category, price, stock, is_active) VALUES (:sku, :name, :category, :price, :stock, TRUE) RETURNING id');
                $stmt->execute([':sku' => $sku, ':name' => $name, ':category' => $category, ':price' => $price, ':stock' => $stock]);
                $targetId = $stmt->fetchColumn();
                if ($stock > 0) {
                    $inventory = $pdo->prepare('INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES (:id, :change, :reason)');
                    $inventory->execute([':id' => $targetId, ':change' => $stock, ':reason' => 'Initial stock']);
                }
                $detail = 'Added product ' . $name;
            }
            audit_admin($pdo, $currentAdmin, 'save_product', 'product', (string) $targetId, ['name' => $name, 'stock' => $stock]);
            $pdo->commit();
            $respond(['status' => 'success', 'id' => $targetId]);
            break;

        case 'delete_product':
        case 'toggle_product':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Product was not found.');
            $active = $action === 'toggle_product' ? filter_var($input['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN) : false;
            $stmt = $pdo->prepare('UPDATE products SET is_active = :active, updated_at = NOW() WHERE id = :id RETURNING name');
            $stmt->execute([':active' => $active ? 'true' : 'false', ':id' => $id]);
            $productName = $stmt->fetchColumn();
            if ($productName === false) throw new InvalidArgumentException('Product was not found.');
            audit_admin($pdo, $currentAdmin, $active ? 'activate_product' : 'deactivate_product', 'product', (string) $id, ['name' => $productName]);
            $respond(['status' => 'success']);
            break;

        case 'save_schedule':
            $zoneId = (string) ($input['zone_id'] ?? '');
            $time = trim((string) ($input['schedule_time'] ?? ''));
            if (!in_array($zoneId, ['zone1', 'zone2'], true) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Choose a valid watering zone and time.');
            }
            $stmt = $pdo->prepare('INSERT INTO watering_schedules (zone_id, schedule_time) VALUES (:zone_id, :schedule_time) ON CONFLICT (zone_id) DO UPDATE SET schedule_time = EXCLUDED.schedule_time');
            $stmt->execute([':zone_id' => $zoneId, ':schedule_time' => $time]);
            audit_admin($pdo, $currentAdmin, 'save_schedule', 'watering_schedule', $zoneId, ['time' => $time]);
            $respond(['status' => 'success']);
            break;

        case 'watering':
            $zone = (string) ($input['zone_name'] ?? '');
            $command = strtoupper((string) ($input['command'] ?? ''));
            $zoneIds = ['Zone 01 — Indoor' => 'zone1', 'Zone 02 — Outdoor' => 'zone2'];
            if (!isset($zoneIds[$zone]) || !in_array($command, ['ON', 'OFF'], true)) {
                throw new InvalidArgumentException('Choose a valid zone and watering command.');
            }
            $pdo->beginTransaction();
            $device = $pdo->prepare("INSERT INTO device_commands (zone_id, zone_name, command, status, issued_by) VALUES (:zone_id, :zone_name, :command, 'PENDING', :admin_id)");
            $device->execute([':zone_id' => $zoneIds[$zone], ':zone_name' => $zone, ':command' => $command, ':admin_id' => $_SESSION['admin_id']]);
            $log = $pdo->prepare('INSERT INTO watering_logs (zone_id, zone_name, status, admin_user_id) VALUES (:zone_id, :zone_name, :status, :admin_id)');
            $log->execute([':zone_id' => $zoneIds[$zone], ':zone_name' => $zone, ':status' => 'MANUAL_' . $command, ':admin_id' => $_SESSION['admin_id']]);
            audit_admin($pdo, $currentAdmin, 'watering_command', 'watering_zone', $zoneIds[$zone], ['zone_name' => $zone, 'command' => $command]);
            $pdo->commit();
            $respond(['status' => 'success']);
            break;

        case 'logout':
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
            }
            session_destroy();
            $respond(['status' => 'success']);
            break;

        default:
            $respond(['status' => 'error', 'message' => 'Unknown admin action.'], 404);
    }
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $respond(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('GreenPrint admin action failed: ' . $e->getMessage());
    $respond(['status' => 'error', 'message' => 'The admin action could not be completed.']);
}
