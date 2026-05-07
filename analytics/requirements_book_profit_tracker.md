# Book Profit Tracker — Requirements

## 1. Purpose

Build a very lightweight PHP + SQLite application to track book printing batches, expenses, orders, returns, and estimated profit/loss.

The app should start simple and work without any automation. Manual entry should be available first. CSV import and n8n/email automation can be added later.

The goal is to answer:

- How much money came in?
- How much was spent?
- Which books are profitable?
- Which orders were returned/refunded?
- What is the month-wise profit or loss?
- How much stock is available?
- Which book may need reprinting soon?

---

## 2. Core Philosophy

Keep the app small.

Initial version should use:

```text
PHP
SQLite
Bootstrap
No framework
No login in version 1
No complex ERP features
```

The app should be usable from a browser and should store everything in a local SQLite database.

---

## 3. Recommended File Structure

```text
analytics/
│
├── index.php              # Main app: dashboard, forms, reports, listings
├── database.sqlite        # SQLite database file
├── uploads/               # Future CSV uploads
│   └── .gitkeep
├── exports/               # Future CSV/report exports
│   └── .gitkeep
├── backups/               # Manual/automatic SQLite backups
│   └── .gitkeep
└── README.md              # Setup notes
```

Optional later:

```text
├── ingest.php             # Optional API endpoint for n8n/email automation
├── config.php             # Optional config: app name, secret key, timezone
└── assets/
    ├── style.css
    └── app.js
```

For version 1, try to keep almost everything inside `index.php`.

---

## 4. Main Modules

The app should contain these modules:

1. Dashboard
2. Books
3. Printing Batches
4. Expenses
5. Orders
6. Returns
7. Monthly Report
8. Book-wise Report
9. Stock Report
10. Backup/Export

Navigation can be handled with query parameters:

```text
index.php?page=dashboard
index.php?page=books
index.php?page=print_batches
index.php?page=expenses
index.php?page=orders
index.php?page=returns
index.php?page=monthly_report
index.php?page=book_report
index.php?page=stock_report
```

---

## 5. Database Tables

### 5.1 `books`

Stores master data for each book.

```sql
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
```

Fields:

| Field | Purpose |
|---|---|
| title | Book title |
| sku | Internal/Amazon SKU |
| isbn | ISBN if available |
| mrp | Printed MRP |
| default_selling_price | Usual selling price |
| notes | Any extra remarks |

---

### 5.2 `print_batches`

Stores each book printing batch.

```sql
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
```

Formula:

```text
Total Batch Cost = Total Print Cost + Transport Cost + Other Cost
Cost Per Book = Total Batch Cost / Quantity
```

Example:

```text
100 books printed
Printing bill = ₹9,200
Transport = ₹500
Other = ₹300

Total Batch Cost = 9,200 + 500 + 300 = ₹10,000
Cost Per Book = 10,000 / 100 = ₹100
```

Note: use PHP/SQL queries for calculated fields instead of generated columns to keep SQLite compatibility simple.

---

### 5.3 `expenses`

Stores common business expenses.

```sql
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
```

Expense types:

```text
Packaging
Amazon Ads
Courier
Design
Editing
ISBN
Review Copy
Software
Miscellaneous
```

Important rule:

Printing cost should primarily be entered in `print_batches`.

If printing is also added manually in `expenses`, reports may double-count it. So version 1 should calculate printing costs from `print_batches`, not from `expenses`.

---

### 5.4 `orders`

Stores book orders.

```sql
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
```

Recommended statuses:

```text
Sold
Returned
Cancelled
Refunded
Pending
```

Order formulas:

```text
Gross Revenue = Selling Price + Shipping Charged
Total Platform Fees = Amazon Fee + Other Fee
Net Received = Gross Revenue - Total Platform Fees
```

If Amazon settlement report gives final payout, use that as `net_received`.

---

### 5.5 `returns`

Stores returned/refunded orders.

```sql
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
```

Return formula:

```text
Return Loss = Refund Amount + Return Fee
```

Returned quantity should only be added back to stock if `restockable = 1`.

---

## 6. Main Calculations

### 6.1 Total Printed Quantity Per Book

```sql
SELECT book_id, SUM(quantity) AS total_printed
FROM print_batches
GROUP BY book_id;
```

Formula:

```text
Total Printed = Sum of all print batch quantities for that book
```

---

### 6.2 Total Sold Quantity Per Book

```sql
SELECT book_id, SUM(quantity) AS total_sold
FROM orders
WHERE status NOT IN ('Cancelled', 'Refunded')
GROUP BY book_id;
```

Formula:

```text
Total Sold = Sum of quantities from valid orders
```

---

### 6.3 Total Returned Quantity Per Book

```sql
SELECT book_id, SUM(quantity) AS total_returned
FROM returns
GROUP BY book_id;
```

Formula:

```text
Total Returned = Sum of returned quantities
```

---

### 6.4 Estimated Stock

Simple stock formula:

```text
Estimated Stock = Total Printed - Total Sold + Restockable Returns
```

SQL concept:

```sql
SELECT
    b.id,
    b.title,
    COALESCE(p.total_printed, 0) AS total_printed,
    COALESCE(o.total_sold, 0) AS total_sold,
    COALESCE(r.restockable_returns, 0) AS restockable_returns,
    COALESCE(p.total_printed, 0) - COALESCE(o.total_sold, 0) + COALESCE(r.restockable_returns, 0) AS estimated_stock
FROM books b
LEFT JOIN (
    SELECT book_id, SUM(quantity) AS total_printed
    FROM print_batches
    GROUP BY book_id
) p ON p.book_id = b.id
LEFT JOIN (
    SELECT book_id, SUM(quantity) AS total_sold
    FROM orders
    WHERE status NOT IN ('Cancelled', 'Refunded')
    GROUP BY book_id
) o ON o.book_id = b.id
LEFT JOIN (
    SELECT book_id, SUM(quantity) AS restockable_returns
    FROM returns
    WHERE restockable = 1
    GROUP BY book_id
) r ON r.book_id = b.id;
```

---

### 6.5 Average Cost Per Book

Weighted average formula:

```text
Average Cost Per Book = Total Cost of All Batches / Total Quantity Printed
```

SQL:

```sql
SELECT
    book_id,
    SUM(total_print_cost + transport_cost + other_cost) AS total_printing_cost,
    SUM(quantity) AS total_quantity,
    CASE
        WHEN SUM(quantity) > 0 THEN
            SUM(total_print_cost + transport_cost + other_cost) / SUM(quantity)
        ELSE 0
    END AS average_cost_per_book
FROM print_batches
GROUP BY book_id;
```

Use this for version 1.

Later, if accuracy is needed, use FIFO batch costing.

---

### 6.6 Estimated Cost of Goods Sold

Formula:

```text
COGS = Quantity Sold × Average Cost Per Book
```

---

### 6.7 Order-Level Estimated Profit

Simple formula:

```text
Order Profit = Net Received - (Quantity × Average Cost Per Book)
```

More complete future formula:

```text
Order Profit = Net Received - Book Cost - Allocated Packaging Cost - Allocated Ad Cost
```

For version 1, keep it simple.

---

### 6.8 Monthly Profit/Loss

There should be two views.

#### Cash View

```text
Cash Profit/Loss = Net Received - Printing Cost Paid - Other Expenses - Refunds - Return Fees
```

This tells whether cash came in or went out that month.

#### Estimated Business Profit View

```text
Estimated Profit = Net Received - COGS - Other Expenses - Refunds - Return Fees
```

This is better for business insight because unsold printed books are still inventory.

---

## 7. Dashboard Requirements

Dashboard cards:

```text
Total Net Received
Total Printing Cost
Total Other Expenses
Total Refunds
Total Return Fees
Cash Profit/Loss
Estimated Profit/Loss
Total Orders
Total Returns
Estimated Stock Value
```

Dashboard formulas:

```text
Total Net Received = SUM(orders.net_received)
Total Printing Cost = SUM(print_batches.total_print_cost + transport_cost + other_cost)
Total Other Expenses = SUM(expenses.amount)
Total Refunds = SUM(returns.refund_amount)
Total Return Fees = SUM(returns.return_fee)

Cash Profit/Loss =
Total Net Received - Total Printing Cost - Total Other Expenses - Total Refunds - Total Return Fees
```

Stock value:

```text
Estimated Stock Value = Estimated Stock × Average Cost Per Book
```

---

## 8. Pages

### 8.1 Dashboard Page

URL:

```text
index.php?page=dashboard
```

Show:

- KPI cards
- Monthly summary table
- Recent orders
- Recent expenses
- Low stock books

---

### 8.2 Books Page

URL:

```text
index.php?page=books
```

Features:

- List books
- Add new book
- Edit basic book details

Fields:

```text
Title
SKU
ISBN
MRP
Default Selling Price
Notes
```

---

### 8.3 Printing Batches Page

URL:

```text
index.php?page=print_batches
```

Features:

- Add print batch
- List all batches
- Show total batch cost
- Show cost per book
- Filter by book

Fields:

```text
Book
Batch Code
Print Date
Quantity
Total Print Cost
Transport Cost
Other Cost
Notes
```

---

### 8.4 Expenses Page

URL:

```text
index.php?page=expenses
```

Features:

- Add expense
- List expenses
- Filter by month/type/book

Fields:

```text
Expense Date
Type
Description
Amount
Related Book
Source
Notes
```

---

### 8.5 Orders Page

URL:

```text
index.php?page=orders
```

Features:

- Add order manually
- List orders
- Filter by platform/month/book/status
- Show whether order has return record

Fields:

```text
Order ID
Order Date
Platform
Book
SKU
Quantity
Selling Price
Shipping Charged
Amazon Fee
Other Fee
Net Received
Status
Notes
```

---

### 8.6 Returns Page

URL:

```text
index.php?page=returns
```

Features:

- Add return manually
- List returns
- Match return by Order ID
- Show return loss
- Mark whether returned book is restockable

Fields:

```text
Order ID
Return Date
Book
Quantity
Refund Amount
Return Fee
Reason
Condition Note
Restockable
Notes
```

---

### 8.7 Monthly Report Page

URL:

```text
index.php?page=monthly_report
```

Columns:

```text
Month
Orders
Qty Sold
Net Received
Printing Cost Paid
Other Expenses
Refunds
Return Fees
Cash Profit/Loss
Estimated COGS
Estimated Operating Profit
```

Formulas:

```text
Cash Profit/Loss = Net Received - Printing Cost Paid - Other Expenses - Refunds - Return Fees
Estimated Operating Profit = Net Received - Estimated COGS - Other Expenses - Refunds - Return Fees
```

---

### 8.8 Book-Wise Report Page

URL:

```text
index.php?page=book_report
```

Columns:

```text
Book
Total Printed
Total Sold
Total Returned
Estimated Stock
Average Cost Per Book
Net Received
Estimated COGS
Estimated Profit
```

Formula:

```text
Estimated Profit = Net Received - Estimated COGS - Refunds - Return Fees
```

---

### 8.9 Stock Report Page

URL:

```text
index.php?page=stock_report
```

Columns:

```text
Book
Printed
Sold
Restockable Returns
Estimated Stock
Average Cost
Stock Value
Suggested Reorder Alert
```

Formula:

```text
Stock Value = Estimated Stock × Average Cost Per Book
```

Suggested reorder rule:

```text
If Estimated Stock <= 10, show "Low Stock"
```

Later rule:

```text
If Estimated Stock < 30 days average sales, show "Reprint Soon"
```

---

## 9. Forms and Validation

All forms should:

- Use POST
- Use prepared statements
- Sanitize output with `htmlspecialchars`
- Validate required fields
- Convert empty numeric fields to 0
- Store dates in `YYYY-MM-DD` format
- Store month as `YYYY-MM`

PHP month helper:

```php
function month_from_date($date) {
    return substr($date, 0, 7);
}
```

Money formatting helper:

```php
function money($amount) {
    return '₹' . number_format((float)$amount, 2);
}
```

---

## 10. Security Requirements

Version 1 may be local/private, but still follow basics:

- Use SQLite prepared statements
- Escape all output
- Do not expose database file publicly if hosted
- If hosted online, move database outside public directory
- Add basic password protection before public deployment
- Add CSRF token later if forms are exposed online

If deployed on shared hosting:

```text
/public_html/book-tracker/index.php
/private/book-tracker/database.sqlite
```

---

## 11. Backup Requirements

Add a simple backup action:

```text
index.php?page=backup
```

It should copy:

```text
database.sqlite
```

to:

```text
backups/database_YYYYMMDD_HHMMSS.sqlite
```

Backup file name example:

```text
database_20260506_153000.sqlite
```

---

## 12. Export Requirements

Reports should be exportable as CSV later.

Initial export pages:

```text
Monthly Report CSV
Orders CSV
Expenses CSV
Book-wise Report CSV
Stock Report CSV
```

Use query format:

```text
index.php?page=monthly_report&export=csv
```

---

## 13. CSV Import Requirements — Later

Do not build in version 1 unless needed.

Later imports:

1. Amazon Order Report
2. Amazon Settlement Report
3. Amazon Returns Report
4. Manual CSV orders

Import flow:

```text
Upload CSV
Preview rows
Map columns
Import
Show imported/skipped count
```

Basic duplicate prevention:

```text
Do not import same order_id + sku twice
```

---

## 14. Optional n8n Integration — Later

n8n is optional. The app should work without it.

Later, n8n can send data to:

```text
ingest.php
```

Example endpoint:

```text
POST /ingest.php?key=SECRET_KEY
```

Supported JSON types:

```json
{ "type": "order" }
```

```json
{ "type": "expense" }
```

```json
{ "type": "return" }
```

Security:

```text
Reject request if secret key is missing or incorrect.
Validate all fields.
Prevent duplicate order entries.
```

---

## 15. Version 1 Scope

Build only:

- SQLite setup
- Books create/update/list
- Printing batch create/list
- Expense create/list
- Order create/list
- Return create/list
- Dashboard
- Monthly report
- Book-wise report
- Stock report
- Backup button

Do not build in version 1:

- Complex login
- CSV import
- n8n dependency
- Demand prediction
- FIFO costing
- Product images
- Full admin panel

---

## 16. Version 2 Scope

Add:

- CSV import
- CSV export
- Basic password login
- Better filtering
- Duplicate detection
- Optional `ingest.php`
- Low-stock alerts

---

## 17. Version 3 Scope

Add:

- Demand prediction
- Reprint suggestions
- Amazon ad ROI tracking
- Platform-wise profit
- FIFO batch costing
- Restockable/non-restockable return reporting
- Charts

---

## 18. Future Demand Prediction

Once order data is available, calculate:

```text
Average Daily Sales = Total Quantity Sold in Last 30 Days / 30
Days of Stock Left = Current Stock / Average Daily Sales
```

Simple reorder rule:

```text
If Days of Stock Left <= 20, show "Reprint Soon"
```

Suggested print quantity:

```text
Suggested Print Quantity = Average Daily Sales × Target Stock Days
```

Example:

```text
Average Daily Sales = 2 books/day
Target Stock Days = 60 days
Suggested Print Quantity = 2 × 60 = 120 books
```

---

## 19. Important Design Decision

Do not overbuild the first version.

The first version should answer only this:

```text
What did I print?
What did I sell?
What came back?
What did I spend?
Am I making profit or loss?
```

Once these answers are reliable, automation and prediction can be added safely.
