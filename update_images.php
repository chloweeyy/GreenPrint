<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db.php';

// Keyword mapping: If the product name contains the key, it gets the assigned image path.
$image_map = [
    // SUPPLIES
    'Complete Fertilizer' => 'IMAGE/Supplies/complete_fertilizer.png',
    'Garden Soil' => 'IMAGE/Supplies/garden_soil.png',
    'Loam Soil' => 'IMAGE/Supplies/loam_soil.png',
    'Urea Fertilizer' => 'IMAGE/Supplies/urea_fertilizer.png',
    
    // POTS
    'Barrel' => 'IMAGE/Pots/barrel_pot.png',
    'Ordinary' => 'IMAGE/Pots/ordinary_pot.png',
    'Painted Pots' => 'IMAGE/Pots/painted_pot_assorted.png',
    'Terracotta' => 'IMAGE/Pots/terracotta_pot.png',
    'Tuaca' => 'IMAGE/Pots/tuaca_pot.png',
    
    // PEBBLES
    'Black Pebbles' => 'IMAGE/Pebbles/black_pebbles.png',
    'Bulk Stone' => 'IMAGE/Pebbles/bulk_stone.png',
    'Lava Rock' => 'IMAGE/Pebbles/lava_rock.png',
    'Marble Chips' => 'IMAGE/Pebbles/marble_chips.png',
    'Orange' => 'IMAGE/Pebbles/orange.png',
    'Selected White' => 'IMAGE/Pebbles/selected_white.png',
    '7 Colors' => 'IMAGE/Pebbles/seven_colors.png',
    'Cubo' => 'IMAGE/Pebbles/bulk_stone.png', // Fallback for Cubo
    
    // OUTDOOR PLANTS
    'Alucaria' => 'IMAGE/Outdoor Plants/alucaria_large.png',
    'Bottle Brush' => 'IMAGE/Outdoor Plants/bottle_brush_small.png',
    'Cochinchinensis' => 'IMAGE/Outdoor Plants/cochinchinensis_large.png',
    'Copea' => 'IMAGE/Outdoor Plants/copea.png',
    'Cypress' => 'IMAGE/Outdoor Plants/cypress_large.png',
    'Eugenia (Small)' => 'IMAGE/Outdoor Plants/eugenia_small.png',
    'Eugenia (Medium)' => 'IMAGE/Outdoor Plants/eugenia_medium.png',
    'Eugenia (Large)' => 'IMAGE/Outdoor Plants/eugenia_large.png',
    'Forget-Me-Not' => 'IMAGE/Outdoor Plants/forget_me_not.png',
    'Golden Betcha' => 'IMAGE/Outdoor Plants/golden_betcha.png',
    'Havetia' => 'IMAGE/Outdoor Plants/havetia_small.png',
    'Hawaiian Ti (Small)' => 'IMAGE/Outdoor Plants/hawaiian_ti_small.png',
    'Hawaiian Ti (Medium)' => 'IMAGE/Outdoor Plants/hawaiian_ti_medium.png',
    'Hawaiian Ti (Large)' => 'IMAGE/Outdoor Plants/hawaiian_ti_large.png',
    'Maki (Small)' => 'IMAGE/Outdoor Plants/maki_small.png',
    'Maki (Medium)' => 'IMAGE/Outdoor Plants/maki_medium.png',
    'Maki (Large)' => 'IMAGE/Outdoor Plants/maki_large.png',
    'Pandakaki (Small)' => 'IMAGE/Outdoor Plants/pandakaki_small.png',
    'Pandakaki (Medium)' => 'IMAGE/Outdoor Plants/pandakaki_medium.png',
    'Pandanus' => 'IMAGE/Outdoor Plants/pandanus.png',
    'Picarra' => 'IMAGE/Outdoor Plants/picarra.png',
    'Poinsettia (Small)' => 'IMAGE/Outdoor Plants/poinsetta_small.png',
    'Poinsettia (Medium)' => 'IMAGE/Outdoor Plants/poinsetta_medium.png',
    'Poinsettia (Large)' => 'IMAGE/Outdoor Plants/poinsetta_large.png',
    'Poinsettia (Extra Large)' => 'IMAGE/Outdoor Plants/poinsetta_extra_large.png',
    'San Francisco' => 'IMAGE/Outdoor Plants/san_francisco.png',
    'Santan Plain' => 'IMAGE/Outdoor Plants/santan_plain.png',
    'Santan Rose' => 'IMAGE/Outdoor Plants/santan_rose.png',
    'Thai Bamboo' => 'IMAGE/Outdoor Plants/thai_bamboo.png',
    'Triangularis (Small)' => 'IMAGE/Outdoor Plants/triangularis_small.png',
    'Triangularis (Medium)' => 'IMAGE/Outdoor Plants/triangularis_medium.png',
    'Triangularis (Large)' => 'IMAGE/Outdoor Plants/triangularis_large.png',
    'Zig Zag' => 'IMAGE/Outdoor Plants/zig_zag.png',
    
    // INDOOR PLANTS
    'African Talisay (Small)' => 'IMAGE/Indoor Plants/african_talisay_small.png',
    'African Talisay (Large)' => 'IMAGE/Indoor Plants/african_talisay_large.png',
    'Anthurium' => 'IMAGE/Indoor Plants/anthurium.png',
    'Bakya' => 'IMAGE/Indoor Plants/bakya.png',
    'Bird of Paradise' => 'IMAGE/Indoor Plants/bird_of_paradise.png',
    'Bromeliad' => 'IMAGE/Indoor Plants/bromeliad.png',
    'Bucida' => 'IMAGE/Indoor Plants/bucida_large.png',
    'Calathea' => 'IMAGE/Indoor Plants/calathea_zebra.png',
    'Doña Carmen' => 'IMAGE/Indoor Plants/doña_carmen.png',
    'Five Fingers' => 'IMAGE/Indoor Plants/five_fingers.png',
    'Horse Tail' => 'IMAGE/Indoor Plants/horse_tail.png',
    'Lourdes' => 'IMAGE/Indoor Plants/lourdes.png',
    'Marianne' => 'IMAGE/Indoor Plants/marianne_dieffenbachia.png',
    'Miagos' => 'IMAGE/Indoor Plants/miagos.png',
    'Orchids' => 'IMAGE/Indoor Plants/orchids.png',
    'Peace Lily' => 'IMAGE/Indoor Plants/peace_lily.png',
    'Pinugo' => 'IMAGE/Indoor Plants/pinugo.png',
    'Red Siam' => 'IMAGE/Indoor Plants/red_siam_aglaonema.png',
    'Ruellia' => 'IMAGE/Indoor Plants/ruellia.png',
    'Selloum' => 'IMAGE/Indoor Plants/selloum_medium.png',
    'Silver King' => 'IMAGE/Indoor Plants/silver_king_aglaonema.png',
    'Snow White' => 'IMAGE/Indoor Plants/snow_white_aglaonema.png',
    'Sun Rise' => 'IMAGE/Indoor Plants/sun_rise.png',
    'Ti Plant' => 'IMAGE/Indoor Plants/ti_plant.png',
    'Yellow Bell' => 'IMAGE/Indoor Plants/yellow_bell.png',
    'Yellow Irish' => 'IMAGE/Indoor Plants/yellow_irish.png'
];

// Only assign image files that exist within the local IMAGE directory.
$imageRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'IMAGE');
$image_map = array_filter($image_map, static function (string $path) use ($imageRoot): bool {
    $candidate = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    if (!$imageRoot || !$candidate || !is_file($candidate)) return false;
    return str_starts_with(strtolower($candidate), strtolower($imageRoot . DIRECTORY_SEPARATOR));
});

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("UPDATE products SET image_url = :url WHERE name LIKE :keyword");
    
    $count = 0;
    foreach ($image_map as $keyword => $url) {
        $stmt->execute([
            ':url' => $url,
            ':keyword' => '%' . $keyword . '%'
        ]);
        $count += $stmt->rowCount();
    }
    
    $pdo->commit();
    echo "Updated {$count} product images in the database." . PHP_EOL;

} catch (Exception $e) {
    $pdo->rollBack();
    error_log('GreenPrint image synchronization failed: ' . $e->getMessage());
    fwrite(STDERR, "Image synchronization failed. Check the PHP error log." . PHP_EOL);
    exit(1);
}
?>
