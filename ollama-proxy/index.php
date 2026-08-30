<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

proxy_start_session();
$config = proxy_config();
$base = $config['base_path'];
$action = (string) ($_GET['action'] ?? 'dashboard');
$error = '';
$notice = '';

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    header('Location: ' . $base . '/login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    proxy_verify_csrf();
    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'register') {
        if (!$config['registration_enabled']) {
            $error = 'New registrations are currently closed.';
        } else {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Enter a valid email address.';
            } elseif (strlen($password) < 10) {
                $error = 'Password must be at least 10 characters.';
            } else {
                $apiKey = proxy_generate_api_key();
                try {
                    $stmt = proxy_db()->prepare('INSERT INTO users (email, password_hash, api_key_hash, api_key_cipher, daily_request_limit) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        proxy_api_key_hash($apiKey),
                        proxy_encrypt($apiKey),
                        (int) $config['default_daily_request_limit'],
                    ]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) proxy_db()->lastInsertId();
                    header('Location: ' . $base . '/?welcome=1');
                    exit;
                } catch (PDOException $e) {
                    if (str_contains(strtolower($e->getMessage()), 'unique')) {
                        $error = 'An account already exists for that email address.';
                    } else {
                        throw $e;
                    }
                }
            }
        }
        $action = 'register';
    }

    if ($form === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = proxy_db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            usleep(300000);
            $error = 'Invalid email or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $update = proxy_db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
            $update->execute([(int) $user['id']]);
            header('Location: ' . $base . '/');
            exit;
        }
        $action = 'login';
    }
}

$user = proxy_current_user();
if (($action === 'login' || $action === 'register') && $user) {
    header('Location: ' . $base . '/');
    exit;
}
if (!$user && !in_array($action, ['login', 'register'], true)) {
    header('Location: ' . $base . '/login');
    exit;
}

$apiKey = $user ? proxy_decrypt((string) $user['api_key_cipher']) : '';
$usedToday = $user ? proxy_requests_today((int) $user['id']) : 0;
$recentUsage = [];
if ($user) {
    $stmt = proxy_db()->prepare('SELECT endpoint, model, http_status, created_at FROM usage_logs WHERE user_id = ? ORDER BY id DESC LIMIT 15');
    $stmt->execute([(int) $user['id']]);
    $recentUsage = $stmt->fetchAll();
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($config['app_name']) ?></title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#17202a;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.wrap{max-width:940px;margin:0 auto;padding:32px 18px}.top{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:28px}.brand{font-size:21px;font-weight:750;color:#111827}.muted{color:#667085}.card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:22px;box-shadow:0 2px 10px rgba(16,24,40,.04);margin-bottom:18px}.auth{max-width:460px;margin:60px auto}.auth h1{margin:0 0 8px}label{display:block;font-size:14px;font-weight:650;margin:16px 0 6px}input{width:100%;padding:12px;border:1px solid #cfd4dc;border-radius:9px;font-size:16px}button,.btn{display:inline-block;border:0;background:#111827;color:#fff;padding:11px 16px;border-radius:9px;font-weight:650;text-decoration:none;cursor:pointer;margin-top:18px}.link{color:#3448c5;text-decoration:none}.error{background:#fff1f0;color:#9f1c16;padding:11px 13px;border-radius:8px;margin:14px 0}.success{background:#ecfdf3;color:#027a48;padding:11px 13px;border-radius:8px;margin-bottom:18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all;background:#f2f4f7;padding:13px;border-radius:8px;border:1px solid #e4e7ec}.stat{font-size:28px;font-weight:760}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;overflow:auto;background:#101828;color:#f2f4f7;padding:16px;border-radius:10px;font-size:13px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eaecf0}th{color:#667085;font-weight:650}@media(max-width:700px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.wrap{padding-top:20px}}
</style>
</head>
<body><div class="wrap">
<?php if (!$user): ?>
<div class="auth card">
    <div class="brand">MediaPitch Ollama Proxy</div>
    <?php if ($action === 'register'): ?>
        <h1>Create account</h1><p class="muted">Register to receive your own MediaPitch proxy API key.</p>
        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(proxy_csrf_token()) ?>"><input type="hidden" name="form" value="register">
            <label>Email</label><input type="email" name="email" autocomplete="email" required>
            <label>Password</label><input type="password" name="password" autocomplete="new-password" minlength="10" required>
            <button type="submit">Create account</button>
        </form>
        <p class="muted">Already registered? <a class="link" href="<?= h($base) ?>/login">Log in</a></p>
    <?php else: ?>
        <h1>Log in</h1><p class="muted">Access your API key and usage.</p>
        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(proxy_csrf_token()) ?>"><input type="hidden" name="form" value="login">
            <label>Email</label><input type="email" name="email" autocomplete="email" required>
            <label>Password</label><input type="password" name="password" autocomplete="current-password" required>
            <button type="submit">Log in</button>
        </form>
        <?php if ($config['registration_enabled']): ?><p class="muted">Need access? <a class="link" href="<?= h($base) ?>/register">Create an account</a></p><?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="top"><div><div class="brand">MediaPitch Ollama Proxy</div><div class="muted"><?= h((string) $user['email']) ?></div></div><a class="link" href="<?= h($base) ?>/logout">Log out</a></div>
<?php if (isset($_GET['welcome'])): ?><div class="success">Your account and API key are ready.</div><?php endif; ?>
<div class="grid">
    <div class="card"><div class="muted">Requests today</div><div class="stat"><?= $usedToday ?><?= (int)$user['daily_request_limit'] > 0 ? ' / ' . (int)$user['daily_request_limit'] : '' ?></div></div>
    <div class="card"><div class="muted">Account status</div><div class="stat">Active</div></div>
</div>
<div class="card"><h2>Your API key</h2><p class="muted">Use this key instead of the real Ollama Cloud key. Keep it private.</p><div class="key"><?= h($apiKey) ?></div></div>
<div class="card"><h2>Quick test</h2><p class="muted">The proxy mirrors the common Ollama REST path, so point requests at MediaPitch and use your key as a Bearer token.</p><div class="code">curl <?= h('https://mediapitch.in' . $base . '/api/chat') ?> \
  -H "Authorization: Bearer <?= h($apiKey) ?>" \
  -H "Content-Type: application/json" \
  -d '{"model":"glm-5.3:cloud","messages":[{"role":"user","content":"Hello"}],"stream":false}'</div></div>
<div class="card"><h2>Recent usage</h2>
<?php if (!$recentUsage): ?><p class="muted">No API requests yet.</p><?php else: ?><table><thead><tr><th>Time (UTC)</th><th>Endpoint</th><th>Model</th><th>Status</th></tr></thead><tbody><?php foreach ($recentUsage as $row): ?><tr><td><?= h((string)$row['created_at']) ?></td><td>/api/<?= h((string)$row['endpoint']) ?></td><td><?= h((string)($row['model'] ?: '—')) ?></td><td><?= (int)$row['http_status'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php endif; ?>
</div></body></html>
