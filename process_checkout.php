<?php
require __DIR__ . '/db.php';
require_once __DIR__ . '/api_common.php';
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

$data = json_decode(file_get_contents('php://input'), true);
$pin = is_array($data) ? (string) ($data['pin'] ?? '') : '';
$cart = is_array($data) ? ($data['cart'] ?? null) : null;
if (!preg_match('/^\d{4,8}$/', $pin) || !is_array($cart) || count($cart) < 1 || count($cart) > 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Enter a valid authorization PIN and cart.']);
    exit;
}

try {
    if (!empty($_SESSION['checkout_pin_locked_until']) && time() < (int) $_SESSION['checkout_pin_locked_until']) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many PIN attempts. Wait one minute and try again.']);
        exit;
    }
    $pinRows = $pdo->query('SELECT id, username, role, checkout_pin_hash FROM admin_users WHERE is_active IS TRUE AND checkout_pin_hash IS NOT NULL')->fetchAll();
    $authorizedAdmin = null;
    foreach ($pinRows as $admin) {
        if (password_verify($pin, (string) $admin['checkout_pin_hash'])) {
            $authorizedAdmin = $admin;
            break;
        }
    }
    if (!$authorizedAdmin) {
        $attempts = (int) ($_SESSION['checkout_pin_attempts'] ?? 0) + 1;
        $_SESSION['checkout_pin_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['checkout_pin_locked_until'] = time() + 60;
            $_SESSION['checkout_pin_attempts'] = 0;
        }
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Incorrect checkout PIN.']);
        exit;
    }

    $quantities = [];
    foreach ($cart as $line) {
        $id = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
        if ($id === false || $quantity === false) {
            throw new InvalidArgumentException('The cart contains an invalid product or quantity.');
        }
        $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
    }

    $pdo->beginTransaction();
    $productQuery = $pdo->prepare('SELECT id, name, sku, price, stock, is_active FROM products WHERE id = :id FOR UPDATE');
    $products = [];
    $subtotal = 0.0;
    foreach ($quantities as $id => $quantity) {
        $productQuery->execute([':id' => $id]);
        $product = $productQuery->fetch();
        $isActive = $product && in_array($product['is_active'], [true, 1, '1', 't', 'true'], true);
        if (!$product || !$isActive) {
            throw new InvalidArgumentException('A cart item is no longer available. Refresh the products and try again.');
        }
        if ((int) $product['stock'] < $quantity) {
            throw new InvalidArgumentException($product['name'] . ' does not have enough stock for this order.');
        }
        $product['quantity'] = $quantity;
        $product['unit_price'] = (float) $product['price'];
        $product['line_total'] = round($product['unit_price'] * $quantity, 2);
        $subtotal += $product['line_total'];
        $products[] = $product;
    }
    $subtotal = round($subtotal, 2);
    $transactionRef = 'KGG-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $sale = $pdo->prepare('INSERT INTO transactions (transaction_ref, total_amount, subtotal) VALUES (:ref, :total, :subtotal) RETURNING id, created_at');
    $sale->execute([':ref' => $transactionRef, ':total' => $subtotal, ':subtotal' => $subtotal]);
    $transaction = $sale->fetch();
    $transactionId = $transaction['id'];
    $itemInsert = $pdo->prepare('INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, unit_price, line_total) VALUES (:transaction_id, :product_id, :product_name, :quantity, :unit_price, :line_total)');
    $stockUpdate = $pdo->prepare('UPDATE products SET stock = stock - :quantity, updated_at = NOW() WHERE id = :id');
    $inventoryLog = $pdo->prepare('INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES (:product_id, :change_amount, :reason)');
    foreach ($products as $product) {
        $itemInsert->execute([
            ':transaction_id' => $transactionId,
            ':product_id' => $product['id'],
            ':product_name' => $product['name'],
            ':quantity' => $product['quantity'],
            ':unit_price' => $product['unit_price'],
            ':line_total' => $product['line_total'],
        ]);
        $stockUpdate->execute([':quantity' => $product['quantity'], ':id' => $product['id']]);
        $inventoryLog->execute([':product_id' => $product['id'], ':change_amount' => -$product['quantity'], ':reason' => 'Sale ' . $transactionRef]);
    }
    audit_admin($pdo, $authorizedAdmin, 'checkout', 'transactions', (string)$transactionId, ['transaction_ref' => $transactionRef, 'item_count' => count($products), 'total' => $subtotal]);
    $pdo->commit();
    $_SESSION['checkout_pin_attempts'] = 0;
    echo json_encode([
        'status' => 'success',
        'transaction_ref' => $transactionRef,
        'transaction_id' => $transactionId,
        'timestamp' => $transaction['created_at'],
        'subtotal' => $subtotal,
        'total_amount' => $subtotal,
        'items' => array_map(static function ($product) {
            return ['product_name' => $product['name'], 'quantity' => $product['quantity'], 'unit_price' => $product['unit_price'], 'line_total' => $product['line_total']];
        }, $products),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('GreenPrint checkout failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Checkout could not be completed. No stock was changed.']);
}
