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
$action = is_array($data) ? (string) ($data['action'] ?? '') : '';
$pin = is_array($data) ? (string) ($data['pin'] ?? '') : '';
$cart = is_array($data) ? ($data['cart'] ?? null) : null;
if (!is_array($cart) || count($cart) < 1 || count($cart) > 100) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'The cart is empty or invalid.']);
    exit;
}

$quantities = [];
foreach ($cart as $line) {
    $id = filter_var($line['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
    if ($id === false || $quantity === false) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'The cart contains an invalid product or quantity.']);
        exit;
    }
    $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
    if ($quantities[$id] > 999) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'The cart contains an invalid quantity.']);
        exit;
    }
}
ksort($quantities, SORT_NUMERIC);
$cartFingerprint = hash('sha256', json_encode($quantities));

if ($action === 'authorize') {
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Enter a valid authorization PIN.']);
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
            if (password_verify($pin, (string) $admin['checkout_pin_hash'])) { $authorizedAdmin = $admin; break; }
        }
        if (!$authorizedAdmin) {
            $attempts = (int) ($_SESSION['checkout_pin_attempts'] ?? 0) + 1;
            $_SESSION['checkout_pin_attempts'] = $attempts;
            if ($attempts >= 5) { $_SESSION['checkout_pin_locked_until'] = time() + 60; $_SESSION['checkout_pin_attempts'] = 0; }
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Incorrect checkout PIN.']);
            exit;
        }
        $_SESSION['checkout_authorization_token'] = bin2hex(random_bytes(24));
        $_SESSION['checkout_authorization_expires'] = time() + 300;
        $_SESSION['checkout_authorization_cart'] = $cartFingerprint;
        $_SESSION['checkout_authorized_admin'] = $authorizedAdmin;
        $_SESSION['checkout_pin_attempts'] = 0;
        echo json_encode(['status' => 'success', 'authorization_token' => $_SESSION['checkout_authorization_token']]);
        exit;
    } catch (Throwable $e) {
        error_log('GreenPrint checkout authorization failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Staff authorization could not be checked. Please try again.']);
        exit;
    }
}

$authorizationToken = is_array($data) ? (string) ($data['authorization_token'] ?? '') : '';
if (empty($_SESSION['checkout_authorization_token'])
    || !hash_equals((string) $_SESSION['checkout_authorization_token'], $authorizationToken)
    || (int) ($_SESSION['checkout_authorization_expires'] ?? 0) < time()
    || !hash_equals((string) ($_SESSION['checkout_authorization_cart'] ?? ''), $cartFingerprint)) {
    unset($_SESSION['checkout_authorization_token'], $_SESSION['checkout_authorization_expires'], $_SESSION['checkout_authorization_cart'], $_SESSION['checkout_authorized_admin']);
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Staff authorization expired. Please enter the PIN again.']);
    exit;
}
$authorizedAdmin = $_SESSION['checkout_authorized_admin'] ?? null;
unset($_SESSION['checkout_authorization_token'], $_SESSION['checkout_authorization_expires'], $_SESSION['checkout_authorization_cart'], $_SESSION['checkout_authorized_admin']);

try {
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
    $paymentInput = is_array($data['payment'] ?? null) ? $data['payment'] : [];
    $paymentMethod = (string) ($paymentInput['method'] ?? 'cash');
    if (!in_array($paymentMethod, ['cash', 'gcash_qr', 'bank_transfer'], true)) {
        throw new InvalidArgumentException('Choose a valid payment method.');
    }
    $amountReceived = is_numeric($paymentInput['amount_received'] ?? null) ? round((float) $paymentInput['amount_received'], 2) : -1;
    if ($paymentMethod === 'cash' && $amountReceived < $subtotal) {
        throw new InvalidArgumentException('The amount received must cover the total.');
    }
    if ($paymentMethod !== 'cash') $amountReceived = $subtotal;
    $changeAmount = max(0, round($amountReceived - $subtotal, 2));
    $customerName = trim(substr((string) ($paymentInput['customer_name'] ?? ''), 0, 120));
    $transactionRef = 'KGG-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $transactionColumns = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'transactions'")->fetchAll(PDO::FETCH_COLUMN);
    $available = array_fill_keys($transactionColumns, true);
    $values = ['transaction_ref' => $transactionRef, 'total_amount' => $subtotal, 'subtotal' => $subtotal];
    if (isset($available['payment_method'])) $values['payment_method'] = $paymentMethod;
    if (isset($available['customer_name'])) $values['customer_name'] = $customerName !== '' ? $customerName : null;
    if (isset($available['amount_received'])) $values['amount_received'] = $amountReceived;
    if (isset($available['change_amount'])) $values['change_amount'] = $changeAmount;
    if (isset($available['discount_amount'])) $values['discount_amount'] = 0;
    if (isset($available['transaction_date'])) $values['transaction_date'] = date('c');
    $columns = array_keys($values);
    $quotedColumns = implode(', ', array_map(static fn($column) => '"' . str_replace('"', '""', $column) . '"', $columns));
    $placeholders = implode(', ', array_map(static fn($index) => ':v' . $index, array_keys($columns)));
    $bindings = [];
    foreach ($columns as $index => $column) $bindings[':v' . $index] = $values[$column];
    $idColumn = isset($available['id']) ? 'id' : 'transaction_id';
    $timeColumn = isset($available['created_at']) ? 'created_at' : (isset($available['transaction_date']) ? 'transaction_date' : $idColumn);
    $sale = $pdo->prepare('INSERT INTO transactions (' . $quotedColumns . ') VALUES (' . $placeholders . ') RETURNING "' . $idColumn . '", "' . $timeColumn . '"');
    $sale->execute($bindings);
    $transaction = $sale->fetch();
    $transactionId = $transaction[$idColumn];
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
    audit_admin($pdo, $authorizedAdmin, 'checkout', 'transactions', (string)$transactionId, ['transaction_ref' => $transactionRef, 'item_count' => count($products), 'total' => $subtotal, 'payment_method' => $paymentMethod, 'customer_name' => $customerName, 'amount_received' => $amountReceived, 'change_amount' => $changeAmount]);
    $pdo->commit();
    $_SESSION['checkout_pin_attempts'] = 0;
    echo json_encode([
        'status' => 'success',
        'transaction_ref' => $transactionRef,
        'transaction_id' => $transactionId,
        'timestamp' => $transaction[$timeColumn],
        'subtotal' => $subtotal,
        'total_amount' => $subtotal,
        'payment_method' => $paymentMethod,
        'customer_name' => $customerName,
        'amount_received' => $amountReceived,
        'change_amount' => $changeAmount,
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
