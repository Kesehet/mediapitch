<?php
/**
 * Runtime configuration for MediaPitch Ollama Proxy.
 *
 * Supports both real server environment variables and a local .env file,
 * which is useful on shared hosting such as Hostinger.
 */

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        // Real server environment variables take precedence over .env.
        if (getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

return [
    'app_name' => 'MediaPitch Ollama Proxy',
    'base_path' => '/ollama-proxy',
    'db_path' => __DIR__ . '/data/proxy.sqlite',

    'app_key' => getenv('OLLAMA_PROXY_APP_KEY') ?: '',
    'upstream_keys' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('OLLAMA_PROXY_UPSTREAM_KEYS') ?: '')
    ))),
    'upstream_base_url' => rtrim(
        getenv('OLLAMA_PROXY_UPSTREAM_BASE_URL') ?: 'https://ollama.com',
        '/'
    ),
    'admin_password' => getenv('OLLAMA_PROXY_ADMIN_PASSWORD') ?: '',
    'default_daily_request_limit' => (int)(
        getenv('OLLAMA_PROXY_DAILY_REQUEST_LIMIT') ?: 100
    ),
    'registration_enabled' => filter_var(
        getenv('OLLAMA_PROXY_REGISTRATION_ENABLED') ?: 'true',
        FILTER_VALIDATE_BOOLEAN
    ),
];
