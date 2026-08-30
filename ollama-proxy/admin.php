<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
proxy_start_session();
$config = proxy_config();

if (isset($_GET['logout'])) {
    unset($_SESSION['proxy_admin']);
    header('Location: admin.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    proxy_verify_csrf();
    if (isset($_POST['admin_password'])) {
        $expected = (string)($config['admin_password'] ?? '');
        if ($expected !== '' && $expected !== 'CHANGE_ME_LONG_ADMIN_PASSWORD' && hash_equals($expected, (string)$_POST['admin_password'])) {
            session_regenerate_id(true);
            $_SESSION['proxy_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $error = 'Invalid admin password.';
    } elseif (!empty($_SESSION['proxy_admin']) && isset($_POST['user_id'])) {
        $id = (int)$_POST['user_id'];
        $active = isset($_POST['is_active']) ? 1 : 0;
        $limit = max(0, (int)($_POST['daily_request_limit'] ?? 0));
        $stmt = proxy_db()->prepare('UPDATE users SET is_active = ?, daily_request_limit = ? WHERE id = ?');
        $stmt->execute([$active, $limit, $id]);
        header('Location: admin.php?saved=1');
        exit;
    }
}

function ah(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$admin = !empty($_SESSION['proxy_admin']);
$users = $admin ? proxy_db()->query("SELECT u.*, (SELECT COUNT(*) FROM usage_logs l WHERE l.user_id=u.id AND l.created_at>=datetime('now','start of day')) AS used_today FROM users u ORDER BY u.id DESC")->fetchAll() : [];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ollama Proxy Admin</title><style>body{font-family:system-ui;background:#f5f7fb;color:#17202a;margin:0}.wrap{max-width:980px;margin:auto;padding:32px 18px}.card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:20px;margin-bottom:16px}.login{max-width:420px;margin:70px auto}input{padding:10px;border:1px solid #cfd4dc;border-radius:8px}input[type=password]{width:100%;margin:10px 0}button{padding:10px 14px;border:0;border-radius:8px;background:#111827;color:#fff;font-weight:650}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #eaecf0}.muted{color:#667085}.rowform{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.top{display:flex;justify-content:space-between;align-items:center}.ok{background:#ecfdf3;color:#027a48;padding:10px;border-radius:8px}</style></head><body><div class="wrap">
<?php if(!$admin): ?><div class="card login"><h1>Proxy admin</h1><p class="muted">Approve/disable users and set daily limits.</p><?php if($error):?><p><?=ah($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=ah(proxy_csrf_token())?>"><input type="password" name="admin_password" placeholder="Admin password" required><button>Log in</button></form></div>
<?php else: ?><div class="top"><div><h1>Ollama Proxy Admin</h1><p class="muted">Manage access and request allowances.</p></div><a href="?logout=1">Log out</a></div><?php if(isset($_GET['saved'])):?><div class="ok">User updated.</div><?php endif;?><div class="card"><table><tr><th>Email</th><th>Today</th><th>Created</th><th>Controls</th></tr><?php foreach($users as $u):?><tr><td><?=ah((string)$u['email'])?></td><td><?=(int)$u['used_today']?> / <?=((int)$u['daily_request_limit'] ?: '∞')?></td><td><?=ah((string)$u['created_at'])?></td><td><form method="post" class="rowform"><input type="hidden" name="csrf" value="<?=ah(proxy_csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>"><label><input type="checkbox" name="is_active" value="1" <?=((int)$u['is_active']?'checked':'')?>> Active</label><label>Daily limit <input type="number" name="daily_request_limit" min="0" value="<?=(int)$u['daily_request_limit']?>" style="width:90px"></label><button>Save</button></form></td></tr><?php endforeach;?></table></div><?php endif; ?></div></body></html>