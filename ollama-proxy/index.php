<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

proxy_start_session();
$config = proxy_config();
$base = $config['base_path'];
$action = (string) ($_GET['action'] ?? 'dashboard');
$error = '';

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    header('Location: ' . $base . '/login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    proxy_verify_csrf();
    if (($_POST['form'] ?? '') === 'login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $apiKey = (string)($_POST['password'] ?? '');
        $stmt = proxy_db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([proxy_username_storage($username)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($apiKey, (string)$user['password_hash'])) {
            usleep(300000);
            $error = 'Invalid username or API key.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            proxy_db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$user['id']]);
            header('Location: ' . $base . '/');
            exit;
        }
    }
}

$user = proxy_current_user();
if (!$user && $action !== 'login') { header('Location: ' . $base . '/login'); exit; }
if ($user && $action === 'login') { header('Location: ' . $base . '/'); exit; }

$apiKey = $user ? proxy_decrypt((string)$user['api_key_cipher']) : '';
$usedToday = $user ? proxy_requests_today((int)$user['id']) : 0;
$used24h = 0;
$recentUsage = [];
$hourlyUsage = array_fill(0, 24, 0);
$hourLabels = [];

if ($user) {
    $uid = (int)$user['id'];
    $stmt = proxy_db()->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND created_at >= datetime('now','-24 hours')");
    $stmt->execute([$uid]);
    $used24h = (int)$stmt->fetchColumn();

    $stmt = proxy_db()->prepare("SELECT created_at FROM usage_logs WHERE user_id = ? AND created_at >= datetime('now','-24 hours') ORDER BY created_at ASC");
    $stmt->execute([$uid]);
    $rows24 = $stmt->fetchAll();
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $startHour = $nowUtc->modify('-23 hours')->setTime((int)$nowUtc->modify('-23 hours')->format('H'), 0, 0);
    for ($i = 0; $i < 24; $i++) {
        $bucketUtc = $startHour->modify('+' . $i . ' hours');
        $hourLabels[$i] = $bucketUtc->setTimezone(proxy_ist_timezone())->format('H:00');
    }
    foreach ($rows24 as $r) {
        $dt = new DateTimeImmutable((string)$r['created_at'], new DateTimeZone('UTC'));
        $diff = (int)floor(($dt->getTimestamp() - $startHour->getTimestamp()) / 3600);
        if ($diff >= 0 && $diff < 24) $hourlyUsage[$diff]++;
    }

    $stmt = proxy_db()->prepare('SELECT endpoint, model, http_status, created_at FROM usage_logs WHERE user_id = ? ORDER BY id DESC LIMIT 25');
    $stmt->execute([$uid]);
    $recentUsage = $stmt->fetchAll();
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= h($config['app_name']) ?></title><style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#17202a;background:#f5f7fb}*{box-sizing:border-box}body{margin:0}.wrap{max-width:980px;margin:0 auto;padding:32px 18px}.top{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:28px}.brand{font-size:21px;font-weight:750;color:#111827}.muted{color:#667085}.card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:22px;box-shadow:0 2px 10px rgba(16,24,40,.04);margin-bottom:18px}.auth{max-width:460px;margin:60px auto}.auth h1{margin:0 0 8px}label{display:block;font-size:14px;font-weight:650;margin:16px 0 6px}input{width:100%;padding:12px;border:1px solid #cfd4dc;border-radius:9px;font-size:16px}button{display:inline-block;border:0;background:#111827;color:#fff;padding:11px 16px;border-radius:9px;font-weight:650;cursor:pointer;margin-top:18px}.link{color:#3448c5;text-decoration:none}.error{background:#fff1f0;color:#9f1c16;padding:11px 13px;border-radius:8px;margin:14px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.key{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all;background:#f2f4f7;padding:13px;border-radius:8px;border:1px solid #e4e7ec}.stat{font-size:28px;font-weight:760}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;overflow:auto;background:#101828;color:#f2f4f7;padding:16px;border-radius:10px;font-size:13px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eaecf0}th{color:#667085;font-weight:650}.chart-wrap{height:260px;display:flex;align-items:flex-end;gap:5px;padding-top:18px;border-bottom:1px solid #d0d5dd}.bar-col{flex:1;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;min-width:0}.bar{width:75%;background:#344054;border-radius:4px 4px 0 0;min-height:2px;position:relative}.bar:hover:after{content:attr(data-count) ' calls';position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#101828;color:#fff;padding:4px 6px;border-radius:5px;font-size:11px;white-space:nowrap;margin-bottom:5px}.xlab{font-size:10px;color:#667085;margin-top:6px;transform:rotate(-45deg);transform-origin:top center;white-space:nowrap;height:34px}@media(max-width:700px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.wrap{padding-top:20px}.chart-wrap{gap:2px}.xlab{font-size:8px}}
</style></head><body><div class="wrap">
<?php if (!$user): ?>
<div class="auth card"><div class="brand">MediaPitch Ollama Proxy</div><h1>Log in</h1><p class="muted">Use the username issued by the admin. Your API key is also your password.</p><?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= h(proxy_csrf_token()) ?>"><input type="hidden" name="form" value="login"><label>Username</label><input type="text" name="username" autocomplete="username" required><label>API key / password</label><input type="password" name="password" autocomplete="current-password" required><button type="submit">Log in</button></form></div>
<?php else: ?>
<div class="top"><div><div class="brand">MediaPitch Ollama Proxy</div><div class="muted"><?= h(proxy_username_display((string)$user['email'])) ?></div></div><a class="link" href="<?= h($base) ?>/logout">Log out</a></div>
<div class="grid"><div class="card"><div class="muted">Requests today (IST)</div><div class="stat"><?= $usedToday ?><?= (int)$user['daily_request_limit'] > 0 ? ' / ' . (int)$user['daily_request_limit'] : '' ?></div></div><div class="card"><div class="muted">Calls in past 24 hours</div><div class="stat"><?= $used24h ?></div></div><div class="card"><div class="muted">Account status</div><div class="stat">Active</div></div></div>
<div class="card"><h2>Usage — past 24 hours</h2><p class="muted">Hourly API calls, shown in Kolkata time (IST). Hover a bar for the exact count.</p><?php $maxUsage=max(1,max($hourlyUsage)); ?><div class="chart-wrap"><?php foreach($hourlyUsage as $i=>$count): $height=max(2,(int)round(($count/$maxUsage)*190)); ?><div class="bar-col"><div class="bar" data-count="<?=$count?>" style="height:<?=$height?>px"></div><div class="xlab"><?=h($hourLabels[$i]??'')?></div></div><?php endforeach;?></div></div>
<div class="card"><h2>Your API key</h2><p class="muted">This is also your login password. Use it as the Bearer token for proxy requests.</p><div class="key"><?= h($apiKey) ?></div></div>
<div class="card"><h2>Quick test</h2><div class="code">curl <?= h('https://mediapitch.in' . $base . '/api/chat') ?> \
  -H "Authorization: Bearer <?= h($apiKey) ?>" \
  -H "Content-Type: application/json" \
  -d '{"model":"glm-5.3:cloud","messages":[{"role":"user","content":"Hello"}],"stream":false}'</div></div>
<div class="card"><h2>Recent usage</h2><?php if (!$recentUsage): ?><p class="muted">No API requests yet.</p><?php else: ?><table><thead><tr><th>Time (IST)</th><th>Endpoint</th><th>Model</th><th>Status</th></tr></thead><tbody><?php foreach ($recentUsage as $row): ?><tr><td><?= h(proxy_format_ist((string)$row['created_at'])) ?></td><td>/api/<?= h((string)$row['endpoint']) ?></td><td><?= h((string)($row['model'] ?: '—')) ?></td><td><?= (int)$row['http_status'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php endif; ?></div></body></html>