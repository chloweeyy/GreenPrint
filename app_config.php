<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
$config = greenprint_config();
echo 'window.GREENPRINT_CONFIG = ' . json_encode([
    'supabaseUrl' => $config['supabase_url'],
    'supabaseAnonKey' => $config['supabase_anon_key'],
], JSON_UNESCAPED_SLASHES) . ';';
