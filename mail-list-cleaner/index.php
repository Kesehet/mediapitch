<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailListCleaner.php';

$result = null;
$raw = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = (string)($_POST['emails'] ?? '');

    if (isset($_FILES['email_file']) && is_array($_FILES['email_file']) && ($_FILES['email_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = (string)($_FILES['email_file']['tmp_name'] ?? '');
        $contents = $tmp !== '' ? @file_get_contents($tmp) : false;
        if ($contents !== false) {
            $raw = trim($raw . "\n" . $contents);
        } else {
            $error = 'Could not read the uploaded file.';
        }
    }

    if ($raw === '' && $error === '') {
        $error = 'Paste some email addresses or upload a text/CSV file.';
    }

    if ($raw !== '') {
        $cleaner = new EmailListCleaner();
        $result = $cleaner->clean($raw);

        if (isset($_POST['download']) && $_POST['download'] === '1') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="cleaned-emails.txt"');
            echo implode("\n", $result['cleaned']);
            exit;
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail List Cleaner</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f6f7f9; color: #1f2937; }
        .wrap { width: min(1100px, calc(100% - 32px)); margin: 40px auto; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 24px; margin-bottom: 20px; box-shadow: 0 3px 14px rgba(0,0,0,.04); }
        h1 { margin: 0 0 8px; font-size: 30px; }
        h2 { margin-top: 0; font-size: 20px; }
        p.muted { color: #6b7280; margin-top: 0; }
        textarea { width: 100%; min-height: 220px; resize: vertical; padding: 12px; border: 1px solid #d1d5db; border-radius: 10px; font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; }
        input[type=file] { margin: 12px 0; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        button { border: 0; border-radius: 9px; padding: 10px 16px; cursor: pointer; font-weight: 700; background: #111827; color: #fff; }
        button.secondary { background: #e5e7eb; color: #111827; }
        .stats { display: grid; grid-template-columns: repeat(6, minmax(100px, 1fr)); gap: 10px; }
        .stat { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; }
        .stat strong { display: block; font-size: 24px; }
        .stat span { color: #6b7280; font-size: 13px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { color: #4b5563; }
        .badge { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 700; }
        .clean { background: #dcfce7; color: #166534; }
        .risky { background: #fef3c7; color: #92400e; }
        .invalid { background: #fee2e2; color: #991b1b; }
        .error { background: #fee2e2; color: #991b1b; border-radius: 9px; padding: 10px 12px; margin-bottom: 12px; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        @media (max-width: 800px) { .stats { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Mail List Cleaner</h1>
        <p class="muted">Paste comma-, semicolon-, or newline-separated addresses. The tool normalizes, de-duplicates, validates syntax, checks DNS/MX, and flags role-based addresses. It does not send email.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <textarea name="emails" placeholder="alice@example.com&#10;bob@example.org, press@example.net"><?= e($raw) ?></textarea>
            <div><input type="file" name="email_file" accept=".txt,.csv,text/plain,text/csv"></div>
            <div class="actions">
                <button type="submit">Clean list</button>
                <?php if ($result !== null && count($result['cleaned']) > 0): ?>
                    <button type="submit" name="download" value="1" class="secondary">Download clean addresses</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2>Summary</h2>
            <div class="stats">
                <?php foreach ($result['summary'] as $label => $value): ?>
                    <div class="stat"><strong><?= (int)$value ?></strong><span><?= e(ucfirst($label)) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h2>Results</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Email</th><th>Status</th><th>Reason</th><th>Domain</th><th>MX</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $row): ?>
                        <tr>
                            <td><?= e((string)$row['email']) ?></td>
                            <td><span class="badge <?= e((string)$row['status']) ?>"><?= e(strtoupper((string)$row['status'])) ?></span></td>
                            <td><?= e((string)$row['reason']) ?></td>
                            <td><?= e((string)$row['domain']) ?></td>
                            <td><?= $row['mx'] ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Clean list</h2>
            <textarea readonly><?= e(implode("\n", $result['cleaned'])) ?></textarea>
            <p class="muted">“Clean” here means syntactically valid and attached to a mail-capable domain. It does <strong>not</strong> prove that the individual mailbox exists.</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
