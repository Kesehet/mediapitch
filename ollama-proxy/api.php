<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/version.php';

@set_time_limit(0);
@ini_set('max_execution_time', '0');
ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');

/**
 * Return only an explicit top-level upstream error. Never scan generated model
 * content for words such as "limit" or "capacity" because legitimate answers
 * may contain those words.
 */
function proxy_api_explicit_error(string $response): ?string
{
    $json = json_decode($response, true);
    if (!is_array($json)) return null;

    if (isset($json['error']) && is_string($json['error'])) {
        return $json['error'];
    }
    if (isset($json['error']['message']) && is_string($json['error']['message'])) {
        return $json['error']['message'];
    }
    return null;
}

function proxy_api_should_retry(int $status, string $response, bool $transportFailed = false): bool
{
    if ($transportFailed) return true;

    // A normal 2xx response is successful. Only retry a 2xx response when the
    // provider explicitly returned a top-level transient/quota error object.
    if ($status >= 200 && $status < 300) {
        $error = proxy_api_explicit_error($response);
        if ($error === null) return false;
        $text = strtolower($error);
        foreach (['rate limit', 'rate_limit', 'quota', 'usage limit', 'limit exceeded', 'too many requests', 'capacity', 'temporarily unavailable'] as $needle) {
            if (str_contains($text, $needle)) return true;
        }
        return false;
    }

    if (in_array($status, [401, 403, 408, 409, 425, 429], true)) return true;
    if ($status >= 500) return true;
    return false;
}

function proxy_api_cooldown_seconds(int $status, string $response, bool $transportFailed = false): int
{
    if ($transportFailed) return 60;
    if ($status === 429) return 900;
    if (in_array($status, [401, 403], true)) return 3600;
    if ($status >= 500 || in_array($status, [408, 409, 425], true)) return 120;

    $error = proxy_api_explicit_error($response);
    if ($error !== null) {
        $text = strtolower($error);
        if (str_contains($text, 'limit') || str_contains($text, 'quota') || str_contains($text, 'too many requests')) return 900;
    }
    return 60;
}

$endpoint = strtolower((string)($_GET['endpoint'] ?? ''));
$endpointMethods = [
    'chat' => 'POST',
    'generate' => 'POST',
    'embed' => 'POST',
    'embeddings' => 'POST',
    'tags' => 'GET',
    'show' => 'POST',
    'ps' => 'GET',
    'version' => 'GET',
    'v1/models' => 'GET',
    'v1/chat/completions' => 'POST',
    'v1/completions' => 'POST',
    'v1/embeddings' => 'POST',
    'v1/responses' => 'POST',
];

if (!isset($endpointMethods[$endpoint])) {
    http_response_code(404);
    echo json_encode(['error' => 'Unsupported endpoint']);
    exit;
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$expectedMethod = $endpointMethods[$endpoint];
if ($requestMethod !== $expectedMethod) {
    http_response_code(405);
    header('Allow: ' . $expectedMethod);
    echo json_encode(['error' => $expectedMethod . ' required']);
    exit;
}

// Ollama's version probe is read-only. Keep it available without credentials so
// clients can discover the proxy before sending authenticated model requests.
// This describes the proxy's API compatibility level, NOT an Ollama daemon.
if ($endpoint === 'version') {
    echo json_encode(['version' => proxy_cached_latest_ollama_version()], JSON_UNESCAPED_SLASHES);
    exit;
}

$metadataEndpoints = ['tags', 'show', 'ps', 'v1/models'];
$isMetadata = in_array($endpoint, $metadataEndpoints, true);

// VS Code Copilot's built-in Ollama provider does not attach auth headers
// when listing models or inspecting their capabilities.
// Allow *only* these two read-only model-discovery operations without a key.
// Operators can disable this using OLLAMA_PROXY_PUBLIC_MODEL_DISCOVERY=false.
$auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$publicOption = getenv('OLLAMA_PROXY_PUBLIC_MODEL_DISCOVERY');
$publicEnabled = filter_var($publicOption === false ? 'true' : $publicOption, FILTER_VALIDATE_BOOLEAN);
$publicDiscovery = $auth === '' && $publicEnabled && in_array($endpoint, ['tags', 'show'], true);
$user = null;
if (!$publicDiscovery) {
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
}
if ($publicDiscovery) {
    // Server-observed IP only; never trust forwarded headers supplied by callers.
    // Limit unauthenticated cloud metadata lookups to 60 per minute per IP.
    $rateFile = proxy_data_dir() . '/discover-' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
    $handle = @fopen($rateFile, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        http_response_code(503);
        echo json_encode(['error' => 'Discovery temporarily unavailable']);
        exit;
    }
    $state = json_decode(stream_get_contents($handle) ?: '', true);
    $now = time();
    if (!is_array($state) || ($state['since'] ?? 0) <= $now - 60) {
        $state = ['since' => $now, 'count' => 0];
    }
    ++$state['count'];
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($state['count'] > 60) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['error' => 'Public discovery rate limit reached']);
        exit;
    }
}
$limit = $user ? (int)$user['daily_request_limit'] : 0;
$used = $user ? proxy_requests_today((int)$user['id']) : 0;
if (!$isMetadata && $limit > 0 && $used >= $limit) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['error' => 'Daily request limit reached', 'limit' => $limit, 'used' => $used]);
    exit;
}

// This gateway runs no Ollama daemon and therefore holds no models in RAM.
// Return Ollama's documented /api/ps shape instead of probing a cloud-only
// backend that may not implement local resident-model inspection.
if ($endpoint === 'ps') {
    echo '{"models":[]}';
    exit;
}

$body = '';
$decoded = [];
$clientRequestedStreaming = false;
$model = null;

if ($requestMethod === 'POST') {
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

    $clientRequestedStreaming = ($decoded['stream'] ?? null) === true;
    if ($endpoint === 'show') {
        $requestedModel = $decoded['model'] ?? $decoded['name'] ?? null;
        if (!is_string($requestedModel) || !preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._:/-]{0,149}$~D', $requestedModel)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid model name']);
            exit;
        }
    }
    if (!array_key_exists('stream', $decoded) && in_array($endpoint, ['chat', 'generate'], true)) {
        $decoded['stream'] = false;
        $body = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Unable to encode request JSON']);
            exit;
        }
    }

    $model = isset($decoded['model']) && is_string($decoded['model']) ? substr($decoded['model'], 0, 150) : null;
}
$config = proxy_config();
$url = rtrim((string)$config['upstream_base_url'], '/') . '/' . (str_starts_with($endpoint, 'v1/') ? $endpoint : 'api/' . $endpoint);
$upstreamKeys = proxy_ordered_upstream_keys($user ? (int)$user['id'] : 0);
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
    $startedAt = microtime(true);
    $streaming = $clientRequestedStreaming && in_array($endpoint, [
        'chat', 'generate', 'v1/chat/completions', 'v1/completions', 'v1/responses'
    ], true);
    $streamStarted = false;
    $streamedBytes = 0;
    $streamBuffer = '';

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $upstreamKey,
            'Accept: ' . ($streaming ? 'text/event-stream, application/x-ndjson, application/json' : 'application/json'),
            'Connection: close',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_NOSIGNAL => true,
    ];

    if ($requestMethod === 'POST') {
        $curlOptions[CURLOPT_POST] = true;
        $curlOptions[CURLOPT_POSTFIELDS] = $body;
        $curlOptions[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    } else {
        $curlOptions[CURLOPT_HTTPGET] = true;
    }

    if ($streaming) {
        // The upstream delivers SSE for /v1 and NDJSON for native Ollama.
        // Forward successful chunks as received; keep HTTP errors buffered
        // so rotating keys cannot emit a broken or duplicated response.
        $curlOptions[CURLOPT_RETURNTRANSFER] = false;
        $curlOptions[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk)
            use (&$streamStarted, &$streamedBytes, &$streamBuffer, $endpoint): int {
            $n = strlen($chunk);
            $streamedBytes += $n;
            $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $mime = strtolower((string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
            if ($code < 200 || $code >= 300 || str_contains($mime, 'application/json')) {
                if (strlen($streamBuffer) < 65536) $streamBuffer .= $chunk;
                return $n;
            }
            if (!$streamStarted) {
                $streamStarted = true;
                http_response_code($code);
                header('Content-Type: ' . (str_starts_with($endpoint, 'v1/')
                    ? 'text/event-stream; charset=utf-8'
                    : 'application/x-ndjson; charset=utf-8'));
                header('Cache-Control: no-store, no-transform');
                header('X-Accel-Buffering: no');
                ignore_user_abort(false);
            }
            echo $chunk;
            if (ob_get_level() > 0) @ob_flush();
            flush();
            return connection_aborted() ? 0 : $n;
        };
    }
    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    if ($streaming && $response !== false) $response = $streamBuffer;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    $transportFailed = ($response === false);
    $elapsed = round(microtime(true) - $startedAt, 2);
    curl_close($ch);

    if ($transportFailed) {
        $status = 502;
        $response = json_encode(['error' => 'Upstream connection failed', 'detail' => $curlError]);
        proxy_log('UPSTREAM TRANSPORT ERROR key=' . $keyId . ' curl_errno=' . $curlErrno . ' elapsed=' . $elapsed . 's detail=' . $curlError . ' endpoint=' . $endpoint . ' model=' . ($model ?? 'n/a'));
    }
    if ($status < 100) $status = 502;

    $finalStatus = $status;
    $finalResponse = (string)$response;

    if ($streamStarted) {
        // Never fail over after any output; this could duplicate agent tool
        // calls or splice a second response into the original SSE stream.
        if ($transportFailed) {
            proxy_log('STREAM INTERRUPTED key=' . $keyId . ' endpoint=' . $endpoint
                . ' model=' . ($model ?? 'n/a') . ' errno=' . $curlErrno);
        } else {
            proxy_mark_upstream_healthy($upstreamKey);
            proxy_remember_upstream_key($upstreamKey);
        }
        $finalResponse = '';
        break;
    }

    if (!proxy_api_should_retry($status, $finalResponse, $transportFailed)) {
        proxy_mark_upstream_healthy($upstreamKey);
        proxy_remember_upstream_key($upstreamKey);
        if ($attempts > 1) {
            proxy_log('UPSTREAM FAILOVER SUCCESS after ' . $attempts . ' attempts; sticky_key=' . $keyId . '; endpoint=' . $endpoint . '; model=' . ($model ?? 'n/a'));
        }
        break;
    }

    $cooldown = proxy_api_cooldown_seconds($status, $finalResponse, $transportFailed);
    proxy_mark_upstream_unhealthy($upstreamKey, $cooldown, 'HTTP ' . $status . ' endpoint=' . $endpoint . ' model=' . ($model ?? 'n/a'));
    proxy_log('UPSTREAM FAILOVER attempt=' . $attempts . ' key=' . $keyId . ' status=' . $status . ' elapsed=' . $elapsed . 's endpoint=' . $endpoint);
}

if ($user) proxy_log_usage((int)$user['id'], $endpoint, $model, $finalStatus, strlen($body),
    $streamedBytes + strlen($finalResponse));

// Streaming response headers are already committed with the first chunk.
if (!$streamStarted) {
    http_response_code($finalStatus);
    header('X-RateLimit-Limit: ' . ($limit > 0 ? $limit : 'unlimited'));
    $quotaCost = $isMetadata ? 0 : 1;
    header('X-RateLimit-Remaining: ' . ($limit > 0 ? max(0, $limit - $used - $quotaCost) : 'unlimited'));
    header('X-Ollama-Proxy-Attempts: ' . $attempts);
    header('X-Ollama-Proxy-Upstreams: ' . count($upstreamKeys));
    header('X-Ollama-Proxy-Stream-Requested: ' . ($clientRequestedStreaming ? '1' : '0'));
}

if (!$streamStarted && $attempts >= count($upstreamKeys) && proxy_api_should_retry($finalStatus, $finalResponse, false)) {
    proxy_log('ALL UPSTREAM KEYS FAILED status=' . $finalStatus . ' keys=' . implode(',', $attemptedKeyIds) . ' endpoint=' . $endpoint . ' model=' . ($model ?? 'n/a'));
}

if (!$streamStarted) echo $finalResponse;
