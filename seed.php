<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db.php';

// The full 93-item KGG Garden catalog
$json_data = '[{"id": "inv-001", "name": "Five Fingers", "sku": "KGG-IN-001", "price": 100.0, "stock": 5, "category": "indoor", "isActive": true}, {"id": "inv-002", "name": "Red Siam (Aglaonema)", "sku": "KGG-IN-002", "price": 100.0, "stock": 60, "category": "indoor", "isActive": true}, {"id": "inv-003", "name": "Snow White (Aglaonema)", "sku": "KGG-IN-003", "price": 150.0, "stock": 30, "category": "indoor", "isActive": true}, {"id": "inv-004", "name": "Calathea Zebra", "sku": "KGG-IN-004", "price": 150.0, "stock": 27, "category": "indoor", "isActive": true}, {"id": "inv-005", "name": "African Talisay (Small)", "sku": "KGG-IN-005", "price": 500.0, "stock": 45, "category": "indoor", "isActive": true}, {"id": "inv-006", "name": "African Talisay (Large)", "sku": "KGG-IN-006", "price": 2500.0, "stock": 38, "category": "indoor", "isActive": true}, {"id": "inv-007", "name": "Pinugo", "sku": "KGG-IN-007", "price": 150.0, "stock": 50, "category": "indoor", "isActive": true}, {"id": "inv-008", "name": "Doña Carmen", "sku": "KGG-IN-008", "price": 150.0, "stock": 38, "category": "indoor", "isActive": true}, {"id": "inv-009", "name": "Selloum (Medium)", "sku": "KGG-IN-009", "price": 200.0, "stock": 5, "category": "indoor", "isActive": true}, {"id": "inv-010", "name": "Miagos", "sku": "KGG-IN-010", "price": 100.0, "stock": 3, "category": "indoor", "isActive": true}, {"id": "inv-011", "name": "Bucida (Large)", "sku": "KGG-IN-011", "price": 1000.0, "stock": 45, "category": "indoor", "isActive": true}, {"id": "inv-012", "name": "Horse Tail", "sku": "KGG-IN-012", "price": 250.0, "stock": 50, "category": "indoor", "isActive": true}, {"id": "inv-013", "name": "Peace Lily", "sku": "KGG-IN-013", "price": 100.0, "stock": 8, "category": "indoor", "isActive": true}, {"id": "inv-014", "name": "Ti Plant", "sku": "KGG-IN-014", "price": 50.0, "stock": 45, "category": "indoor", "isActive": true}, {"id": "inv-015", "name": "Yellow Bell", "sku": "KGG-IN-015", "price": 250.0, "stock": 38, "category": "indoor", "isActive": true}, {"id": "inv-016", "name": "Sun Rise", "sku": "KGG-IN-016", "price": 50.0, "stock": 30, "category": "indoor", "isActive": true}, {"id": "inv-017", "name": "Lourdes", "sku": "KGG-IN-017", "price": 50.0, "stock": 30, "category": "indoor", "isActive": true}, {"id": "inv-018", "name": "Ruellia", "sku": "KGG-IN-018", "price": 50.0, "stock": 38, "category": "indoor", "isActive": true}, {"id": "inv-019", "name": "Bakya", "sku": "KGG-IN-019", "price": 100.0, "stock": 8, "category": "indoor", "isActive": true}, {"id": "inv-020", "name": "Silver King (Aglaonema)", "sku": "KGG-IN-020", "price": 100.0, "stock": 38, "category": "indoor", "isActive": true}, {"id": "inv-021", "name": "Marianne (Dieffenbachia)", "sku": "KGG-IN-021", "price": 100.0, "stock": 50, "category": "indoor", "isActive": true}, {"id": "inv-022", "name": "Bird of Paradise", "sku": "KGG-IN-022", "price": 50.0, "stock": 30, "category": "indoor", "isActive": true}, {"id": "inv-023", "name": "Yellow Irish", "sku": "KGG-IN-023", "price": 50.0, "stock": 45, "category": "indoor", "isActive": true}, {"id": "inv-024", "name": "Orchids", "sku": "KGG-IN-024", "price": 250.0, "stock": 3, "category": "indoor", "isActive": true}, {"id": "inv-025", "name": "Anthurium", "sku": "KGG-IN-025", "price": 350.0, "stock": 38, "category": "indoor", "isActive": true}, {"id": "inv-026", "name": "Bromeliad", "sku": "KGG-IN-026", "price": 250.0, "stock": 8, "category": "indoor", "isActive": true}, {"id": "inv-027", "name": "Picarra", "sku": "KGG-OUT-001", "price": 50.0, "stock": 60, "category": "outdoor", "isActive": true}, {"id": "inv-028", "name": "Eugenia (Small)", "sku": "KGG-OUT-002", "price": 50.0, "stock": 30, "category": "outdoor", "isActive": true}, {"id": "inv-029", "name": "Eugenia (Medium)", "sku": "KGG-OUT-003", "price": 150.0, "stock": 40, "category": "outdoor", "isActive": true}, {"id": "inv-030", "name": "Eugenia (Large)", "sku": "KGG-OUT-004", "price": 350.0, "stock": 30, "category": "outdoor", "isActive": true}, {"id": "inv-031", "name": "Forget-Me-Not", "sku": "KGG-OUT-005", "price": 50.0, "stock": 27, "category": "outdoor", "isActive": true}, {"id": "inv-032", "name": "Santan Plain", "sku": "KGG-OUT-006", "price": 50.0, "stock": 22, "category": "outdoor", "isActive": true}, {"id": "inv-033", "name": "Santan Rose", "sku": "KGG-OUT-007", "price": 50.0, "stock": 15, "category": "outdoor", "isActive": true}, {"id": "inv-034", "name": "Poinsettia (Small)", "sku": "KGG-OUT-008", "price": 250.0, "stock": 8, "category": "outdoor", "isActive": true}, {"id": "inv-035", "name": "Poinsettia (Medium)", "sku": "KGG-OUT-009", "price": 350.0, "stock": 15, "category": "outdoor", "isActive": true}, {"id": "inv-036", "name": "Poinsettia (Large)", "sku": "KGG-OUT-010", "price": 500.0, "stock": 50, "category": "outdoor", "isActive": true}, {"id": "inv-037", "name": "Poinsettia (Extra Large)", "sku": "KGG-OUT-011", "price": 800.0, "stock": 60, "category": "outdoor", "isActive": true}, {"id": "inv-038", "name": "San Francisco", "sku": "KGG-OUT-012", "price": 50.0, "stock": 50, "category": "outdoor", "isActive": true}, {"id": "inv-039", "name": "Pandanus", "sku": "KGG-OUT-013", "price": 150.0, "stock": 50, "category": "outdoor", "isActive": true}, {"id": "inv-040", "name": "Havetia (Small)", "sku": "KGG-OUT-014", "price": 100.0, "stock": 8, "category": "outdoor", "isActive": true}, {"id": "inv-041", "name": "Havetia (Large)", "sku": "KGG-OUT-015", "price": 350.0, "stock": 45, "category": "outdoor", "isActive": true}, {"id": "inv-042", "name": "Cypress (Large)", "sku": "KGG-OUT-016", "price": 1000.0, "stock": 45, "category": "outdoor", "isActive": true}, {"id": "inv-043", "name": "Copea", "sku": "KGG-OUT-017", "price": 35.0, "stock": 5, "category": "outdoor", "isActive": true}, {"id": "inv-044", "name": "Zig Zag", "sku": "KGG-OUT-018", "price": 50.0, "stock": 3, "category": "outdoor", "isActive": true}, {"id": "inv-045", "name": "Bottle Brush (Small)", "sku": "KGG-OUT-019", "price": 100.0, "stock": 30, "category": "outdoor", "isActive": true}, {"id": "inv-046", "name": "Pandakaki (Small)", "sku": "KGG-OUT-020", "price": 50.0, "stock": 38, "category": "outdoor", "isActive": true}, {"id": "inv-047", "name": "Pandakaki (Medium)", "sku": "KGG-OUT-021", "price": 150.0, "stock": 60, "category": "outdoor", "isActive": true}, {"id": "inv-048", "name": "Maki (Small)", "sku": "KGG-OUT-022", "price": 100.0, "stock": 8, "category": "outdoor", "isActive": true}, {"id": "inv-049", "name": "Maki (Medium)", "sku": "KGG-OUT-023", "price": 250.0, "stock": 8, "category": "outdoor", "isActive": true}, {"id": "inv-050", "name": "Maki (Large)", "sku": "KGG-OUT-024", "price": 500.0, "stock": 40, "category": "outdoor", "isActive": true}, {"id": "inv-051", "name": "Thai Bamboo", "sku": "KGG-OUT-025", "price": 350.0, "stock": 45, "category": "outdoor", "isActive": true}, {"id": "inv-052", "name": "Triangularis (Small)", "sku": "KGG-OUT-026", "price": 100.0, "stock": 30, "category": "outdoor", "isActive": true}, {"id": "inv-053", "name": "Triangularis (Medium)", "sku": "KGG-OUT-027", "price": 200.0, "stock": 40, "category": "outdoor", "isActive": true}, {"id": "inv-054", "name": "Triangularis (Large)", "sku": "KGG-OUT-028", "price": 350.0, "stock": 30, "category": "outdoor", "isActive": true}, {"id": "inv-055", "name": "Golden Betcha (Large)", "sku": "KGG-OUT-029", "price": 800.0, "stock": 3, "category": "outdoor", "isActive": true}, {"id": "inv-056", "name": "Alucaria (Large)", "sku": "KGG-OUT-030", "price": 1000.0, "stock": 15, "category": "outdoor", "isActive": true}, {"id": "inv-057", "name": "Hawaiian Ti (Small)", "sku": "KGG-OUT-031", "price": 500.0, "stock": 45, "category": "outdoor", "isActive": true}, {"id": "inv-058", "name": "Hawaiian Ti (Medium)", "sku": "KGG-OUT-032", "price": 800.0, "stock": 5, "category": "outdoor", "isActive": true}, {"id": "inv-059", "name": "Hawaiian Ti (Large)", "sku": "KGG-OUT-033", "price": 1500.0, "stock": 50, "category": "outdoor", "isActive": true}, {"id": "inv-060", "name": "Cochinchinensis (Large)", "sku": "KGG-OUT-034", "price": 2500.0, "stock": 15, "category": "outdoor", "isActive": true}, {"id": "inv-061", "name": "Ordinary (20 inch)", "sku": "KGG-POT-001", "price": 250.0, "stock": 3, "category": "pots", "isActive": true}, {"id": "inv-062", "name": "Ordinary (16 inch)", "sku": "KGG-POT-002", "price": 200.0, "stock": 60, "category": "pots", "isActive": true}, {"id": "inv-063", "name": "Ordinary (12 inch)", "sku": "KGG-POT-003", "price": 150.0, "stock": 27, "category": "pots", "isActive": true}, {"id": "inv-064", "name": "Ordinary (10 inch)", "sku": "KGG-POT-004", "price": 100.0, "stock": 8, "category": "pots", "isActive": true}, {"id": "inv-065", "name": "Ordinary (8 inch)", "sku": "KGG-POT-005", "price": 80.0, "stock": 22, "category": "pots", "isActive": true}, {"id": "inv-066", "name": "Ordinary (6 inch)", "sku": "KGG-POT-006", "price": 75.0, "stock": 15, "category": "pots", "isActive": true}, {"id": "inv-067", "name": "Barrel (Small)", "sku": "KGG-POT-007", "price": 100.0, "stock": 27, "category": "pots", "isActive": true}, {"id": "inv-068", "name": "Barrel (Medium)", "sku": "KGG-POT-008", "price": 350.0, "stock": 50, "category": "pots", "isActive": true}, {"id": "inv-069", "name": "Tuaca (Small)", "sku": "KGG-POT-009", "price": 150.0, "stock": 50, "category": "pots", "isActive": true}, {"id": "inv-070", "name": "Tuaca (Medium)", "sku": "KGG-POT-010", "price": 250.0, "stock": 60, "category": "pots", "isActive": true}, {"id": "inv-071", "name": "Tuaca (Large)", "sku": "KGG-POT-011", "price": 350.0, "stock": 27, "category": "pots", "isActive": true}, {"id": "inv-072", "name": "Terracotta (Small)", "sku": "KGG-POT-012", "price": 150.0, "stock": 3, "category": "pots", "isActive": true}, {"id": "inv-073", "name": "Terracotta (Medium)", "sku": "KGG-POT-013", "price": 250.0, "stock": 50, "category": "pots", "isActive": true}, {"id": "inv-074", "name": "Terracotta (Large)", "sku": "KGG-POT-014", "price": 350.0, "stock": 5, "category": "pots", "isActive": true}, {"id": "inv-075", "name": "Painted Pots (Assorted Sizes)", "sku": "KGG-POT-015", "price": 600.0, "stock": 27, "category": "pots", "isActive": true}, {"id": "inv-076", "name": "Marble Chips (5 inch)", "sku": "KGG-PBL-001", "price": 100.0, "stock": 30, "category": "pebbles", "isActive": true}, {"id": "inv-077", "name": "Marble Chips (10 inch)", "sku": "KGG-PBL-002", "price": 100.0, "stock": 50, "category": "pebbles", "isActive": true}, {"id": "inv-078", "name": "Marble Chips (15 inch)", "sku": "KGG-PBL-003", "price": 100.0, "stock": 50, "category": "pebbles", "isActive": true}, {"id": "inv-079", "name": "Marble Chips (20 inch)", "sku": "KGG-PBL-004", "price": 100.0, "stock": 40, "category": "pebbles", "isActive": true}, {"id": "inv-080", "name": "Black Pebbles (5 inch)", "sku": "KGG-PBL-005", "price": 100.0, "stock": 38, "category": "pebbles", "isActive": true}, {"id": "inv-081", "name": "Black Pebbles (10 inch)", "sku": "KGG-PBL-006", "price": 100.0, "stock": 60, "category": "pebbles", "isActive": true}, {"id": "inv-082", "name": "Black Pebbles (15 inch)", "sku": "KGG-PBL-007", "price": 100.0, "stock": 30, "category": "pebbles", "isActive": true}, {"id": "inv-083", "name": "Lava Rock", "sku": "KGG-PBL-008", "price": 100.0, "stock": 8, "category": "pebbles", "isActive": true}, {"id": "inv-084", "name": "Bulk Stone", "sku": "KGG-PBL-009", "price": 100.0, "stock": 22, "category": "pebbles", "isActive": true}, {"id": "inv-085", "name": "Cubo", "sku": "KGG-PBL-010", "price": 100.0, "stock": 45, "category": "pebbles", "isActive": true}, {"id": "inv-086", "name": "7 Colors", "sku": "KGG-PBL-011", "price": 100.0, "stock": 8, "category": "pebbles", "isActive": true}, {"id": "inv-087", "name": "Selected White (40 inch)", "sku": "KGG-PBL-012", "price": 100.0, "stock": 38, "category": "pebbles", "isActive": true}, {"id": "inv-088", "name": "Orange (15 inch)", "sku": "KGG-PBL-013", "price": 100.0, "stock": 3, "category": "pebbles", "isActive": true}, {"id": "inv-089", "name": "Orange (15 inch)", "sku": "KGG-PBL-014", "price": 100.0, "stock": 45, "category": "pebbles", "isActive": true}, {"id": "inv-090", "name": "Loam Soil", "sku": "KGG-SUP-001", "price": 35.0, "stock": 38, "category": "supplies", "isActive": true}, {"id": "inv-091", "name": "Garden Soil", "sku": "KGG-SUP-002", "price": 35.0, "stock": 8, "category": "supplies", "isActive": true}, {"id": "inv-092", "name": "Urea Fertilizer", "sku": "KGG-SUP-003", "price": 150.0, "stock": 60, "category": "supplies", "isActive": true}, {"id": "inv-093", "name": "Complete Fertilizer", "sku": "KGG-SUP-004", "price": 150.0, "stock": 40, "category": "supplies", "isActive": true}]';

// Decode the JSON into a PHP array
$products = json_decode($json_data, true);

if (!$products) {
    die("<h3>Error: Invalid JSON formatting.</h3>");
}

try {
    // Start a transaction so they all insert at once
    $pdo->beginTransaction();
    
    // Prepare the insertion query (ON CONFLICT prevents accidental duplicates if you run it twice!)
    $stmt = $pdo->prepare("INSERT INTO products (sku, name, category, price, stock, is_active, notes) 
                           VALUES (:sku, :name, :category, :price, :stock, :is_active, :notes)
                           ON CONFLICT (sku) DO NOTHING");
    
    $inserted_count = 0;
    
    // Loop through your 93 items and insert them
    foreach ($products as $p) {
        $stmt->execute([
            ':sku'       => $p['sku'],
            ':name'      => $p['name'],
            ':category'  => $p['category'],
            ':price'     => (float)$p['price'],
            ':stock'     => (int)$p['stock'],
            ':is_active' => $p['isActive'] ? 'true' : 'false',
            ':notes'     => ''
        ]);
        $inserted_count += $stmt->rowCount();
    }

    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE passcode_hash IS NOT NULL AND is_active IS TRUE')->fetchColumn();
    if ($adminCount === 0) {
        $adminUsername = trim((string)greenprint_config('ADMIN_USERNAME', ''));
        $adminPasscode = (string)greenprint_config('ADMIN_PASSCODE', '');
        $checkoutPin = (string)greenprint_config('CHECKOUT_PIN', '');
        if ($adminUsername === '' || strlen($adminPasscode) < 10 || !preg_match('/^\d{4,8}$/', $checkoutPin)) {
            throw new RuntimeException('Set an admin username, passcode of at least 10 characters, and a unique 4–8 digit checkout PIN in .env or greenprint.local.php before seeding.');
        }
        $adminStmt = $pdo->prepare('INSERT INTO admin_users (username, passcode_hash, checkout_pin_hash, role) VALUES (:username, :passcode_hash, :checkout_pin_hash, :role)');
        $adminStmt->execute([
            ':username' => $adminUsername,
            ':passcode_hash' => password_hash($adminPasscode, PASSWORD_DEFAULT),
            ':checkout_pin_hash' => password_hash($checkoutPin, PASSWORD_DEFAULT),
            ':role' => 'owner',
        ]);
    } else {
        $pinCount = (int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE is_active IS TRUE AND checkout_pin_hash IS NOT NULL')->fetchColumn();
        if ($pinCount === 0) {
            $checkoutPin = (string)greenprint_config('CHECKOUT_PIN', '');
            if (!preg_match('/^\d{4,8}$/', $checkoutPin)) {
                throw new RuntimeException('Set a unique 4–8 digit checkout PIN in .env or greenprint.local.php before checkout can be authorized.');
            }
            $firstAdmin = $pdo->query("SELECT id FROM admin_users WHERE is_active IS TRUE AND passcode_hash IS NOT NULL ORDER BY CASE WHEN role IN ('owner', 'admin') THEN 0 ELSE 1 END, id LIMIT 1 FOR UPDATE")->fetchColumn();
            if (!$firstAdmin) throw new RuntimeException('No active admin account is available for checkout authorization.');
            $pinUpdate = $pdo->prepare('UPDATE admin_users SET checkout_pin_hash = :pin_hash WHERE id = :id');
            $pinUpdate->execute([':pin_hash' => password_hash($checkoutPin, PASSWORD_DEFAULT), ':id' => $firstAdmin]);
        }
    }
    
    $pdo->commit();
    
    echo "<div style='font-family: sans-serif; padding: 40px;'>";
    echo "<h1 style='color: #0e6c4a;'>🌱 Migration Successful!</h1>";
    echo "<p>Successfully planted <strong>{$inserted_count}</strong> items into your Supabase database.</p>";
    echo "<p>The seeder is CLI-only; it cannot be run through the web server.</p>";
    echo "</div>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h1>Database Error</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
