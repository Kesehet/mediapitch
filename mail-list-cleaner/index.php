<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailListCleaner.php';

const MAIL_CLEANER_WEB_MAX_FILE_BYTES = 2097152; // 2 MiB
const MAIL_CLEANER_WEB_MAX_EMAILS = 10000;

$result = null;
$raw = '';
$error = '';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return array<int,string> */
function splitPastedEmails(string $raw): array
{
    return array_values(preg_split('/[\r\n,;]+/', $raw) ?: []);
}

/** @return array<int,string> */
function readCsvEmailCandidates(string $path, EmailListCleaner $cleaner): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
        if (count($rows) > MAIL_CLEANER_WEB_MAX_EMAILS + 1) {
            break;
        }
    }
    fclose($handle);

    if ($rows === []) {
        return [];
    }

    $emailColumn = null;
    $header = $rows[0];
    foreach ($header as $index => $heading) {
        $normalized = strtolower(trim((string)$heading));
        $normalized = str_replace(['-', '_'], ' ', $normalized);
        if (in_array($normalized, ['email', 'e mail', 'email address', 'e mail address'], true)) {
            $emailColumn = (int)$index;
            break;
        }
    }

    $candidates = [];
    $start = $emailColumn === null ? 0 : 1;

    for ($i = $start, $count = count($rows); $i < $count; $i++) {
        $row = $rows[$i];

        if ($emailColumn !== null) {
            $candidate = $cleaner->extractAddress((string)($row[$emailColumn] ?? ''));
            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
            continue;
        }

        // No recognizable email header: scan cells and keep only fields that look email-like.
        foreach ($row as $cell) {
            $candidate = $cleaner->extractAddress((string)$cell);
            if ($candidate !== '' && str_contains($candidate, '@')) {
                $candidates[] = $candidate;
                break;
            }
        }
    }

    return $candidates;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = (string)($_POST['emails'] ?? '');

    if (strlen($raw) > MAIL_CLEANER_WEB_MAX_FILE_BYTES) {
        $error = 'Pasted input is too large. Please keep it under 2 MB.';
    }

    $cleaner = new EmailListCleaner();
    $items = $error === '' ? splitPastedEmails($raw) : [];

    if (
        $error === ''
        && isset($_FILES['email_file'])
        && is_array($_FILES['email_file'])
        && ($_FILES['email_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        $uploadError = (int)($_FILES['email_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        $fileSize = (int)($_FILES['email_file']['size'] ?? 0);
        $tmp = (string)($_FILES['email_file']['tmp_name'] ?? '');
        $name = strtolower((string)($_FILES['email_file']['name'] ?? ''));

        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE || $fileSize > MAIL_CLEANER_WEB_MAX_FILE_BYTES) {
            $error = 'Uploaded file is too large. The limit is 2 MB.';
        } elseif ($uploadError !== UPLOAD_ERR_OK || $tmp === '') {
            $error = 'Could not read the uploaded file.';
        } elseif (str_ends_with($name, '.csv')) {
            $items = array_merge($items, readCsvEmailCandidates($tmp, $cleaner));
        } else {
            $contents = @file_get_contents($tmp);
            if ($contents === false) {
                $error = 'Could not read the uploaded file.';
            } else {
                $items = array_merge($items, splitPastedEmails($contents));
            }
        }
    }

    $items = array_values(array_filter($items, static fn($item): bool => trim((string)$item) !== ''));

    if ($error === '' && $items === []) {
        $error = 'Paste some email addresses or upload a text/CSV file.';
    }

    if ($error === '' && count($items) > MAIL_CLEANER_WEB_MAX_EMAILS) {
        $error = 'This page can process up to 10,000 entries at a time. Split larger lists into batches.';
    }

    if ($error === '') {
        $result = $cleaner->cleanItems($items);

        if (isset($_POST['download']) && $_POST['download'] === '1') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="cleaned-emails.txt"');
            echo implode("\n", $result['cleaned']);
            exit;
        }
    }
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
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 10px; }
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
        .unknown { background: #e0e7ff; color: #3730a3; }
        .invalid { background: #fee2e2; color: #991b1b; }
        .error { background: #fee2e2; color: #991b1b; border-radius: 9px; padding: 10px 12px; margin-bottom: 12px; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        a { color: inherit; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Mail List Cleaner</h1>
        <p class="muted">Paste comma-, semicolon-, or newline-separated addresses, including copied forms such as <code>Jane Doe &lt;jane@example.com&gt;</code>. The tool de-duplicates, validates syntax and mail routing, caches DNS lookups, and flags role, disposable, and likely typo addresses. It does not send email.</p>

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
        <p class="muted">CSV files with an Email/Email Address column are detected automatically. Web limit: 10,000 entries or 2 MB. A JSON API is available at <a href="/mail-list-cleaner/api.php"><code>/mail-list-cleaner/api.php</code></a>.</p>
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
                    <tr><th>Email</th><th>Status</th><th>Reason</th><th>Domain</th><th>Mail routing</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $row): ?>
                        <tr>
                            <td><?= e((string)$row['email']) ?></td>
                            <td><span class="badge <?= e((string)$row['status']) ?>"><?= e(strtoupper((string)$row['status'])) ?></span></td>
                            <td><?= e((string)$row['reason']) ?></td>
                            <td><?= e((string)$row['domain']) ?></td>
                            <td><?= e((string)($row['mail_routing'] ?? ($row['mx'] ? 'MX' : 'None'))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Clean list</h2>
            <textarea readonly><?= e(implode("\n", $result['cleaned'])) ?></textarea>
            <p class="muted">“Clean” means the syntax is valid and the domain has usable mail routing with no risk flag detected. It does <strong>not</strong> prove that the individual mailbox exists. “Unknown” means the server could not make a reliable local determination.</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
