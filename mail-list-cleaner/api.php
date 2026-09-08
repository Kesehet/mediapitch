<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailListCleaner.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAIL_CLEANER_API_MAX_BODY = 1048576; // 1 MiB
const MAIL_CLEANER_API_MAX_EMAILS = 1000;

function apiRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function mailCleanerSetting(string $key): string
{
    $runtime = getenv($key);
    if ($runtime !== false && trim((string)$runtime) !== '') {
        return trim((string)$runtime);
    }

    // Shared-hosting friendly fallback. The repository .htaccess blocks direct web access
    // to /.env and .gitignore keeps it out of source control.
    static $localEnv = null;
    if ($localEnv === null) {
        $path = dirname(__DIR__) . '/.env';
        $parsed = is_file($path) ? @parse_ini_file($path, false, INI_SCANNER_RAW) : false;
        $localEnv = is_array($parsed) ? $parsed : [];
    }

    return trim((string)($localEnv[$key] ?? ''));
}

function providedApiKey(): string
{
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '') {
        return $key;
    }

    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $authorization = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        }
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
        return trim($match[1]);
    }

    return '';
}

function enforceRateLimit(bool $authenticated): void
{
    // Public access is intentionally conservative because every validation can cause DNS work.
    // Authenticated integrations get a larger allowance for controlled batch/CRM use.
    $limit = $authenticated ? 120 : 30;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = gmdate('YmdHi');
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'mp-mail-cleaner-' . sha1($ip . '|' . $bucket) . '.count';

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return; // Validation still works if the host disallows temporary files.
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        rewind($handle);
        $current = (int)trim((string)stream_get_contents($handle));
        $current++;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string)$current);
        fflush($handle);
        flock($handle, LOCK_UN);

        if ($current > $limit) {
            header('Retry-After: 60');
            apiRespond([
                'ok' => false,
                'error' => 'rate_limit',
                'message' => 'Too many validation requests. Please try again shortly.',
            ], 429);
        }
    } finally {
        fclose($handle);
    }
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$configuredKey = mailCleanerSetting('MAIL_LIST_CLEANER_API_KEY');

if ($method === 'GET') {
    apiRespond([
        'ok' => true,
        'service' => 'MediaPitch Mail List Cleaner API',
        'version' => 2,
        'method' => 'POST',
        'accepts' => [
            ['email' => 'person@example.com'],
            ['emails' => ['one@example.com', 'two@example.com']],
            ['emails' => "one@example.com\ntwo@example.com"],
        ],
        'max_emails_per_request' => MAIL_CLEANER_API_MAX_EMAILS,
        'authentication_required_for_bulk' => true,
        'api_key_configured' => $configuredKey !== '',
        'authentication' => 'Authorization: Bearer <key> or X-API-Key: <key>',
        'note' => 'Unauthenticated callers may validate one address per request. A clean result confirms syntax and mail-capable DNS routing, not mailbox existence.',
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    apiRespond(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > MAIL_CLEANER_API_MAX_BODY) {
    apiRespond(['ok' => false, 'error' => 'request_too_large', 'message' => 'Request body is too large.'], 413);
}

$providedKey = providedApiKey();
$authenticated = $configuredKey !== '' && $providedKey !== '' && hash_equals($configuredKey, $providedKey);

// If a server-side key has been configured, an incorrect supplied key is always rejected.
if ($configuredKey !== '' && $providedKey !== '' && !$authenticated) {
    apiRespond(['ok' => false, 'error' => 'unauthorized', 'message' => 'The supplied API key is not valid.'], 401);
}

enforceRateLimit($authenticated);

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$payload = [];

if (str_contains($contentType, 'application/json')) {
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        apiRespond(['ok' => false, 'error' => 'empty_request', 'message' => 'Send an email or emails field.'], 400);
    }

    try {
        $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        apiRespond(['ok' => false, 'error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
    }

    if (!is_array($decoded)) {
        apiRespond(['ok' => false, 'error' => 'invalid_request', 'message' => 'The JSON body must be an object.'], 400);
    }
    $payload = $decoded;
} else {
    $payload = $_POST;
}

$cleaner = new EmailListCleaner();

if (array_key_exists('email', $payload)) {
    $email = is_scalar($payload['email']) ? (string)$payload['email'] : '';
    if (trim($email) === '') {
        apiRespond(['ok' => false, 'error' => 'empty_email', 'message' => 'The email field is empty.'], 400);
    }
    $result = $cleaner->cleanItems([$email]);
} elseif (array_key_exists('emails', $payload)) {
    // Bulk validation is restricted to authenticated server-to-server clients. This prevents
    // the public endpoint from being used as a high-volume DNS amplification/work service.
    if (!$authenticated) {
        apiRespond([
            'ok' => false,
            'error' => 'authentication_required',
            'message' => $configuredKey === ''
                ? 'Bulk API validation is disabled until MAIL_LIST_CLEANER_API_KEY is configured on the server.'
                : 'A valid API key is required for bulk validation.',
        ], 401);
    }

    $emails = $payload['emails'];

    if (is_array($emails)) {
        if (count($emails) > MAIL_CLEANER_API_MAX_EMAILS) {
            apiRespond(['ok' => false, 'error' => 'too_many_emails', 'message' => 'Too many email addresses in one request.'], 413);
        }
        $result = $cleaner->cleanItems(array_values($emails));
    } elseif (is_scalar($emails)) {
        $rawEmails = (string)$emails;
        $preflight = preg_split('/[\r\n,;]+/', $rawEmails, MAIL_CLEANER_API_MAX_EMAILS + 2) ?: [];
        if (count($preflight) > MAIL_CLEANER_API_MAX_EMAILS) {
            apiRespond(['ok' => false, 'error' => 'too_many_emails', 'message' => 'Too many email addresses in one request.'], 413);
        }
        $result = $cleaner->clean($rawEmails);
    } else {
        apiRespond(['ok' => false, 'error' => 'invalid_emails', 'message' => 'The emails field must be a string or array.'], 400);
    }
} else {
    apiRespond(['ok' => false, 'error' => 'missing_input', 'message' => 'Send either email or emails.'], 400);
}

apiRespond([
    'ok' => true,
    'summary' => $result['summary'],
    'results' => $result['rows'],
    'cleaned' => $result['cleaned'],
]);
