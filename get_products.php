<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $includeOutOfStock = isset($_GET['all']) && $_GET['all'] === '1';
    $sql = 'SELECT id, sku, name, category, price, stock, is_active, image_url FROM products WHERE is_active = TRUE';
    if (!$includeOutOfStock) {
        $sql .= ' AND stock > 0';
    }
    $sql .= ' ORDER BY category, name';
    $products = $pdo->query($sql)->fetchAll();
    foreach ($products as &$product) {
        $product['id'] = (string) $product['id'];
        $product['price'] = (float) $product['price'];
        $product['stock'] = (int) $product['stock'];
        $product['is_active'] = in_array($product['is_active'], [true, 1, '1', 't', 'true'], true);
    }
    unset($product);
    echo json_encode(['status' => 'success', 'data' => $products], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('GreenPrint product query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not load products.']);
}
