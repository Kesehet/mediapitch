# Mail List Cleaner

A dependency-free PHP utility for cleaning email lists inside the MediaPitch site, with a reusable JSON API for newsletter and CRM integrations.

## What it does

- Accepts newline-, comma-, or semicolon-separated email addresses.
- Accepts pasted text or uploaded `.txt` / `.csv` files.
- Detects common CSV columns such as `Email` and `Email Address`.
- Extracts addresses copied as `Name <person@example.com>` or `mailto:person@example.com`.
- De-duplicates addresses case-insensitively.
- Validates syntax with PHP's `FILTER_VALIDATE_EMAIL`.
- Converts internationalized domains to ASCII/punycode when PHP `intl` support is available.
- Checks mail-capable DNS routing:
  - usable MX
  - RFC 7505 Null MX
  - RFC 5321 A/AAAA fallback when no MX exists
- Caches DNS results per domain during each request, so a large Gmail/Outlook list does not repeat the same lookup for every address.
- Uses `UNKNOWN` when the server cannot make a reliable local determination.
- Flags common role-based addresses.
- Flags a starter set of disposable/temporary mail domains.
- Flags likely typos for major providers without silently rewriting the address.
- Produces a clean newline-separated list that can be downloaded.

## Statuses

- `clean`: syntax and mail routing passed and no configured risk flag was found.
- `risky`: the address is mail-capable but has a role/disposable/likely-typo flag.
- `invalid`: syntax is invalid or the domain has no usable mail route (including Null MX).
- `unknown`: the server could not reliably complete local validation.

A `clean` result **does not prove that the individual mailbox exists**. Mailbox-level verification requires a second stage and can still be uncertain because of catch-all domains, greylisting, anti-abuse controls, and servers that intentionally hide recipient validity.

## Web UI

Deploy the repository normally and open:

`/mail-list-cleaner/`

The browser utility accepts up to 10,000 entries per run and up to 2 MB of pasted/uploaded input.

## JSON API

Endpoint:

`POST https://mediapitch.in/mail-list-cleaner/api.php`

Accepted JSON bodies:

```json
{"email":"person@example.com"}
```

```json
{"emails":["one@example.com","two@example.com"]}
```

```json
{"emails":"one@example.com\ntwo@example.com"}
```

Example response:

```json
{
  "ok": true,
  "summary": {
    "input": 1,
    "unique": 1,
    "clean": 1,
    "risky": 0,
    "unknown": 0,
    "invalid": 0,
    "duplicates": 0
  },
  "results": [
    {
      "email": "person@example.org",
      "status": "clean",
      "reason": "Syntax and mail-routing checks passed",
      "domain": "example.org",
      "mx": true,
      "mail_routing": "MX",
      "role": false,
      "disposable": false,
      "suggestion": null,
      "flags": []
    }
  ],
  "cleaned": ["person@example.org"]
}
```

The API accepts at most 1,000 addresses and a 1 MB request body. It has lightweight per-IP rate limiting.

### API authentication

Set this environment variable on the MediaPitch host to require authentication:

```text
MAIL_LIST_CLEANER_API_KEY=<long-random-secret>
```

Clients can then send either:

```text
Authorization: Bearer <key>
```

or:

```text
X-API-Key: <key>
```

If `MAIL_LIST_CLEANER_API_KEY` is not configured, the API remains available with the lower unauthenticated rate limit.

Do not commit the real key to Git.

## Newsletter integration

The Fill Masjid and MediaPitch Store newsletter systems can call the API server-to-server with:

```text
EMAIL_VALIDATOR_API_URL=https://mediapitch.in/mail-list-cleaner/api.php
EMAIL_VALIDATOR_API_KEY=<same key if API authentication is enabled>
```

The newsletter integrations intentionally **fail open** if the validation service is unavailable after local syntax validation, so an API/DNS outage does not lose legitimate signups. Definitively `invalid` addresses are rejected. Likely provider typos return a correction prompt. Other `risky` addresses (for example role/disposable addresses) are currently allowed and can be reviewed or filtered later.

## Recommended test addresses

- `aaaa@example.com` → invalid, Null MX
- `info@gmail.com` → risky, role-based
- `hello@definitely-does-not-exist-93841.invalid` → invalid
- `Jane Doe <some-valid-address@gmail.com>` → address extracted and checked
- `person@gmial.com` → risky with a Gmail typo suggestion when the typo domain itself is mail-capable

## Server requirements

- PHP 8+
- DNS functions enabled (`getmxrr`, `checkdnsrr`)
- Outbound DNS resolution
- cURL on applications that call the JSON API
- PHP `intl` is recommended for internationalized domain support
