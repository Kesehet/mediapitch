<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST required']);
    exit;
}

$endpoint = strtolower((string) ($_GET['endpoint'] ?? ''));
$allowed = ['chat', 'generate', 'embed', 'embeddings'];
if (!in_array($endpoint, $allowed, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unsupported endpoint']);
    exit;
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $match)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing Bearer API key']);
    exit;
}

$user = proxy_find_user_by_api_key(trim($match[1]));
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or disabled API key']);
    exit;
}

$limit = (int) $user['daily_request_limit'];
$used = proxy_requests_today((int) $user['id']);
if ($limit > 0 && $used >= $limit) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode([
        'error' => 'Daily request limit reached',
        'limit' => $limit,
        'used' => $used,
    ]);
    exit;
}

$body = file_get_contents('php://input') ?: '';
if ($body === '' || strlen($body) > 2 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Request body is empty or too large']);
    exit;
}

$decoded = json_decode($body, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}
$model = isset($decoded['model']) && is_string($decoded['model']) ? substr($decoded['model'], 0, 150) : null;

$config = proxy_config();
$url = $config['upstream_base_url'] . '/api/' . $endpoint;
$upstreamKey = proxy_select_upstream_key((int) $user['id']);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $upstreamKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 300,
]);

$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    $status = 502;
    $response = json_encode(['error' => 'Upstream connection failed', 'detail' => $curlError]);
}
if ($status < 100) $status = 502;

proxy_log_usage(
    (int) $user['id'],
    $endpoint,
    $model,
    $status,
    strlen($body),
    strlen((string) $response)
);

http_response_code($status);
header('X-RateLimit-Limit: ' . ($limit > 0 ? $limit : 'unlimited'));
header('X-RateLimit-Remaining: ' . ($limit > 0 ? max(0, $limit - $used - 1) : 'unlimited'));
echo $response;
