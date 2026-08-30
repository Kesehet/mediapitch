<?php
declare(strict_types=1);

function proxy_config(): array
{
    static $config;
    if ($config !== null) return $config;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) { http_response_code(503); exit('Ollama proxy is not configured. Copy config.example.php to config.php on the server.'); }
    $config = require $path;
    return $config;
}

function proxy_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $config = proxy_config();
    $dir = dirname($config['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,api_key_hash TEXT NOT NULL UNIQUE,api_key_cipher TEXT NOT NULL,daily_request_limit INTEGER NOT NULL DEFAULT 100,is_active INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,last_login_at TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS usage_logs (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,endpoint TEXT NOT NULL,model TEXT NULL,http_status INTEGER NOT NULL,request_bytes INTEGER NOT NULL DEFAULT 0,response_bytes INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_usage_user_created ON usage_logs(user_id, created_at)');
    return $pdo;
}

function proxy_app_key(): string { $decoded=base64_decode((string)proxy_config()['app_key'],true); if($decoded===false||strlen($decoded)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES){http_response_code(503);exit('Invalid OLLAMA_PROXY_APP_KEY configuration.');} return $decoded; }
function proxy_encrypt(string $plain): string { $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce.sodium_crypto_secretbox($plain,$nonce,proxy_app_key())); }
function proxy_decrypt(string $cipher): string { $blob=base64_decode($cipher,true); if($blob===false||strlen($blob)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return ''; $nonce=substr($blob,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $plain=sodium_crypto_secretbox_open(substr($blob,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,proxy_app_key()); return $plain===false?'':$plain; }
function proxy_generate_api_key(): string { return 'mp_oll_'.bin2hex(random_bytes(24)); }
function proxy_api_key_hash(string $key): string { return hash('sha256',$key); }
function proxy_start_session(): void { if(session_status()===PHP_SESSION_NONE){session_name('mp_ollama_proxy');session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>proxy_config()['base_path'].'/']);session_start();} }
function proxy_csrf_token(): string { proxy_start_session(); if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function proxy_verify_csrf(): void { proxy_start_session(); $t=(string)($_POST['csrf']??''); if(!$t||!hash_equals((string)($_SESSION['csrf']??''),$t)){http_response_code(419);exit('Invalid request token.');} }
function proxy_current_user(): ?array { proxy_start_session(); $id=(int)($_SESSION['user_id']??0); if(!$id)return null; $s=proxy_db()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$s->execute([$id]);return $s->fetch()?:null; }
function proxy_find_user_by_api_key(string $key): ?array { $s=proxy_db()->prepare('SELECT * FROM users WHERE api_key_hash=? AND is_active=1');$s->execute([proxy_api_key_hash($key)]);return $s->fetch()?:null; }
function proxy_requests_today(int $userId): int { $s=proxy_db()->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id=? AND created_at>=datetime('now','start of day')");$s->execute([$userId]);return (int)$s->fetchColumn(); }
function proxy_select_upstream_key(int $userId): string { $keys=proxy_config()['upstream_keys']??[]; if(!$keys){http_response_code(503);echo json_encode(['error'=>'No upstream Ollama Cloud API key configured']);exit;} return $keys[$userId%count($keys)]; }
function proxy_log_usage(int $userId,string $endpoint,?string $model,int $status,int $requestBytes,int $responseBytes): void { $s=proxy_db()->prepare('INSERT INTO usage_logs (user_id,endpoint,model,http_status,request_bytes,response_bytes) VALUES (?,?,?,?,?,?)');$s->execute([$userId,$endpoint,$model,$status,$requestBytes,$responseBytes]); }
