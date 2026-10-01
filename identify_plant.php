<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini_client.php';

require_http_method('POST');
if (!isset($_FILES['plant_image']) || $_FILES['plant_image']['error'] !== UPLOAD_ERR_OK) {
    json_response(['status' => 'error', 'message' => 'Choose a plant image before scanning.'], 400);
}
$upload = $_FILES['plant_image'];
if ((int)$upload['size'] < 1 || (int)$upload['size'] > 8 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
    json_response(['status' => 'error', 'message' => 'Use a valid image smaller than 8 MB.'], 413);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($upload['tmp_name']);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    json_response(['status' => 'error', 'message' => 'Use a JPEG, PNG, or WebP image.'], 415);
}

$cleanText = static function ($value, int $max = 500): string {
    $value = strip_tags(trim((string)$value));
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
};
$normalizeName = static function (string $value): string {
    $value = preg_replace('/\([^)]*\)/u', '', $value) ?? $value;
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return strtolower(trim($value));
};

try {
    $inventory = $pdo->query("SELECT id, sku, name, category, price, stock FROM products WHERE is_active IS TRUE AND stock > 0 AND lower(category) IN ('indoor', 'outdoor') ORDER BY category, name")->fetchAll();
    $careRecords = $pdo->query('SELECT common_name, scientific_name, care_instructions, sunlight, watering, soil_type, ideal_temperature, is_toxic FROM plant_care_info ORDER BY common_name')->fetchAll();
    $inventoryText = json_encode(array_map(static fn($item) => ['name' => $item['name'], 'category' => $item['category']], $inventory), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $careText = json_encode($careRecords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $imageData = file_get_contents($upload['tmp_name']);
    if ($imageData === false) throw new RuntimeException('Unable to read the uploaded plant image.');
    $prompt = "Identify the plant in this image for a garden store kiosk. Compare against the in-stock plant inventory and verified care records. State uncertainty clearly. Return only JSON with keys name, scientific_name, care_instructions, sunlight, watering, soil_type, ideal_temperature, is_toxic. Use is_toxic true, false, or null. Do not claim a product is in stock unless it exactly matches the inventory. Inventory: {$inventoryText}. Verified care records: {$careText}.";
    $response = gemini_generate([
        ['text' => $prompt],
        ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageData)]],
    ], 60);
    $plant = gemini_json_text($response);
    $data = [
        'name' => $cleanText($plant['name'] ?? 'Unknown plant', 160),
        'scientific_name' => $cleanText($plant['scientific_name'] ?? '', 160),
        'care_instructions' => $cleanText($plant['care_instructions'] ?? ''),
        'sunlight' => $cleanText($plant['sunlight'] ?? ''),
        'watering' => $cleanText($plant['watering'] ?? ''),
        'soil_type' => $cleanText($plant['soil_type'] ?? ''),
        'ideal_temperature' => $cleanText($plant['ideal_temperature'] ?? ''),
        'is_toxic' => is_bool($plant['is_toxic'] ?? null) ? $plant['is_toxic'] : null,
    ];

    $plantName = $normalizeName($data['name']);
    $scientificName = $normalizeName($data['scientific_name']);
    $matchedCare = null;
    foreach ($careRecords as $record) {
        if (($plantName !== '' && $plantName === $normalizeName((string)$record['common_name'])) ||
            ($scientificName !== '' && $scientificName === $normalizeName((string)($record['scientific_name'] ?? '')))) {
            $matchedCare = $record;
            break;
        }
    }
    if ($matchedCare) {
        foreach (['care_instructions', 'sunlight', 'watering', 'soil_type', 'ideal_temperature'] as $field) {
            if (trim((string)($matchedCare[$field] ?? '')) !== '') $data[$field] = $cleanText($matchedCare[$field]);
        }
        if ($matchedCare['is_toxic'] !== null) $data['is_toxic'] = filter_var($matchedCare['is_toxic'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($plantName === '') $data['name'] = $cleanText($matchedCare['common_name'], 160);
    }

    $matchNames = array_filter([
        $normalizeName($data['name']),
        $normalizeName($data['scientific_name']),
        $matchedCare ? $normalizeName((string)$matchedCare['common_name']) : '',
        $matchedCare ? $normalizeName((string)($matchedCare['scientific_name'] ?? '')) : '',
    ]);
    $products = [];
    foreach ($inventory as $item) {
        $candidateNames = [$normalizeName((string)$item['name'])];
        if (preg_match('/\(([^)]*)\)/u', (string)$item['name'], $parts)) $candidateNames[] = $normalizeName($parts[1]);
        if (count(array_intersect($matchNames, array_filter($candidateNames))) > 0) {
            $products[] = [
                'id' => (string)$item['id'], 'sku' => $item['sku'], 'name' => $item['name'],
                'category' => $item['category'], 'price' => (float)$item['price'], 'stock' => (int)$item['stock'],
            ];
        }
    }
    json_response(['status' => 'success', 'data' => $data, 'products' => $products]);
} catch (Throwable $e) {
    error_log('GreenPrint plant identification failed: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Plant identification is temporarily unavailable. You can try again or ask a garden specialist.'], 502);
}
