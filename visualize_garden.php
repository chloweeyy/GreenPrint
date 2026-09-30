<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini_client.php';

require_http_method('POST');
$input = request_json();
$theme = trim((string)($input['theme'] ?? ''));
if ($theme === '' || strlen($theme) > 120) {
    json_response(['status' => 'error', 'message' => 'Choose a garden theme first.'], 400);
}
try {
    $inventory = $pdo->query("SELECT id, name, category FROM products WHERE is_active IS TRUE AND stock > 0 AND category IN ('indoor', 'outdoor', 'pots', 'pebbles') ORDER BY category, name")->fetchAll();
    if (!$inventory) throw new RuntimeException('No in-stock products are available.');
    $inventoryText = json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $prompt = "Design a practical landscape plan inspired by the selected template " . json_encode($theme, JSON_UNESCAPED_UNICODE) . ". Leave a clear walking route, balance planted areas with open space, and group containers naturally. Choose only exact product names from this in-stock inventory: {$inventoryText}. Return JSON with exactly five keys: placements, layout_tip, dimension_guide, scene_balance, focal_point. placements must contain 3 to 6 objects, each with plant, pot, stones, x, y, size. Use exact indoor/outdoor names for plant, pots names for pot, pebbles names for stones; use None only if that category has no in-stock items. x/y are integer percentages 10 through 90. size is small, medium, or large. Do not invent products or dimensions. Keep the four tips concise.";
    $design = gemini_json_text(gemini_generate([['text' => $prompt]], 45));
    $roles = ['plant' => ['indoor','outdoor'], 'pot' => ['pots'], 'stones' => ['pebbles']];
    $placements = [];
    $candidates = is_array($design['placements'] ?? null) ? array_slice($design['placements'], 0, 6) : [];
    foreach ($candidates as $index => $candidate) {
        if (!is_array($candidate)) continue;
        $entry = [];
        foreach ($roles as $key => $categories) {
            $name = trim((string)($candidate[$key] ?? ''));
            $entry[$key] = null;
            foreach ($inventory as $item) {
                if (in_array((string)$item['category'], $categories, true) && strcasecmp($name, (string)$item['name']) === 0) {
                    $entry[$key] = (string)$item['name'];
                    break;
                }
            }
        }
        if ($entry['plant'] === null && $entry['pot'] === null) continue;
        $entry['x'] = max(10, min(90, (int)($candidate['x'] ?? (20 + $index * 12))));
        $entry['y'] = max(10, min(90, (int)($candidate['y'] ?? (30 + $index * 8))));
        $size = strtolower(trim((string)($candidate['size'] ?? 'medium')));
        $entry['size'] = in_array($size, ['small','medium','large'], true) ? $size : 'medium';
        $placements[] = $entry;
    }
    $hasLegacySuggestion = !empty($design['suggested_plant']) || !empty($design['suggested_pot']);
    if (!$placements && $hasLegacySuggestion) {
        $old = ['plant'=>$design['suggested_plant'] ?? null,'pot'=>$design['suggested_pot'] ?? null,'stones'=>$design['suggested_stones'] ?? null];
        $entry = [];
        foreach ($roles as $key => $categories) {
            $entry[$key] = null;
            foreach ($inventory as $item) {
                if (in_array((string)$item['category'], $categories, true) && strcasecmp(trim((string)$old[$key]), (string)$item['name']) === 0) {
                    $entry[$key] = (string)$item['name'];
                    break;
                }
            }
        }
        $entry += ['x'=>50,'y'=>50,'size'=>'medium'];
        if ($entry['plant'] !== null || $entry['pot'] !== null) $placements[] = $entry;
    }
    $safeText = static function (string $key, string $fallback) use ($design): string {
        $text = strip_tags(trim((string)($design[$key] ?? $fallback)));
        return function_exists('mb_substr') ? mb_substr($text, 0, 260) : substr($text, 0, 260);
    };
    json_response(['status'=>'success','data'=>[
        'placements'=>$placements,
        'layout_tip'=>$safeText('layout_tip','Group planters and keep a clear route through the garden.'),
        'dimension_guide'=>$safeText('dimension_guide','Use the one metre grid to estimate container spacing.'),
        'scene_balance'=>$safeText('scene_balance','Balance planted zones with open space and walking routes.'),
        'focal_point'=>$safeText('focal_point','Use a taller planter as the focal point.')
    ]]);
} catch (Throwable $e) {
    error_log('GreenPrint garden AI failed: ' . $e->getMessage());
    json_response(['status'=>'error','message'=>'AI decorating is temporarily unavailable.'], 502);
}
