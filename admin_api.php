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
                $from = trim((string) ($_GET['from'] ?? ''));
                $to = trim((string) ($_GET['to'] ?? ''));
                $validDate = static function (string $value): bool {
                    if ($value === '') return true;
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
                    [$year, $month, $day] = array_map('intval', explode('-', $value));
                    return checkdate($month, $day, $year);
                };
                if (!$validDate($from) || !$validDate($to) || ($from !== '' && $to !== '' && $from > $to)) {
                    throw new InvalidArgumentException('Choose a valid date range; the from date must be on or before the to date.');
                }
                $columnQuery = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'transactions' AND column_name IN ('created_at', 'transaction_date', 'timestamp')");
                $columnQuery->execute();
                $availableColumns = $columnQuery->fetchAll(PDO::FETCH_COLUMN);
                $timeColumn = null;
                // Prefer the sale's recorded date; created_at can be later for imported/backfilled transactions.
                foreach (['transaction_date', 'created_at', 'timestamp'] as $candidate) {
                    if (in_array($candidate, $availableColumns, true)) { $timeColumn = $candidate; break; }
                }
                if (($from !== '' || $to !== '') && $timeColumn === null) {
                    throw new InvalidArgumentException('Date filtering is unavailable because the transactions table has no date column.');
                }
                $timeSql = $timeColumn === null ? 'NULL::timestamptz' : '"' . $timeColumn . '"';
                $sql = 'SELECT transaction_ref, total_amount, subtotal, ' . $timeSql . ' AS created_at FROM transactions';
                $conditions = [];
                $params = [];
                $timeZone = getenv('GREENPRINT_TIMEZONE') ?: 'Asia/Manila';
                try { new DateTimeZone($timeZone); } catch (Throwable $e) { $timeZone = 'UTC'; }
                if ($from !== '') {
                    $conditions[] = '"' . $timeColumn . '" >= (CAST(? AS date)::timestamp AT TIME ZONE CAST(? AS text))';
                    $params[] = $from;
                    $params[] = $timeZone;
                }
                if ($to !== '') {
                    $conditions[] = '"' . $timeColumn . '" < ((CAST(? AS date) + INTERVAL \'1 day\')::timestamp AT TIME ZONE CAST(? AS text))';
                    $params[] = $to;
                    $params[] = $timeZone;
                }
                if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
                $sql .= $timeColumn === null ? ' ORDER BY transaction_ref DESC' : ' ORDER BY "' . $timeColumn . '" DESC';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll();
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
            if ($id === false || $name === '' || strlen($name) > 160 || !in_array($category, $categories, true) || $price === false || $price < 0 || $price > 9999999999.99 || ($id === null && $stock === false)) {
                $respond(['status' => 'error', 'message' => 'Check the product name, category, price, and initial stock.'], 422);
                exit;
            }
            $pdo->beginTransaction();
            if ($id !== null) {
                $before = $pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
                $before->execute([':id' => $id]);
                $oldStock = $before->fetchColumn();
                if ($oldStock === false) throw new InvalidArgumentException('Product was not found.');
                $stmt = $pdo->prepare('UPDATE products SET name = :name, category = :category, price = :price, updated_at = NOW() WHERE id = :id');
                $stmt->execute([':name' => $name, ':category' => $category, ':price' => $price, ':id' => $id]);
                $stockForAudit = (int) $oldStock;
                $targetId = $id;
                $detail = 'Updated product ' . $name;
            } else {
                $prefixMap = ['indoor' => 'IN', 'outdoor' => 'OUT', 'pots' => 'POT', 'pebbles' => 'PBL', 'supplies' => 'SUP'];
                $sku = 'KGG-' . $prefixMap[$category] . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $stmt = $pdo->prepare('INSERT INTO products (sku, name, category, price, stock, is_active) VALUES (:sku, :name, :category, :price, :stock, TRUE) RETURNING id');
                $stmt->execute([':sku' => $sku, ':name' => $name, ':category' => $category, ':price' => $price, ':stock' => $stock]);
                $targetId = $stmt->fetchColumn();
                $stockForAudit = $stock;
                if ($stock > 0) {
                    $inventory = $pdo->prepare('INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES (:id, :change, :reason)');
                    $inventory->execute([':id' => $targetId, ':change' => $stock, ':reason' => 'Initial stock']);
                }
                $detail = 'Added product ' . $name;
            }
            audit_admin($pdo, $currentAdmin, 'save_product', 'product', (string) $targetId, ['name' => $name, 'stock' => $stockForAudit]);
            $pdo->commit();
            $respond(['status' => 'success', 'id' => $targetId]);
            break;

        case 'adjust_stock':
            $id = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            $direction = (string) ($input['direction'] ?? '');
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($id === false || $quantity === false || !in_array($direction, ['in', 'out'], true) || $reason === '' || strlen($reason) > 160) {
                throw new InvalidArgumentException('Choose a product, a positive whole-number quantity, and a reason.');
            }
            $pdo->beginTransaction();
            $productQuery = $pdo->prepare('SELECT id, name, stock FROM products WHERE id = :id FOR UPDATE');
            $productQuery->execute([':id' => $id]);
            $product = $productQuery->fetch();
            if (!$product) throw new InvalidArgumentException('Product was not found.');
            $change = $direction === 'in' ? $quantity : -$quantity;
            $newStock = (int) $product['stock'] + $change;
            if ($newStock < 0) throw new InvalidArgumentException('Cannot remove ' . $quantity . '; only ' . (int) $product['stock'] . ' are in stock.');
            if ($newStock > 2147483647) throw new InvalidArgumentException('The adjusted stock exceeds the database limit.');
            $update = $pdo->prepare('UPDATE products SET stock = :stock, updated_at = NOW() WHERE id = :id');
            $update->execute([':stock' => $newStock, ':id' => $id]);
            $inventory = $pdo->prepare('INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES (:id, :change, :reason)');
            $inventory->execute([':id' => $id, ':change' => $change, ':reason' => $reason]);
            audit_admin($pdo, $currentAdmin, $direction === 'in' ? 'stock_in' : 'stock_out', 'product', (string) $id, [
                'name' => $product['name'], 'quantity' => $quantity, 'change' => $change,
                'from_stock' => (int) $product['stock'], 'to_stock' => $newStock, 'reason' => $reason,
            ]);
            $pdo->commit();
            $respond(['status' => 'success', 'stock' => $newStock]);
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
