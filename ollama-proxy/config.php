<?php
/**
 * Runtime configuration. Secrets are read from the hosting environment only;
 * never put real Ollama keys in this repository.
 */
return [
    'app_name' => 'MediaPitch Ollama Proxy',
    'base_path' => '/ollama-proxy',
    'db_path' => __DIR__ . '/data/proxy.sqlite',
    'app_key' => getenv('OLLAMA_PROXY_APP_KEY') ?: '',
    'upstream_keys' => array_values(array_filter(array_map('trim', explode(',', getenv('OLLAMA_PROXY_UPSTREAM_KEYS') ?: '')))),
    'upstream_base_url' => rtrim(getenv('OLLAMA_PROXY_UPSTREAM_BASE_URL') ?: 'https://ollama.com', '/'),
    'admin_password' => getenv('OLLAMA_PROXY_ADMIN_PASSWORD') ?: '',
    'default_daily_request_limit' => (int)(getenv('OLLAMA_PROXY_DAILY_REQUEST_LIMIT') ?: 100),
    'registration_enabled' => filter_var(getenv('OLLAMA_PROXY_REGISTRATION_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];
