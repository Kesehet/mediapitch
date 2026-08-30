<?php
declare(strict_types=1);

function proxy_config(): array
{
    static $config;
    if ($config !== null) return $config;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) { http_response_code(503); exit('Ollama proxy is not configured.'); }
    $config = require $path;
    return $config;
}

function proxy_data_dir(): string
{
    $dir = dirname((string)proxy_config()['db_path']);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function proxy_log(string $message): void
{
    $dir = proxy_data_dir();
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX);
}

@ini_set('log_errors', '1');
@ini_set('error_log', proxy_data_dir() . '/error.log');
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        proxy_log('FATAL ' . ($e['message'] ?? 'unknown') . ' in ' . ($e['file'] ?? '?') . ':' . ($e['line'] ?? '?'));
    }
});

function proxy_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = proxy_config();
    $dir = proxy_data_dir();
    try {
        $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,api_key_hash TEXT NOT NULL UNIQUE,api_key_cipher TEXT NOT NULL,daily_request_limit INTEGER NOT NULL DEFAULT 100,is_active INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,last_login_at TEXT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS usage_logs (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,endpoint TEXT NOT NULL,model TEXT NULL,http_status INTEGER NOT NULL,request_bytes INTEGER NOT NULL DEFAULT 0,response_bytes INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_usage_user_created ON usage_logs(user_id, created_at)');
        return $pdo;
    } catch (Throwable $e) {
        proxy_log('DB ERROR: ' . $e->getMessage());
        throw $e;
    }
}

function proxy_app_key(): string
{
    $raw = (string)(proxy_config()['app_key'] ?? '');
    if ($raw === '') throw new RuntimeException('OLLAMA_PROXY_APP_KEY is not configured.');
    $decoded = base64_decode($raw, true);
    if ($decoded === false || strlen($decoded) !== 32) throw new RuntimeException('OLLAMA_PROXY_APP_KEY must be a base64-encoded 32-byte key.');
    return $decoded;
}

function proxy_encrypt(string $plain): string
{
    $key = proxy_app_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('OpenSSL encryption failed.');
        return 'openssl:' . base64_encode($iv . $tag . $cipher);
    }
    throw new RuntimeException('Neither Sodium nor OpenSSL encryption is available on this server.');
}

function proxy_decrypt(string $cipher): string
{
    try {
        $key = proxy_app_key();
        if (str_starts_with($cipher, 'sodium:')) {
            if (!function_exists('sodium_crypto_secretbox_open')) return '';
            $blob = base64_decode(substr($cipher, 7), true);
            if ($blob === false || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
            $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
            return $plain === false ? '' : $plain;
        }
        if (str_starts_with($cipher, 'openssl:')) {
            if (!function_exists('openssl_decrypt')) return '';
            $blob = base64_decode(substr($cipher, 8), true);
            if ($blob === false || strlen($blob) < 29) return '';
            $iv = substr($blob, 0, 12);
            $tag = substr($blob, 12, 16);
            $data = substr($blob, 28);
            $plain = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return $plain === false ? '' : $plain;
        }
        return '';
    } catch (Throwable $e) {
        proxy_log('DECRYPT ERROR: ' . $e->getMessage());
        return '';
    }
}

function proxy_generate_api_key(): string { return 'mp_oll_' . bin2hex(random_bytes(24)); }
function proxy_api_key_hash(string $key): string { return hash('sha256', $key); }
function proxy_start_session(): void { if (session_status() === PHP_SESSION_NONE) { session_name('mp_ollama_proxy'); session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>proxy_config()['base_path'].'/']); session_start(); } }
function proxy_csrf_token(): string { proxy_start_session(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function proxy_verify_csrf(): void { proxy_start_session(); $t=(string)($_POST['csrf']??''); if(!$t||!hash_equals((string)($_SESSION['csrf']??''),$t)){http_response_code(419);exit('Invalid request token.');} }
function proxy_current_user(): ?array { proxy_start_session(); $id=(int)($_SESSION['user_id']??0); if(!$id)return null; $s=proxy_db()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$s->execute([$id]);return $s->fetch()?:null; }
function proxy_find_user_by_api_key(string $key): ?array { $s=proxy_db()->prepare('SELECT * FROM users WHERE api_key_hash=? AND is_active=1');$s->execute([proxy_api_key_hash($key)]);return $s->fetch()?:null; }
function proxy_requests_today(int $userId): int { $s=proxy_db()->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id=? AND created_at>=datetime('now','start of day')");$s->execute([$userId]);return (int)$s->fetchColumn(); }
function proxy_select_upstream_key(int $userId): string { $keys=proxy_config()['upstream_keys']??[]; if(!$keys){http_response_code(503);echo json_encode(['error'=>'No upstream Ollama Cloud API key configured']);exit;} return $keys[$userId%count($keys)]; }
function proxy_log_usage(int $userId,string $endpoint,?string $model,int $status,int $requestBytes,int $responseBytes): void { $s=proxy_db()->prepare('INSERT INTO usage_logs (user_id,endpoint,model,http_status,request_bytes,response_bytes) VALUES (?,?,?,?,?,?)');$s->execute([$userId,$endpoint,$model,$status,$requestBytes,$responseBytes]); }
