<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
proxy_start_session();
if (empty($_SESSION['proxy_admin'])) {
    header('Location: ' . proxy_config()['base_path'] . '/admin');
    exit;
}
$logFile = proxy_data_dir() . '/error.log';
if (isset($_POST['clear'])) {
    proxy_verify_csrf();
    @file_put_contents($logFile, '');
    header('Location: logs');
    exit;
}
$contents = is_file($logFile) ? (string)file_get_contents($logFile) : 'No proxy errors logged yet.';
if (strlen($contents) > 200000) $contents = substr($contents, -200000);
$contents = proxy_logs_to_ist($contents);
function lh(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ollama Proxy Logs</title><style>body{font-family:system-ui;background:#f5f7fb;color:#17202a;margin:0}.wrap{max-width:1100px;margin:auto;padding:30px 18px}.top{display:flex;justify-content:space-between;align-items:center}.card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:20px}.log{background:#101828;color:#f2f4f7;padding:16px;border-radius:8px;white-space:pre-wrap;overflow:auto;max-height:70vh;font:13px/1.5 ui-monospace,monospace}button{padding:9px 13px;border:0;border-radius:8px;background:#111827;color:#fff}a{color:#3448c5;text-decoration:none}</style></head><body><div class="wrap"><div class="top"><div><h1>Ollama Proxy Logs</h1><p>Latest proxy errors and failover events. Times shown in Kolkata time (IST).</p></div><a href="admin">Back to admin</a></div><div class="card"><form method="post" style="margin-bottom:14px"><input type="hidden" name="csrf" value="<?=lh(proxy_csrf_token())?>"><button type="submit" name="clear" value="1">Clear log</button></form><div class="log"><?=lh($contents)?></div></div></div></body></html>