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
$createdKey = $_SESSION['created_proxy_key'] ?? null;
$createdUser = $_SESSION['created_proxy_user'] ?? null;
unset($_SESSION['created_proxy_key'], $_SESSION['created_proxy_user']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    proxy_verify_csrf();
    if (isset($_POST['admin_password'])) {
        $expected = (string)($config['admin_password'] ?? '');
        if ($expected !== '' && hash_equals($expected, (string)$_POST['admin_password'])) {
            session_regenerate_id(true);
            $_SESSION['proxy_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $error = 'Invalid admin password.';
    } elseif (!empty($_SESSION['proxy_admin']) && ($_POST['form'] ?? '') === 'create_user') {
        $username = trim((string)($_POST['username'] ?? ''));
        $limit = max(0, (int)($_POST['daily_request_limit'] ?? ($config['default_daily_request_limit'] ?? 100)));
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            $error = 'Username must be 3-40 characters using letters, numbers, dot, dash or underscore.';
        } else {
            $apiKey = proxy_generate_api_key();
            try {
                $stmt = proxy_db()->prepare('INSERT INTO users (email,password_hash,api_key_hash,api_key_cipher,daily_request_limit,is_active) VALUES (?,?,?,?,?,1)');
                $stmt->execute([
                    proxy_username_storage($username),
                    password_hash($apiKey, PASSWORD_DEFAULT),
                    proxy_api_key_hash($apiKey),
                    proxy_encrypt($apiKey),
                    $limit,
                ]);
                $_SESSION['created_proxy_key'] = $apiKey;
                $_SESSION['created_proxy_user'] = $username;
                header('Location: admin.php?created=1');
                exit;
            } catch (PDOException $e) {
                proxy_log('CREATE USER ERROR: ' . $e->getMessage());
                $error = 'That username already exists or could not be created.';
            }
        }
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
$users = $admin ? proxy_db()->query("SELECT u.*, (SELECT COUNT(*) FROM usage_logs l WHERE l.user_id=u.id AND l.created_at>=datetime('now','+5 hours','+30 minutes','start of day','-5 hours','-30 minutes')) AS used_today FROM users u ORDER BY u.id DESC")->fetchAll() : [];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ollama Proxy Admin</title><style>body{font-family:system-ui;background:#f5f7fb;color:#17202a;margin:0}.wrap{max-width:1050px;margin:auto;padding:32px 18px}.card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:20px;margin-bottom:16px}.login{max-width:420px;margin:70px auto}input{padding:10px;border:1px solid #cfd4dc;border-radius:8px}input[type=password]{width:100%;margin:10px 0}button{padding:10px 14px;border:0;border-radius:8px;background:#111827;color:#fff;font-weight:650;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #eaecf0}.muted{color:#667085}.rowform{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.top{display:flex;justify-content:space-between;align-items:center}.ok{background:#ecfdf3;color:#027a48;padding:12px;border-radius:8px;margin-bottom:14px}.err{background:#fff1f0;color:#9f1c16;padding:12px;border-radius:8px;margin-bottom:14px}.key{font-family:monospace;word-break:break-all;background:#f2f4f7;padding:12px;border-radius:8px}.create{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.create label{display:flex;flex-direction:column;gap:6px;font-weight:650}.create input{min-width:220px}</style></head><body><div class="wrap">
<?php if(!$admin): ?><div class="card login"><h1>Proxy admin</h1><p class="muted">Create users, issue keys and manage usage limits.</p><?php if($error):?><div class="err"><?=ah($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=ah(proxy_csrf_token())?>"><input type="password" name="admin_password" placeholder="Admin password" required><button>Log in</button></form></div>
<?php else: ?><div class="top"><div><h1>Ollama Proxy Admin</h1><p class="muted">Admin-created accounts only. Times are shown in Kolkata time (IST).</p></div><a href="?logout=1">Log out</a></div>
<?php if($error):?><div class="err"><?=ah($error)?></div><?php endif;?>
<?php if($createdKey && $createdUser):?><div class="ok"><strong>User created: <?=ah($createdUser)?></strong><p>Give this key to the user. It is both their login password and their proxy API key.</p><div class="key"><?=ah($createdKey)?></div></div><?php endif;?>
<div class="card"><h2>Create user</h2><form method="post" class="create"><input type="hidden" name="csrf" value="<?=ah(proxy_csrf_token())?>"><input type="hidden" name="form" value="create_user"><label>Username<input type="text" name="username" placeholder="e.g. wasey" required pattern="[A-Za-z0-9._-]{3,40}"></label><label>Daily request limit<input type="number" name="daily_request_limit" min="0" value="<?= (int)($config['default_daily_request_limit'] ?? 100) ?>"></label><button>Create user + API key</button></form><p class="muted">Use 0 for unlimited. No email is required.</p></div>
<?php if(isset($_GET['saved'])):?><div class="ok">User updated.</div><?php endif;?><div class="card"><table><tr><th>Username</th><th>Today (IST)</th><th>Created (IST)</th><th>Controls</th></tr><?php foreach($users as $u):?><tr><td><?=ah(proxy_username_display((string)$u['email']))?></td><td><?=(int)$u['used_today']?> / <?=((int)$u['daily_request_limit'] ?: '∞')?></td><td><?=ah(proxy_format_ist((string)$u['created_at'], 'd M Y, h:i A'))?></td><td><form method="post" class="rowform"><input type="hidden" name="csrf" value="<?=ah(proxy_csrf_token())?>"><input type="hidden" name="user_id" value="<?=(int)$u['id']?>"><label><input type="checkbox" name="is_active" value="1" <?=((int)$u['is_active']?'checked':'')?>> Active</label><label>Daily limit <input type="number" name="daily_request_limit" min="0" value="<?=(int)$u['daily_request_limit']?>" style="width:90px"></label><button>Save</button></form></td></tr><?php endforeach;?></table></div><?php endif; ?></div></body></html>