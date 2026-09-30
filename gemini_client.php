<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function gemini_generate(array $parts, int $timeout = 45): array
{
    $config = greenprint_config();
    if ($config['gemini_api_key'] === '') {
        throw new RuntimeException('Plant AI is not configured yet. Add GEMINI_API_KEY to .env.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for plant AI.');
    }
    $model = preg_replace('/[^a-zA-Z0-9._-]/', '', $config['gemini_model']);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $payload = json_encode([
        'contents' => [['parts' => $parts]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $config['gemini_api_key']],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error !== '') {
        throw new RuntimeException('Plant AI could not be reached. Try again in a moment.');
    }
    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        error_log('GreenPrint Gemini request failed with HTTP ' . $status . ': ' . substr((string)$body, 0, 1000));
        throw new RuntimeException('Plant AI returned an error. Check the configured model and API key.');
    }
    return $decoded;
}

function gemini_json_text(array $response): array
{
    $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($text) || !preg_match('/\{[\s\S]*\}/', $text, $matches)) {
        throw new RuntimeException('Plant AI did not return a usable result.');
    }
    $value = json_decode($matches[0], true);
    if (!is_array($value)) {
        throw new RuntimeException('Plant AI returned malformed data.');
    }
    return $value;
}
