<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';
json_response(['status' => 'error', 'message' => 'This legacy route is disabled. Use the authenticated admin product API.'], 410);
