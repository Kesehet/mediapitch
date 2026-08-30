<?php
/**
 * Copy this file to config.php on the server. Do NOT commit config.php.
 * Prefer environment variables when your host supports them.
 */
return [
    'app_name' => 'MediaPitch Ollama Proxy',
    'base_path' => '/ollama-proxy',
    'db_path' => __DIR__ . '/data/proxy.sqlite',

    // Generate with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    'app_key' => getenv('OLLAMA_PROXY_APP_KEY') ?: 'CHANGE_ME_BASE64_32_BYTE_KEY',

    // Comma-separated Ollama Cloud API keys. They are never sent to proxy users.
    'upstream_keys' => array_values(array_filter(array_map('trim', explode(',', getenv('OLLAMA_PROXY_UPSTREAM_KEYS') ?: '')))),
    'upstream_base_url' => rtrim(getenv('OLLAMA_PROXY_UPSTREAM_BASE_URL') ?: 'https://ollama.com', '/'),

    // Default limit for newly registered users. 0 means unlimited.
    'default_daily_request_limit' => (int) (getenv('OLLAMA_PROXY_DAILY_REQUEST_LIMIT') ?: 100),

    // Registration can later be changed to an approval workflow without changing API keys.
    'registration_enabled' => filter_var(getenv('OLLAMA_PROXY_REGISTRATION_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];
