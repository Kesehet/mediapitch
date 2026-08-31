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

$endpoint = strtolower((string)($_GET['endpoint'] ?? ''));
$allowed = ['chat', 'generate', 'embed', 'embeddings'];
if (!in_array($endpoint, $allowed, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unsupported endpoint']);
    exit;
}

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
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

$limit = (int)$user['daily_request_limit'];
$used = proxy_requests_today((int)$user['id']);
if ($limit > 0 && $used >= $limit) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['error' => 'Daily request limit reached', 'limit' => $limit, 'used' => $used]);
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
$url = rtrim((string)$config['upstream_base_url'], '/') . '/api/' . $endpoint;
$upstreamKeys = proxy_ordered_upstream_keys((int)$user['id']);
if (!$upstreamKeys) {
    http_response_code(503);
    echo json_encode(['error' => 'No upstream Ollama Cloud API key configured']);
    exit;
}

$finalResponse = '';
$finalStatus = 502;
$attempts = 0;
$attemptedKeyIds = [];

foreach ($upstreamKeys as $upstreamKey) {
    $attempts++;
    $keyId = proxy_upstream_key_id($upstreamKey);
    $attemptedKeyIds[] = $keyId;

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
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    $transportFailed = ($response === false);
    curl_close($ch);

    if ($transportFailed) {
        $status = 502;
        $response = json_encode(['error' => 'Upstream connection failed', 'detail' => $curlError]);
    }
    if ($status < 100) $status = 502;

    $finalStatus = $status;
    $finalResponse = (string)$response;

    if (!proxy_should_retry_upstream($status, $finalResponse, $transportFailed)) {
        proxy_mark_upstream_healthy($upstreamKey);
        if ($attempts > 1) {
            proxy_log('UPSTREAM FAILOVER SUCCESS after ' . $attempts . ' attempts; key=' . $keyId . '; endpoint=' . $endpoint . '; model=' . ($model ?? 'n/a'));
        }
        break;
    }

    $cooldown = proxy_upstream_cooldown_seconds($status, $finalResponse, $transportFailed);
    proxy_mark_upstream_unhealthy($upstreamKey, $cooldown, 'HTTP ' . $status . ' endpoint=' . $endpoint . ' model=' . ($model ?? 'n/a'));
    proxy_log('UPSTREAM FAILOVER attempt=' . $attempts . ' key=' . $keyId . ' status=' . $status . ' endpoint=' . $endpoint);
}

proxy_log_usage(
    (int)$user['id'],
    $endpoint,
    $model,
    $finalStatus,
    strlen($body),
    strlen($finalResponse)
);

http_response_code($finalStatus);
header('X-RateLimit-Limit: ' . ($limit > 0 ? $limit : 'unlimited'));
header('X-RateLimit-Remaining: ' . ($limit > 0 ? max(0, $limit - $used - 1) : 'unlimited'));
header('X-Ollama-Proxy-Attempts: ' . $attempts);
header('X-Ollama-Proxy-Upstreams: ' . count($upstreamKeys));

if ($attempts >= count($upstreamKeys) && proxy_should_retry_upstream($finalStatus, $finalResponse, false)) {
    proxy_log('ALL UPSTREAM KEYS FAILED status=' . $finalStatus . ' keys=' . implode(',', $attemptedKeyIds) . ' endpoint=' . $endpoint . ' model=' . ($model ?? 'n/a'));
}

echo $finalResponse;
