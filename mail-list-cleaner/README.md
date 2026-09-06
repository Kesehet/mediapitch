# Mail List Cleaner

A dependency-free PHP utility for cleaning email lists inside the MediaPitch site.

## What it does

- Accepts newline-, comma-, or semicolon-separated email addresses.
- Accepts pasted text or uploaded `.txt` / `.csv` files.
- Trims and normalizes addresses.
- Removes duplicates case-insensitively.
- Validates email syntax with PHP's `FILTER_VALIDATE_EMAIL`.
- Checks whether the domain has MX records, with A/AAAA fallback.
- Flags common role-based addresses such as `info@`, `press@`, `sales@`, and `support@` as risky.
- Produces a clean newline-separated list that can be downloaded.

## Important limitation

The local checks do **not** prove that a specific mailbox exists. A result marked `clean` means the email syntax is valid and the domain appears capable of receiving email.

Mailbox-level verification can be added later as an optional second stage using SMTP probing or a third-party verification API. It should return an uncertain/unknown state when a receiving server blocks verification, uses catch-all behavior, greylisting, or anti-abuse controls.

## Usage

Deploy the repository normally and open:

`/mail-list-cleaner/`

No Composer packages or API keys are required for this first version.

## Server requirements

- PHP 8+
- DNS functions enabled (`getmxrr`, `checkdnsrr`)
- Outbound DNS resolution available
