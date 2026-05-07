# Book Profit Tracker

Lightweight PHP + SQLite tracker for book printing batches, orders, returns, expenses, stock, and estimated profit/loss.

## Files

- `index.php` - single-file app with schema setup, forms, reports, CSV export, and backup action.
- `database.sqlite` - created automatically on first browser load.
- `uploads/`, `exports/`, `backups/` - reserved folders for imports, generated files, and SQLite backups.

## Run

From XAMPP, open:

```text
http://localhost/mediapitch/analytics/index.php
```

The app has no login in version 1. Keep it local/private. If this is ever hosted publicly, move `database.sqlite` outside the public web root and add password protection.

## Backup

Use the `Backup` navigation item. It copies `database.sqlite` to:

```text
analytics/backups/database_YYYYMMDD_HHMMSS.sqlite
```

## Notes

- Printing cost is calculated from `print_batches`.
- Other expenses are stored in `expenses`.
- Stock uses: printed quantity minus sold quantity plus restockable returns.
- Estimated profit uses weighted average print cost per book, not FIFO costing.
