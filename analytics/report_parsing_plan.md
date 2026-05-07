# Amazon Report Parsing Plan

## Goal

Add a simple importer later that can read Amazon-exported Excel/CSV reports from a local folder and insert clean, non-duplicate data into the SQLite database used by the Book Profit Tracker.

This is not part of version 1. The current app should continue to work with manual entries first.

---

## Basic Approach

Keep downloaded Amazon reports inside a folder such as:

```text
analytics/imports/
```

Amazon may export files with different names depending on the report type, date range, and Seller Central format. We should not depend only on exact file names.

The importer should:

1. Scan the import folder.
2. Read supported Excel/CSV files.
3. Detect the report type using columns and, where useful, filename hints.
4. Normalize the rows into our internal format.
5. Insert or update records in SQLite.
6. Prevent duplicate rows from being inserted.
7. Record which files have already been processed.

Supported file formats can be:

```text
.xlsx
.xls
.csv
.txt
```

For PHP, Excel parsing can later use a library like PhpSpreadsheet. CSV/TXT can be parsed with native PHP functions.

---

## Suggested Folder Structure

```text
analytics/
|
|-- index.php
|-- database.sqlite
|-- imports/
|   |-- orders/
|   |-- settlements/
|   |-- returns/
|   |-- processed/
|   |-- failed/
|   `-- .gitkeep
|-- uploads/
|-- exports/
|-- backups/
```

Keeping separate folders is helpful but not mandatory. If we keep all files in one `imports/` folder, the importer should detect report type from the file contents.

---

## Amazon Reports To Parse

### 1. Orders Report

Usually downloaded from:

```text
Orders -> Order Reports
```

Possible report names:

```text
All Orders Report
Flat File Orders Report
Order Report
```

Common columns:

```text
order-id
purchase-date
sku
product-name
quantity-purchased
item-price
shipping-price
order-status
```

Mapping to SQLite `orders` table:

| Amazon Column | SQLite Field |
|---|---|
| order-id | order_id |
| purchase-date | order_date |
| sku | sku |
| product-name | notes or book matching helper |
| quantity-purchased | quantity |
| item-price | selling_price |
| shipping-price | shipping_charged |
| order-status | status |

The app should try to match `sku` to `books.sku`. If a matching book is found, set `book_id`. If not, keep the SKU and leave `book_id` empty for manual review.

---

### 2. Settlement / Transaction Report

Usually downloaded from:

```text
Payments -> Reports Repository
```

Possible report names:

```text
Transaction Report
Settlement Report
Date Range Report
```

Common columns:

```text
order-id
amount-type
amount-description
amount
posted-date
sku
```

Use this report to calculate:

```text
amazon_fee
other_fee
net_received
```

For each `order-id`, sum all settlement rows that belong to the order.

Suggested simple logic:

- Positive principal/item/shipping amounts increase revenue.
- Negative commission/closing fee/shipping chargeback amounts become Amazon/other fees.
- Final `net_received` can be the sum of all amount rows for that order.

Version 1 importer does not need perfect accounting treatment for every Amazon adjustment. It should focus on getting usable order-level net received and fees.

---

### 3. Returns Report

Usually downloaded from:

```text
Reports -> Fulfillment -> Customer Concessions -> Returns
```

or:

```text
Orders -> Manage Returns
```

Common columns:

```text
order-id
return-date
sku
quantity
refund-amount
reason
condition
```

Mapping to SQLite `returns` table:

| Amazon Column | SQLite Field |
|---|---|
| order-id | order_id |
| return-date | return_date |
| sku | book lookup by sku |
| quantity | quantity |
| refund-amount | refund_amount |
| reason | reason |
| condition | condition_note |

If `sku` matches a book, set `book_id`. Otherwise leave it empty for manual review.

---

## Duplicate Prevention

The importer must be idempotent. Running it twice on the same folder should not duplicate orders, settlement rows, or returns.

Recommended database additions for later:

```sql
CREATE TABLE IF NOT EXISTS import_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name TEXT NOT NULL,
    file_path TEXT,
    file_hash TEXT NOT NULL UNIQUE,
    report_type TEXT,
    imported_at TEXT DEFAULT CURRENT_TIMESTAMP,
    rows_seen INTEGER DEFAULT 0,
    rows_inserted INTEGER DEFAULT 0,
    rows_updated INTEGER DEFAULT 0,
    rows_skipped INTEGER DEFAULT 0,
    status TEXT DEFAULT 'imported',
    notes TEXT
);
```

Optional raw transaction table:

```sql
CREATE TABLE IF NOT EXISTS settlement_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id TEXT,
    posted_date TEXT,
    sku TEXT,
    amount_type TEXT,
    amount_description TEXT,
    amount REAL DEFAULT 0,
    source_file_hash TEXT,
    source_row_hash TEXT NOT NULL UNIQUE,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

Recommended unique keys:

```text
orders:
order_id + sku

returns:
order_id + sku + return_date + refund_amount

settlement_transactions:
source_row_hash

import_files:
file_hash
```

If the current schema is kept unchanged, duplicate prevention can still be handled in code by checking before insert.

---

## Import Flow

### Step 1: Scan Files

Look inside:

```text
analytics/imports/
```

Ignore:

```text
processed/
failed/
temporary files
already imported file hashes
```

### Step 2: Hash File

Calculate a file hash before reading:

```text
sha256(file contents)
```

If the hash already exists in `import_files`, skip the file.

### Step 3: Detect Report Type

Detection should use column names first.

Examples:

```text
order-id + purchase-date + quantity-purchased -> orders
order-id + amount-description + amount -> settlement
order-id + return-date + refund-amount -> returns
```

Filename can be used as a fallback hint only.

### Step 4: Normalize Rows

Normalize:

- dates to `YYYY-MM-DD`
- months to `YYYY-MM`
- empty numeric values to `0`
- SKUs by trimming whitespace
- order IDs by trimming whitespace
- amounts by removing commas/currency symbols

### Step 5: Insert Or Update SQLite

Orders:

- If `order_id + sku` does not exist, insert.
- If it exists, update useful fields such as status, quantity, selling price, shipping, and notes.

Settlements:

- Store raw rows if using `settlement_transactions`.
- Recalculate each affected order's `amazon_fee`, `other_fee`, and `net_received`.

Returns:

- If the same return key already exists, skip.
- Otherwise insert return and mark matching order as `Returned`.

### Step 6: Mark File Processed

After successful import:

- insert row in `import_files`
- move file to `imports/processed/`

If import fails:

- record the error
- move file to `imports/failed/`

---

## Manual Review

Some rows may not match an existing book because Amazon SKU and local SKU may differ.

Later we can add a review page:

```text
index.php?page=import_review
```

It should show:

- imported rows without `book_id`
- unknown SKUs
- duplicate/skipped rows
- rows with invalid dates or amounts

For version 2, a simple unknown SKU list is enough.

---

## Practical Import Strategy

Do not import ten years immediately.

Start with:

```text
Last 12 months
```

Recommended first import order:

1. Books are manually added first with correct SKUs.
2. Import Amazon Orders report.
3. Import Settlement/Transaction report.
4. Import Returns report.
5. Review unmatched SKUs.
6. Re-run reports.

After this is reliable, older month-wise or year-wise reports can be added.

---

## Important Notes

Amazon reports are not clean accounting data. They may include:

- duplicate transaction rows
- reserve balances
- reimbursements
- TCS/TDS
- SAFE-T claims
- promo rebates
- return reversals
- fee adjustments

The first parser should not try to perfectly model every Amazon accounting detail.

The first useful target is:

```text
orders + fees + net received + returns + no duplicate imports
```

Once that works reliably, advanced accounting treatment can be added later.
