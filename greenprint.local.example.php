<?php
// Copy this file to greenprint.local.php and fill in your own credentials.
// greenprint.local.php is blocked from direct web access by .htaccess.
return [
    'DB_HOST' => 'your-project.pooler.supabase.com',
    'DB_PORT' => '5432',
    'DB_NAME' => 'postgres',
    'DB_USER' => 'postgres.your-project-ref',
    'DB_PASSWORD' => 'replace-with-a-new-database-password',
    'DB_SSLMODE' => 'require',
    'SUPABASE_URL' => 'https://your-project.supabase.co',
    'SUPABASE_ANON_KEY' => 'your-public-anon-key',
    'GEMINI_API_KEY' => 'replace-with-a-new-gemini-api-key',
    'GEMINI_MODEL' => 'gemini-3.8-flash',
    'DEVICE_KEY' => 'replace-with-a-long-random-device-key',
    'TIMEZONE' => 'Asia/Manila',
    'ADMIN_USERNAME' => 'admin',
    'ADMIN_PASSCODE' => 'replace-with-a-strong-passcode-at-least-10-characters',
    'CHECKOUT_PIN' => 'replace-with-a-unique-4-to-8-digit-pin',
];
