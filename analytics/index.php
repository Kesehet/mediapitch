<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

$baseDir = __DIR__;
$dbFile = $baseDir . DIRECTORY_SEPARATOR . 'database.sqlite';
$uploadDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads';
$exportDir = $baseDir . DIRECTORY_SEPARATOR . 'exports';
$backupDir = $baseDir . DIRECTORY_SEPARATOR . 'backups';
foreach ([$uploadDir, $exportDir, $backupDir] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            sku TEXT UNIQUE,
            isbn TEXT,
            mrp REAL DEFAULT 0,
            default_selling_price REAL DEFAULT 0,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS print_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL,
            batch_code TEXT,
            print_date TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            total_print_cost REAL NOT NULL,
            transport_cost REAL DEFAULT 0,
            other_cost REAL DEFAULT 0,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(book_id) REFERENCES books(id)
        );
        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            expense_date TEXT NOT NULL,
            expense_month TEXT,
            type TEXT NOT NULL,
            description TEXT,
            amount REAL NOT NULL,
            related_book_id INTEGER,
            source TEXT DEFAULT 'manual',
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(related_book_id) REFERENCES books(id)
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL,
            order_date TEXT NOT NULL,
            order_month TEXT,
            platform TEXT DEFAULT 'Amazon',
            book_id INTEGER,
            sku TEXT,
            quantity INTEGER DEFAULT 1,
            selling_price REAL DEFAULT 0,
            shipping_charged REAL DEFAULT 0,
            amazon_fee REAL DEFAULT 0,
            other_fee REAL DEFAULT 0,
            net_received REAL DEFAULT 0,
            status TEXT DEFAULT 'Sold',
            source TEXT DEFAULT 'manual',
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(book_id) REFERENCES books(id)
        );
        CREATE TABLE IF NOT EXISTS returns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL,
            return_date TEXT NOT NULL,
            return_month TEXT,
            book_id INTEGER,
            quantity INTEGER DEFAULT 1,
            refund_amount REAL DEFAULT 0,
            return_fee REAL DEFAULT 0,
            reason TEXT,
            condition_note TEXT,
            restockable INTEGER DEFAULT 0,
            source TEXT DEFAULT 'manual',
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(book_id) REFERENCES books(id)
        );
    ");
}
migrate($pdo);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $amount): string
{
    return 'Rs. ' . number_format((float)$amount, 2);
}

function month_from_date(string $date): string
{
    return substr($date, 0, 7);
}

function num(string $key): float
{
    return ($_POST[$key] ?? '') === '' ? 0.0 : (float)$_POST[$key];
}

function intv(string $key, int $default = 0): int
{
    return ($_POST[$key] ?? '') === '' ? $default : (int)$_POST[$key];
}

function strv(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function nullable_int(string $key): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : (int)$value;
}

function redirect(string $page, string $message): never
{
    header('Location: index.php?page=' . urlencode($page) . '&message=' . urlencode($message));
    exit;
}

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: [];
}

function scalar(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

function books(PDO $pdo): array
{
    return rows($pdo, 'SELECT * FROM books ORDER BY title');
}

function cost_subquery(): string
{
    return "SELECT book_id,
        SUM(total_print_cost + transport_cost + other_cost) AS total_printing_cost,
        SUM(quantity) AS total_quantity,
        CASE WHEN SUM(quantity) > 0 THEN SUM(total_print_cost + transport_cost + other_cost) / SUM(quantity) ELSE 0 END AS avg_cost
        FROM print_batches GROUP BY book_id";
}

function stock_report(PDO $pdo): array
{
    return rows($pdo, "
        SELECT b.id, b.title, b.sku,
            COALESCE(p.total_printed, 0) AS printed,
            COALESCE(o.total_sold, 0) AS sold,
            COALESCE(r.restockable_returns, 0) AS restockable_returns,
            COALESCE(p.total_printed, 0) - COALESCE(o.total_sold, 0) + COALESCE(r.restockable_returns, 0) AS stock,
            COALESCE(c.avg_cost, 0) AS avg_cost,
            (COALESCE(p.total_printed, 0) - COALESCE(o.total_sold, 0) + COALESCE(r.restockable_returns, 0)) * COALESCE(c.avg_cost, 0) AS stock_value
        FROM books b
        LEFT JOIN (SELECT book_id, SUM(quantity) AS total_printed FROM print_batches GROUP BY book_id) p ON p.book_id = b.id
        LEFT JOIN (SELECT book_id, SUM(quantity) AS total_sold FROM orders WHERE status NOT IN ('Cancelled', 'Refunded') GROUP BY book_id) o ON o.book_id = b.id
        LEFT JOIN (SELECT book_id, SUM(quantity) AS restockable_returns FROM returns WHERE restockable = 1 GROUP BY book_id) r ON r.book_id = b.id
        LEFT JOIN (" . cost_subquery() . ") c ON c.book_id = b.id
        ORDER BY stock ASC, b.title ASC
    ");
}

function book_report(PDO $pdo): array
{
    return rows($pdo, "
        SELECT b.id, b.title,
            COALESCE(p.total_printed, 0) AS printed,
            COALESCE(o.total_sold, 0) AS sold,
            COALESCE(rt.total_returned, 0) AS returned,
            COALESCE(st.stock, 0) AS stock,
            COALESCE(c.avg_cost, 0) AS avg_cost,
            COALESCE(o.net_received, 0) AS net_received,
            COALESCE(o.total_sold, 0) * COALESCE(c.avg_cost, 0) AS cogs,
            COALESCE(rt.refunds, 0) AS refunds,
            COALESCE(rt.return_fees, 0) AS return_fees,
            COALESCE(o.net_received, 0) - (COALESCE(o.total_sold, 0) * COALESCE(c.avg_cost, 0)) - COALESCE(rt.refunds, 0) - COALESCE(rt.return_fees, 0) AS profit
        FROM books b
        LEFT JOIN (SELECT book_id, SUM(quantity) AS total_printed FROM print_batches GROUP BY book_id) p ON p.book_id = b.id
        LEFT JOIN (SELECT book_id, SUM(quantity) AS total_sold, SUM(net_received) AS net_received FROM orders WHERE status NOT IN ('Cancelled', 'Refunded') GROUP BY book_id) o ON o.book_id = b.id
        LEFT JOIN (SELECT book_id, SUM(quantity) AS total_returned, SUM(refund_amount) AS refunds, SUM(return_fee) AS return_fees FROM returns GROUP BY book_id) rt ON rt.book_id = b.id
        LEFT JOIN (SELECT book_id, COALESCE(SUM(quantity),0) AS stock FROM returns WHERE restockable = 1 GROUP BY book_id) rr ON rr.book_id = b.id
        LEFT JOIN (" . cost_subquery() . ") c ON c.book_id = b.id
        LEFT JOIN (
            SELECT b2.id, COALESCE(p2.total_printed, 0) - COALESCE(o2.total_sold, 0) + COALESCE(r2.restockable_returns, 0) AS stock
            FROM books b2
            LEFT JOIN (SELECT book_id, SUM(quantity) AS total_printed FROM print_batches GROUP BY book_id) p2 ON p2.book_id = b2.id
            LEFT JOIN (SELECT book_id, SUM(quantity) AS total_sold FROM orders WHERE status NOT IN ('Cancelled', 'Refunded') GROUP BY book_id) o2 ON o2.book_id = b2.id
            LEFT JOIN (SELECT book_id, SUM(quantity) AS restockable_returns FROM returns WHERE restockable = 1 GROUP BY book_id) r2 ON r2.book_id = b2.id
        ) st ON st.id = b.id
        ORDER BY b.title
    ");
}

function monthly_report(PDO $pdo): array
{
    return rows($pdo, "
        WITH months AS (
            SELECT order_month AS month FROM orders WHERE order_month IS NOT NULL
            UNION SELECT substr(print_date, 1, 7) FROM print_batches
            UNION SELECT expense_month FROM expenses WHERE expense_month IS NOT NULL
            UNION SELECT return_month FROM returns WHERE return_month IS NOT NULL
        )
        SELECT m.month,
            COALESCE(o.orders_count, 0) AS orders_count,
            COALESCE(o.qty_sold, 0) AS qty_sold,
            COALESCE(o.net_received, 0) AS net_received,
            COALESCE(p.printing_cost, 0) AS printing_cost,
            COALESCE(e.other_expenses, 0) AS other_expenses,
            COALESCE(r.refunds, 0) AS refunds,
            COALESCE(r.return_fees, 0) AS return_fees,
            COALESCE(o.cogs, 0) AS cogs,
            COALESCE(o.net_received, 0) - COALESCE(p.printing_cost, 0) - COALESCE(e.other_expenses, 0) - COALESCE(r.refunds, 0) - COALESCE(r.return_fees, 0) AS cash_profit,
            COALESCE(o.net_received, 0) - COALESCE(o.cogs, 0) - COALESCE(e.other_expenses, 0) - COALESCE(r.refunds, 0) - COALESCE(r.return_fees, 0) AS operating_profit
        FROM months m
        LEFT JOIN (
            SELECT order_month, COUNT(*) AS orders_count, SUM(quantity) AS qty_sold, SUM(net_received) AS net_received, SUM(quantity * COALESCE(c.avg_cost, 0)) AS cogs
            FROM orders
            LEFT JOIN (" . cost_subquery() . ") c ON c.book_id = orders.book_id
            WHERE status NOT IN ('Cancelled', 'Refunded')
            GROUP BY order_month
        ) o ON o.order_month = m.month
        LEFT JOIN (SELECT substr(print_date, 1, 7) AS month, SUM(total_print_cost + transport_cost + other_cost) AS printing_cost FROM print_batches GROUP BY substr(print_date, 1, 7)) p ON p.month = m.month
        LEFT JOIN (SELECT expense_month, SUM(amount) AS other_expenses FROM expenses GROUP BY expense_month) e ON e.expense_month = m.month
        LEFT JOIN (SELECT return_month, SUM(refund_amount) AS refunds, SUM(return_fee) AS return_fees FROM returns GROUP BY return_month) r ON r.return_month = m.month
        WHERE m.month IS NOT NULL AND m.month <> ''
        ORDER BY m.month DESC
    ");
}

function export_csv(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    }
    fclose($out);
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
$message = $_GET['message'] ?? '';
$validPages = ['dashboard', 'books', 'print_batches', 'expenses', 'orders', 'returns', 'monthly_report', 'book_report', 'stock_report', 'backup'];
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_book') {
        if (strv('title') === '') {
            redirect('books', 'Book title is required.');
        }
        if (intv('id') > 0) {
            $stmt = $pdo->prepare('UPDATE books SET title=?, sku=NULLIF(?, ""), isbn=?, mrp=?, default_selling_price=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([strv('title'), strv('sku'), strv('isbn'), num('mrp'), num('default_selling_price'), strv('notes'), intv('id')]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO books (title, sku, isbn, mrp, default_selling_price, notes) VALUES (?, NULLIF(?, ""), ?, ?, ?, ?)');
            $stmt->execute([strv('title'), strv('sku'), strv('isbn'), num('mrp'), num('default_selling_price'), strv('notes')]);
        }
        redirect('books', 'Book saved.');
    }
    if ($action === 'save_batch') {
        if (nullable_int('book_id') === null || strv('print_date') === '' || intv('quantity') <= 0) {
            redirect('print_batches', 'Book, print date, and quantity are required.');
        }
        $stmt = $pdo->prepare('INSERT INTO print_batches (book_id, batch_code, print_date, quantity, total_print_cost, transport_cost, other_cost, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([intv('book_id'), strv('batch_code'), strv('print_date'), intv('quantity'), num('total_print_cost'), num('transport_cost'), num('other_cost'), strv('notes')]);
        redirect('print_batches', 'Print batch saved.');
    }
    if ($action === 'save_expense') {
        if (strv('expense_date') === '' || strv('type') === '' || num('amount') <= 0) {
            redirect('expenses', 'Expense date, type, and amount are required.');
        }
        $stmt = $pdo->prepare('INSERT INTO expenses (expense_date, expense_month, type, description, amount, related_book_id, source, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([strv('expense_date'), month_from_date(strv('expense_date')), strv('type'), strv('description'), num('amount'), nullable_int('related_book_id'), strv('source') ?: 'manual', strv('notes')]);
        redirect('expenses', 'Expense saved.');
    }
    if ($action === 'save_order') {
        if (strv('order_id') === '' || strv('order_date') === '') {
            redirect('orders', 'Order ID and date are required.');
        }
        $gross = num('selling_price') + num('shipping_charged');
        $fees = num('amazon_fee') + num('other_fee');
        $net = ($_POST['net_received'] ?? '') === '' ? $gross - $fees : num('net_received');
        $bookId = nullable_int('book_id');
        $sku = strv('sku');
        if ($sku === '' && $bookId) {
            $book = one($pdo, 'SELECT sku FROM books WHERE id = ?', [$bookId]);
            $sku = (string)($book['sku'] ?? '');
        }
        $stmt = $pdo->prepare('INSERT INTO orders (order_id, order_date, order_month, platform, book_id, sku, quantity, selling_price, shipping_charged, amazon_fee, other_fee, net_received, status, source, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([strv('order_id'), strv('order_date'), month_from_date(strv('order_date')), strv('platform') ?: 'Amazon', $bookId, $sku, max(1, intv('quantity', 1)), num('selling_price'), num('shipping_charged'), num('amazon_fee'), num('other_fee'), $net, strv('status') ?: 'Sold', 'manual', strv('notes')]);
        redirect('orders', 'Order saved.');
    }
    if ($action === 'save_return') {
        if (strv('order_id') === '' || strv('return_date') === '') {
            redirect('returns', 'Order ID and return date are required.');
        }
        $stmt = $pdo->prepare('INSERT INTO returns (order_id, return_date, return_month, book_id, quantity, refund_amount, return_fee, reason, condition_note, restockable, source, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([strv('order_id'), strv('return_date'), month_from_date(strv('return_date')), nullable_int('book_id'), max(1, intv('quantity', 1)), num('refund_amount'), num('return_fee'), strv('reason'), strv('condition_note'), isset($_POST['restockable']) ? 1 : 0, 'manual', strv('notes')]);
        $pdo->prepare("UPDATE orders SET status = 'Returned', updated_at = CURRENT_TIMESTAMP WHERE order_id = ?")->execute([strv('order_id')]);
        redirect('returns', 'Return saved.');
    }
}

if ($page === 'backup') {
    $backupFile = $backupDir . DIRECTORY_SEPARATOR . 'database_' . date('Ymd_His') . '.sqlite';
    copy($dbFile, $backupFile);
    redirect('dashboard', 'Backup created: ' . basename($backupFile));
}

if (($_GET['export'] ?? '') === 'csv') {
    if ($page === 'monthly_report') export_csv('monthly_report.csv', monthly_report($pdo));
    if ($page === 'book_report') export_csv('book_report.csv', book_report($pdo));
    if ($page === 'stock_report') export_csv('stock_report.csv', stock_report($pdo));
    if ($page === 'orders') export_csv('orders.csv', rows($pdo, 'SELECT * FROM orders ORDER BY order_date DESC, id DESC'));
    if ($page === 'expenses') export_csv('expenses.csv', rows($pdo, 'SELECT * FROM expenses ORDER BY expense_date DESC, id DESC'));
}

$allBooks = books($pdo);
$editBook = ($page === 'books' && isset($_GET['edit'])) ? one($pdo, 'SELECT * FROM books WHERE id = ?', [(int)$_GET['edit']]) : [];
$expenseTypes = ['Packaging', 'Amazon Ads', 'Courier', 'Design', 'Editing', 'ISBN', 'Review Copy', 'Software', 'Miscellaneous'];
$statuses = ['Sold', 'Returned', 'Cancelled', 'Refunded', 'Pending'];

$totalNet = scalar($pdo, "SELECT COALESCE(SUM(net_received),0) FROM orders WHERE status NOT IN ('Cancelled', 'Refunded')");
$totalPrinting = scalar($pdo, 'SELECT COALESCE(SUM(total_print_cost + transport_cost + other_cost),0) FROM print_batches');
$totalExpenses = scalar($pdo, 'SELECT COALESCE(SUM(amount),0) FROM expenses');
$totalRefunds = scalar($pdo, 'SELECT COALESCE(SUM(refund_amount),0) FROM returns');
$totalReturnFees = scalar($pdo, 'SELECT COALESCE(SUM(return_fee),0) FROM returns');
$totalOrders = scalar($pdo, 'SELECT COUNT(*) FROM orders');
$totalReturns = scalar($pdo, 'SELECT COUNT(*) FROM returns');
$stockRows = stock_report($pdo);
$stockValue = array_sum(array_map(fn($r) => (float)$r['stock_value'], $stockRows));
$bookRows = book_report($pdo);
$estimatedProfit = array_sum(array_map(fn($r) => (float)$r['profit'], $bookRows)) - $totalExpenses;
$monthlyRows = monthly_report($pdo);
$cashProfit = $totalNet - $totalPrinting - $totalExpenses - $totalRefunds - $totalReturnFees;

function book_select(array $allBooks, string $name, mixed $selected = ''): void
{
    echo '<select class="form-select" name="' . h($name) . '"><option value="">Choose book</option>';
    foreach ($allBooks as $book) {
        $isSelected = (string)$selected === (string)$book['id'] ? ' selected' : '';
        echo '<option value="' . h($book['id']) . '"' . $isSelected . '>' . h($book['title']) . '</option>';
    }
    echo '</select>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book Profit Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.css" rel="stylesheet">
    <style>
        body { background:#f6f7f9; }
        .navbar { box-shadow:0 1px 6px rgba(20,30,40,.08); }
        .kpi { border:0; border-radius:8px; box-shadow:0 1px 8px rgba(20,30,40,.06); }
        .kpi small { color:#667085; }
        .page-panel { background:#fff; border:1px solid #e7e9ee; border-radius:8px; padding:18px; }
        .table td, .table th { vertical-align:middle; }
        .profit-pos { color:#067647; }
        .profit-neg { color:#b42318; }
        textarea { min-height:42px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="index.php">Book Profit Tracker</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <div class="navbar-nav">
                <?php
                $nav = ['dashboard'=>'Dashboard','books'=>'Books','print_batches'=>'Print Batches','expenses'=>'Expenses','orders'=>'Orders','returns'=>'Returns','monthly_report'=>'Monthly','book_report'=>'Book Report','stock_report'=>'Stock','backup'=>'Backup'];
                foreach ($nav as $key => $label) {
                    $active = $page === $key ? ' active fw-semibold' : '';
                    echo '<a class="nav-link' . $active . '" href="index.php?page=' . h($key) . '">' . h($label) . '</a>';
                }
                ?>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid py-4">
    <?php if ($message): ?><div class="alert alert-info py-2"><?= h($message) ?></div><?php endif; ?>

    <?php if ($page === 'dashboard'): ?>
        <div class="row g-3 mb-4">
            <?php
            $cards = [
                'Total Net Received' => $totalNet,
                'Total Printing Cost' => $totalPrinting,
                'Total Other Expenses' => $totalExpenses,
                'Total Refunds' => $totalRefunds,
                'Total Return Fees' => $totalReturnFees,
                'Cash Profit/Loss' => $cashProfit,
                'Estimated Profit/Loss' => $estimatedProfit,
                'Estimated Stock Value' => $stockValue,
            ];
            foreach ($cards as $label => $value):
                $class = str_contains($label, 'Profit') ? ((float)$value >= 0 ? 'profit-pos' : 'profit-neg') : '';
            ?>
                <div class="col-12 col-sm-6 col-lg-3"><div class="card kpi"><div class="card-body"><small><?= h($label) ?></small><div class="h4 mb-0 <?= $class ?>"><?= money($value) ?></div></div></div></div>
            <?php endforeach; ?>
            <div class="col-12 col-sm-6 col-lg-3"><div class="card kpi"><div class="card-body"><small>Total Orders</small><div class="h4 mb-0"><?= (int)$totalOrders ?></div></div></div></div>
            <div class="col-12 col-sm-6 col-lg-3"><div class="card kpi"><div class="card-body"><small>Total Returns</small><div class="h4 mb-0"><?= (int)$totalReturns ?></div></div></div></div>
        </div>
        <div class="row g-3">
            <div class="col-lg-7"><div class="page-panel"><h5>Monthly Profit/Loss</h5><canvas id="monthlyChart" height="130"></canvas></div></div>
            <div class="col-lg-5"><div class="page-panel"><h5>Low Stock Books</h5><table class="table table-sm"><thead><tr><th>Book</th><th>Stock</th><th>Value</th></tr></thead><tbody><?php foreach (array_slice(array_filter($stockRows, fn($r) => (float)$r['stock'] <= 10), 0, 8) as $r): ?><tr><td><?= h($r['title']) ?></td><td><span class="badge text-bg-warning"><?= h($r['stock']) ?></span></td><td><?= money($r['stock_value']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
            <div class="col-lg-6"><div class="page-panel"><h5>Recent Orders</h5><?php $recentOrders = rows($pdo, 'SELECT o.*, b.title FROM orders o LEFT JOIN books b ON b.id=o.book_id ORDER BY o.order_date DESC, o.id DESC LIMIT 8'); ?><table class="table table-sm"><thead><tr><th>Date</th><th>Order</th><th>Book</th><th>Net</th></tr></thead><tbody><?php foreach ($recentOrders as $r): ?><tr><td><?= h($r['order_date']) ?></td><td><?= h($r['order_id']) ?></td><td><?= h($r['title'] ?: $r['sku']) ?></td><td><?= money($r['net_received']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
            <div class="col-lg-6"><div class="page-panel"><h5>Recent Expenses</h5><?php $recentExpenses = rows($pdo, 'SELECT * FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 8'); ?><table class="table table-sm"><thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Amount</th></tr></thead><tbody><?php foreach ($recentExpenses as $r): ?><tr><td><?= h($r['expense_date']) ?></td><td><?= h($r['type']) ?></td><td><?= h($r['description']) ?></td><td><?= money($r['amount']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        </div>
    <?php endif; ?>

    <?php if ($page === 'books'): ?>
        <div class="row g-3">
            <div class="col-lg-4"><div class="page-panel"><h5><?= $editBook ? 'Edit Book' : 'Add Book' ?></h5><form method="post"><input type="hidden" name="action" value="save_book"><input type="hidden" name="id" value="<?= h($editBook['id'] ?? '') ?>"><div class="mb-2"><label class="form-label">Title</label><input required class="form-control" name="title" value="<?= h($editBook['title'] ?? '') ?>"></div><div class="row"><div class="col mb-2"><label class="form-label">SKU</label><input class="form-control" name="sku" value="<?= h($editBook['sku'] ?? '') ?>"></div><div class="col mb-2"><label class="form-label">ISBN</label><input class="form-control" name="isbn" value="<?= h($editBook['isbn'] ?? '') ?>"></div></div><div class="row"><div class="col mb-2"><label class="form-label">MRP</label><input type="number" step="0.01" class="form-control" name="mrp" value="<?= h($editBook['mrp'] ?? '') ?>"></div><div class="col mb-2"><label class="form-label">Default Selling Price</label><input type="number" step="0.01" class="form-control" name="default_selling_price" value="<?= h($editBook['default_selling_price'] ?? '') ?>"></div></div><div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes"><?= h($editBook['notes'] ?? '') ?></textarea></div><button class="btn btn-primary">Save Book</button></form></div></div>
            <div class="col-lg-8"><div class="page-panel"><h5>Books</h5><table class="table data-table"><thead><tr><th>Title</th><th>SKU</th><th>ISBN</th><th>MRP</th><th>Selling Price</th><th></th></tr></thead><tbody><?php foreach ($allBooks as $r): ?><tr><td><?= h($r['title']) ?></td><td><?= h($r['sku']) ?></td><td><?= h($r['isbn']) ?></td><td><?= money($r['mrp']) ?></td><td><?= money($r['default_selling_price']) ?></td><td><a class="btn btn-sm btn-outline-secondary" href="index.php?page=books&edit=<?= h($r['id']) ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div></div>
        </div>
    <?php endif; ?>

    <?php if ($page === 'print_batches'): ?>
        <div class="page-panel mb-3"><h5>Add Print Batch</h5><form method="post" class="row g-2"><input type="hidden" name="action" value="save_batch"><div class="col-md-3"><?php book_select($allBooks, 'book_id'); ?></div><div class="col-md-2"><input class="form-control" name="batch_code" placeholder="Batch code"></div><div class="col-md-2"><input required type="date" class="form-control" name="print_date" value="<?= h(date('Y-m-d')) ?>"></div><div class="col-md-1"><input required type="number" class="form-control" name="quantity" placeholder="Qty"></div><div class="col-md-2"><input required type="number" step="0.01" class="form-control" name="total_print_cost" placeholder="Print cost"></div><div class="col-md-1"><input type="number" step="0.01" class="form-control" name="transport_cost" placeholder="Transport"></div><div class="col-md-1"><input type="number" step="0.01" class="form-control" name="other_cost" placeholder="Other"></div><div class="col-md-10"><input class="form-control" name="notes" placeholder="Notes"></div><div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div></form></div>
        <div class="page-panel"><h5>Printing Batches</h5><?php $batchRows = rows($pdo, 'SELECT p.*, b.title, (p.total_print_cost+p.transport_cost+p.other_cost) AS total_cost, CASE WHEN p.quantity>0 THEN (p.total_print_cost+p.transport_cost+p.other_cost)/p.quantity ELSE 0 END AS cost_per_book FROM print_batches p LEFT JOIN books b ON b.id=p.book_id ORDER BY p.print_date DESC, p.id DESC'); ?><table class="table data-table"><thead><tr><th>Date</th><th>Book</th><th>Batch</th><th>Qty</th><th>Total Cost</th><th>Cost/Book</th><th>Notes</th></tr></thead><tbody><?php foreach ($batchRows as $r): ?><tr><td><?= h($r['print_date']) ?></td><td><?= h($r['title']) ?></td><td><?= h($r['batch_code']) ?></td><td><?= h($r['quantity']) ?></td><td><?= money($r['total_cost']) ?></td><td><?= money($r['cost_per_book']) ?></td><td><?= h($r['notes']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if ($page === 'expenses'): ?>
        <div class="page-panel mb-3"><div class="d-flex justify-content-between"><h5>Add Expense</h5><a class="btn btn-sm btn-outline-secondary" href="index.php?page=expenses&export=csv">Export CSV</a></div><form method="post" class="row g-2"><input type="hidden" name="action" value="save_expense"><div class="col-md-2"><input required type="date" class="form-control" name="expense_date" value="<?= h(date('Y-m-d')) ?>"></div><div class="col-md-2"><select required class="form-select" name="type"><option value="">Type</option><?php foreach ($expenseTypes as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><input class="form-control" name="description" placeholder="Description"></div><div class="col-md-2"><input required type="number" step="0.01" class="form-control" name="amount" placeholder="Amount"></div><div class="col-md-2"><?php book_select($allBooks, 'related_book_id'); ?></div><div class="col-md-1"><input class="form-control" name="source" value="manual"></div><div class="col-md-10"><input class="form-control" name="notes" placeholder="Notes"></div><div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div></form></div>
        <div class="page-panel"><h5>Expenses</h5><?php $expenseRows = rows($pdo, 'SELECT e.*, b.title FROM expenses e LEFT JOIN books b ON b.id=e.related_book_id ORDER BY e.expense_date DESC, e.id DESC'); ?><table class="table data-table"><thead><tr><th>Date</th><th>Month</th><th>Type</th><th>Description</th><th>Book</th><th>Amount</th><th>Source</th></tr></thead><tbody><?php foreach ($expenseRows as $r): ?><tr><td><?= h($r['expense_date']) ?></td><td><?= h($r['expense_month']) ?></td><td><?= h($r['type']) ?></td><td><?= h($r['description']) ?></td><td><?= h($r['title']) ?></td><td><?= money($r['amount']) ?></td><td><?= h($r['source']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if ($page === 'orders'): ?>
        <div class="page-panel mb-3"><div class="d-flex justify-content-between"><h5>Add Order</h5><a class="btn btn-sm btn-outline-secondary" href="index.php?page=orders&export=csv">Export CSV</a></div><form method="post" class="row g-2"><input type="hidden" name="action" value="save_order"><div class="col-md-2"><input required class="form-control" name="order_id" placeholder="Order ID"></div><div class="col-md-2"><input required type="date" class="form-control" name="order_date" value="<?= h(date('Y-m-d')) ?>"></div><div class="col-md-2"><input class="form-control" name="platform" value="Amazon"></div><div class="col-md-2"><?php book_select($allBooks, 'book_id'); ?></div><div class="col-md-2"><input class="form-control" name="sku" placeholder="SKU"></div><div class="col-md-1"><input type="number" class="form-control" name="quantity" value="1"></div><div class="col-md-1"><select class="form-select" name="status"><?php foreach ($statuses as $s): ?><option><?= h($s) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="selling_price" placeholder="Selling price"></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="shipping_charged" placeholder="Shipping"></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="amazon_fee" placeholder="Amazon fee"></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="other_fee" placeholder="Other fee"></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="net_received" placeholder="Net received"></div><div class="col-md-10"><input class="form-control" name="notes" placeholder="Notes"></div><div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div></form></div>
        <div class="page-panel"><h5>Orders</h5><?php $orderRows = rows($pdo, 'SELECT o.*, b.title, CASE WHEN r.id IS NULL THEN 0 ELSE 1 END AS has_return FROM orders o LEFT JOIN books b ON b.id=o.book_id LEFT JOIN returns r ON r.order_id=o.order_id ORDER BY o.order_date DESC, o.id DESC'); ?><table class="table data-table"><thead><tr><th>Date</th><th>Order</th><th>Platform</th><th>Book</th><th>Qty</th><th>Net</th><th>Status</th><th>Returned?</th></tr></thead><tbody><?php foreach ($orderRows as $r): ?><tr><td><?= h($r['order_date']) ?></td><td><?= h($r['order_id']) ?></td><td><?= h($r['platform']) ?></td><td><?= h($r['title'] ?: $r['sku']) ?></td><td><?= h($r['quantity']) ?></td><td><?= money($r['net_received']) ?></td><td><?= h($r['status']) ?></td><td><?= $r['has_return'] ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if ($page === 'returns'): ?>
        <div class="page-panel mb-3"><h5>Add Return</h5><form method="post" class="row g-2"><input type="hidden" name="action" value="save_return"><div class="col-md-2"><input required class="form-control" name="order_id" placeholder="Order ID"></div><div class="col-md-2"><input required type="date" class="form-control" name="return_date" value="<?= h(date('Y-m-d')) ?>"></div><div class="col-md-2"><?php book_select($allBooks, 'book_id'); ?></div><div class="col-md-1"><input type="number" class="form-control" name="quantity" value="1"></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="refund_amount" placeholder="Refund"></div><div class="col-md-2"><input type="number" step="0.01" class="form-control" name="return_fee" placeholder="Return fee"></div><div class="col-md-1 form-check pt-2"><input class="form-check-input" type="checkbox" name="restockable" id="restockable"><label class="form-check-label" for="restockable">Restock</label></div><div class="col-md-3"><input class="form-control" name="reason" placeholder="Reason"></div><div class="col-md-3"><input class="form-control" name="condition_note" placeholder="Condition"></div><div class="col-md-4"><input class="form-control" name="notes" placeholder="Notes"></div><div class="col-md-2"><button class="btn btn-primary w-100">Save</button></div></form></div>
        <div class="page-panel"><h5>Returns</h5><?php $returnRows = rows($pdo, 'SELECT r.*, b.title, (r.refund_amount+r.return_fee) AS return_loss FROM returns r LEFT JOIN books b ON b.id=r.book_id ORDER BY r.return_date DESC, r.id DESC'); ?><table class="table data-table"><thead><tr><th>Date</th><th>Order</th><th>Book</th><th>Qty</th><th>Refund</th><th>Fee</th><th>Loss</th><th>Restockable</th><th>Reason</th></tr></thead><tbody><?php foreach ($returnRows as $r): ?><tr><td><?= h($r['return_date']) ?></td><td><?= h($r['order_id']) ?></td><td><?= h($r['title']) ?></td><td><?= h($r['quantity']) ?></td><td><?= money($r['refund_amount']) ?></td><td><?= money($r['return_fee']) ?></td><td><?= money($r['return_loss']) ?></td><td><?= $r['restockable'] ? 'Yes' : 'No' ?></td><td><?= h($r['reason']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if ($page === 'monthly_report'): ?>
        <div class="page-panel"><div class="d-flex justify-content-between"><h5>Monthly Report</h5><a class="btn btn-sm btn-outline-secondary" href="index.php?page=monthly_report&export=csv">Export CSV</a></div><table class="table data-table"><thead><tr><th>Month</th><th>Orders</th><th>Qty Sold</th><th>Net Received</th><th>Printing Paid</th><th>Other Expenses</th><th>Refunds</th><th>Return Fees</th><th>Cash P/L</th><th>COGS</th><th>Operating Profit</th></tr></thead><tbody><?php foreach ($monthlyRows as $r): ?><tr><td><?= h($r['month']) ?></td><td><?= h($r['orders_count']) ?></td><td><?= h($r['qty_sold']) ?></td><td><?= money($r['net_received']) ?></td><td><?= money($r['printing_cost']) ?></td><td><?= money($r['other_expenses']) ?></td><td><?= money($r['refunds']) ?></td><td><?= money($r['return_fees']) ?></td><td class="<?= (float)$r['cash_profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= money($r['cash_profit']) ?></td><td><?= money($r['cogs']) ?></td><td class="<?= (float)$r['operating_profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= money($r['operating_profit']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if ($page === 'book_report'): ?>
        <div class="page-panel"><div class="d-flex justify-content-between"><h5>Book-Wise Report</h5><a class="btn btn-sm btn-outline-secondary" href="index.php?page=book_report&export=csv">Export CSV</a></div><table class="table data-table"><thead><tr><th>Book</th><th>Printed</th><th>Sold</th><th>Returned</th><th>Stock</th><th>Avg Cost</th><th>Net Received</th><th>COGS</th><th>Estimated Profit</th></tr></thead><tbody><?php foreach ($bookRows as $r): ?><tr><td><?= h($r['title']) ?></td><td><?= h($r['printed']) ?></td><td><?= h($r['sold']) ?></td><td><?= h($r['returned']) ?></td><td><?= h($r['stock']) ?></td><td><?= money($r['avg_cost']) ?></td><td><?= money($r['net_received']) ?></td><td><?= money($r['cogs']) ?></td><td class="<?= (float)$r['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= money($r['profit']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if ($page === 'stock_report'): ?>
        <div class="page-panel"><div class="d-flex justify-content-between"><h5>Stock Report</h5><a class="btn btn-sm btn-outline-secondary" href="index.php?page=stock_report&export=csv">Export CSV</a></div><table class="table data-table"><thead><tr><th>Book</th><th>Printed</th><th>Sold</th><th>Restockable Returns</th><th>Stock</th><th>Avg Cost</th><th>Stock Value</th><th>Alert</th></tr></thead><tbody><?php foreach ($stockRows as $r): ?><tr><td><?= h($r['title']) ?></td><td><?= h($r['printed']) ?></td><td><?= h($r['sold']) ?></td><td><?= h($r['restockable_returns']) ?></td><td><?= h($r['stock']) ?></td><td><?= money($r['avg_cost']) ?></td><td><?= money($r['stock_value']) ?></td><td><?= (float)$r['stock'] <= 10 ? '<span class="badge text-bg-warning">Low Stock</span>' : '<span class="badge text-bg-success">OK</span>' ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.querySelectorAll('.data-table').forEach((table) => new DataTable(table, { pageLength: 25, order: [] }));
const chartEl = document.getElementById('monthlyChart');
if (chartEl) {
    const monthly = <?= json_encode(array_reverse(array_slice($monthlyRows, 0, 12)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    new Chart(chartEl, {
        type: 'bar',
        data: {
            labels: monthly.map(row => row.month),
            datasets: [
                { label: 'Cash P/L', data: monthly.map(row => Number(row.cash_profit)), backgroundColor: '#2e90fa' },
                { label: 'Operating Profit', data: monthly.map(row => Number(row.operating_profit)), backgroundColor: '#12b76a' }
            ]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
    });
}
</script>
</body>
</html>
