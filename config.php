<?php
declare(strict_types=1);

// Local configuration can come from Apache environment variables, a protected
// .env file, or greenprint.local.php (ignored and denied by .htaccess).
$localConfig = [];
$localConfigPath = __DIR__ . '/greenprint.local.php';
if (is_file($localConfigPath)) {
    $loaded = require $localConfigPath;
    if (is_array($loaded)) $localConfig = $loaded;
}
$envPath = __DIR__ . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) $value = substr($value, 1, -1);
        if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
    }
}

function greenprint_config(?string $name = null, $default = null)
{
    global $localConfig;
    $read = static function (string $key, $fallback = null) use ($localConfig) {
        $environmentName = str_starts_with($key, 'GREENPRINT_') ? $key : 'GREENPRINT_' . $key;
        $value = getenv($environmentName);
        if ($value !== false && $value !== '') return $value;
        $value = getenv($key);
        if ($value !== false && $value !== '') return $value;
        return $localConfig[$key] ?? $localConfig[$environmentName] ?? $fallback;
    };

    if ($name !== null) return $read($name, $default);
    $dbPassword = $read('DB_PASSWORD', '');
    $geminiKey = getenv('GEMINI_API_KEY');
    if ($geminiKey === false || $geminiKey === '') $geminiKey = $read('GEMINI_API_KEY', '');
    $timezone = (string)$read('TIMEZONE', 'Asia/Manila');
    if (in_array($timezone, timezone_identifiers_list(), true)) date_default_timezone_set($timezone);
    return [
        'db_host' => (string)$read('DB_HOST', ''),
        'db_port' => (string)$read('DB_PORT', '5432'),
        'db_name' => (string)$read('DB_NAME', 'postgres'),
        'db_user' => (string)$read('DB_USER', ''),
        'db_password' => (string)$dbPassword,
        'db_sslmode' => (string)$read('DB_SSLMODE', 'require'),
        'supabase_url' => rtrim((string)$read('SUPABASE_URL', ''), '/'),
        'supabase_anon_key' => (string)$read('SUPABASE_ANON_KEY', ''),
        'gemini_api_key' => (string)$geminiKey,
        'gemini_model' => (string)$read('GEMINI_MODEL', 'gemini-3.8-flash'),
        'device_key' => (string)$read('DEVICE_KEY', ''),
        'app_timezone' => $timezone,
    ];
}

// Apply the configured timezone for request timestamps and transaction references.
greenprint_config();
