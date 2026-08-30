<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
proxy_start_session();
$config = proxy_config();
$base = $config['base_path'];
$error = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    proxy_verify_csrf();
    if (!$config['registration_enabled']) {
        $error = 'New applications are currently closed.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 10) {
            $error = 'Password must be at least 10 characters.';
        } else {
            $apiKey = proxy_generate_api_key();
            try {
                $stmt = proxy_db()->prepare('INSERT INTO users (email,password_hash,api_key_hash,api_key_cipher,daily_request_limit,is_active) VALUES (?,?,?,?,?,0)');
                $stmt->execute([$email,password_hash($password,PASSWORD_DEFAULT),proxy_api_key_hash($apiKey),proxy_encrypt($apiKey),(int)$config['default_daily_request_limit']]);
                $submitted = true;
            } catch (PDOException $e) {
                $error = str_contains(strtolower($e->getMessage()), 'unique') ? 'An application already exists for that email address.' : 'Unable to submit the application.';
            }
        }
    }
}
function ph(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Apply - MediaPitch Ollama Proxy</title><style>body{font-family:system-ui;background:#f5f7fb;color:#17202a;margin:0}.card{max-width:460px;margin:70px auto;background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:24px}label{display:block;font-weight:650;margin:16px 0 6px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #cfd4dc;border-radius:8px}button{margin-top:18px;padding:11px 16px;border:0;border-radius:8px;background:#111827;color:#fff;font-weight:650}.muted{color:#667085}.error{background:#fff1f0;color:#9f1c16;padding:10px;border-radius:8px}.ok{background:#ecfdf3;color:#027a48;padding:12px;border-radius:8px}a{color:#3448c5;text-decoration:none}</style></head><body><div class="card"><h1>Apply for API access</h1><?php if($submitted):?><div class="ok"><strong>Application received.</strong><br>Your API key is reserved but remains disabled until MediaPitch approves your account.</div><p><a href="<?=ph($base)?>/login">Go to login</a></p><?php else:?><p class="muted">Create your account. Once approved, your dashboard will show your personal proxy key and usage allowance.</p><?php if($error):?><div class="error"><?=ph($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=ph(proxy_csrf_token())?>"><label>Email</label><input type="email" name="email" required autocomplete="email"><label>Password</label><input type="password" name="password" required minlength="10" autocomplete="new-password"><button>Submit application</button></form><p class="muted">Already approved? <a href="<?=ph($base)?>/login">Log in</a></p><?php endif;?></div></body></html>