# Bulk Mailer (PHP + MySQL)

A responsive bulk email sender with genuine concurrent SMTP delivery. Each user has their own account, settings, recipient file, campaign and background sending process, so many users can run campaigns at the same time without affecting one another.

- SMTP configuration (server, port, username, password, TLS: STARTTLS / SSL/TLS / None)
- Sender name and email, subject, HTML message with `{{field}}` personalization
- CSV / Excel (.xlsx, .ods) upload, streamed: 300k rows import in a few seconds
- Template preview against real rows from the file, plus test emails to one or more addresses
- **Send Limit** = number of emails sent at the same time (any value; no hardcoded cap)
- Live dashboard: totals, sent, failed, remaining, progress, in-flight emails, failed-email details with CSV export
- Start, Pause, Resume (optionally with a new Send Limit), Cancel

---

## Requirements

| Component | Version |
|---|---|
| PHP | **8.5** (tested on 8.5.12). Uses PHP 8.4/8.5 features, so 8.5 is the minimum. |
| MySQL | **9.7 LTS** (recommended for production) or **26.10 Innovation**. 8.4 LTS also works. |
| Dependencies | symfony/mime 8.1, openspout/openspout 5.13, egulias/email-validator 4 |
| OS | Linux server / VPS, Nginx or Apache with PHP-FPM, Composer 2 |

- PHP extensions: `openssl`, `mbstring`, `pdo_mysql`, `sodium`, `zip`, `xmlreader`, `dom`
- `proc_open` must be allowed (it is by default; some shared hosts disable it)
- MariaDB is not supported: the schema uses MySQL's `utf8mb4_0900_ai_ci` collation.

On Ubuntu 24.04, PHP 8.5 is available from the `ondrej/php` PPA:

```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
sudo apt install php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-zip
```

(`openssl`, `sodium` and `opcache` are built into these packages.) For MySQL 9.7 / 26.x, use Oracle's MySQL APT repository (dev.mysql.com/downloads/repo/apt).

## Installation

```bash
cd /var/www
# copy the project here, then:
cd bulk-mailer
composer install --no-dev --optimize-autoloader

cp .env.example .env
php bin/generate-key.php          # paste the output line into .env as APP_KEY
# edit the password at the bottom of database/schema.sql first: it creates the
# database, the users table and an app account with only SELECT/INSERT/UPDATE rights
mysql -u root -p < database/schema.sql
# put the same credentials in .env (DB_HOST=localhost uses the local socket)

# storage must be writable by the PHP-FPM user (e.g. www-data)
chown -R www-data:www-data storage
chmod -R 770 storage
chmod +x bin/spawn-worker.sh
```

Set `PHP_CLI_BINARY` in `.env` to the PHP command-line binary (`which php8.5`, e.g. `/usr/bin/php8.5`). Under PHP-FPM, PHP cannot detect this by itself.

Cleanup of expired temporary data (cron, as the web user):

```
0,15,30,45 * * * *  php /var/www/bulk-mailer/bin/cleanup.php >/dev/null 2>&1
```

### Nginx

```nginx
server {
    listen 80;
    server_name mailer.example.com;
    root /var/www/bulk-mailer/public;
    index index.php;
    client_max_body_size 8m;          # chunks are sized to PHP's own limits

    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_read_timeout 300;      # large-file import on the last chunk
    }
}
```

MySQL 9 removed the old `mysql_native_password` method; accounts use `caching_sha2_password`, which PHP's mysqlnd driver supports. Connecting with `DB_HOST=localhost` (Unix socket) avoids the extra key exchange that this method needs over unencrypted TCP. For a remote database, enable TLS on the MySQL server.

### Apache

Point the DocumentRoot at `public/`. The included `public/.htaccess` routes requests to `index.php` (needs `mod_rewrite`).

Use HTTPS in production: the session cookie is marked `Secure` automatically when the site is served over HTTPS.

### Quick local run

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

---

## How it works

### Concurrency: non-blocking SMTP connections, not a queue

The common PHP mail libraries (PHPMailer, Symfony Mailer) send one message at a time and block. libcurl's SMTP support also blocks while waiting for the server's final reply after `DATA`, so `curl_multi` silently becomes sequential. This was measured during development: 20 "parallel" cURL sends took 6.0 s, and 20 sends to a server that answers in 0.3 s gave 20 × 0.3 s.

So the app includes its own **non-blocking SMTP client** (`src/Mail/SmtpConnection.php`). Every phase (TCP connect, TLS handshake, EHLO, STARTTLS, AUTH PLAIN/LOGIN, MAIL/RCPT/DATA, body upload, final reply) is an explicit state, and all connections are driven by a single `stream_select()` loop.

`src/Sender/SlidingWindowSender.php` keeps a **sliding window** of N in-flight messages, where N is the user's Send Limit:

- N connections send N messages at the same time.
- The moment any message finishes, that connection immediately takes the next recipient. There is no wait-for-the-whole-batch and no queue system.
- Connections are reused for up to 100 messages, so TLS and login are not redone for every email.

Measured against a test server with 0.25 s latency per message: Send Limit 50 → ~9,400 emails/min, with exactly 50 messages in flight at the server. 100 test sends complete in 0.39 s.

### One background process per campaign

**Send Now** starts a detached CLI process (`bin/send-worker.php`) for that user only, via `bin/spawn-worker.sh`:

- No PHP-FPM worker is held while sending, and there is no `max_execution_time` limit.
- Closing the browser does not stop the campaign.
- The SMTP password reaches the worker through a pipe: never on the command line, never on disk.
- An exclusive lock (`worker.lock`) guarantees at most one worker per user.

### Per-user isolation

- Accounts live in MySQL (`users` table). Each login gets its own regenerated session, with CSRF protection on every write.
- Form settings are kept in the PHP **session** only (the SMTP password is encrypted with `APP_KEY`).
- Recipient data, progress and per-email results live in a per-user temporary folder `storage/tmp/u{id}/`. Paths are derived only from the logged-in user's id, never from request input.
- Temporary data is removed when the campaign finishes (recipient data immediately; results after `JOB_RETENTION_HOURS`), on logout, and by the cleanup cron.

### Server-wide limit (optional)

`SERVER_MAX_CONCURRENT_SENDS` in `.env` caps the total simultaneous sends across all users, to protect the server. Each user still chooses their own Send Limit. If the server is near the ceiling, a new campaign gets the remaining capacity, and the dashboard shows "server granted N". Set it to `0` for no ceiling.

### Reliability, retries and duplicates

| Situation | What happens |
|---|---|
| 4xx reply, timeout before the message was sent, dropped connection | Retried with exponential backoff + jitter (max retries configurable). The slot is not held while waiting. |
| 5xx for a recipient (e.g. 550 mailbox unknown) | Marked failed, with code and message, no retry |
| Login failure, TLS failure, sender rejected, server unreachable | Campaign **pauses automatically** with the reason shown. Fix it and click Resume. |
| 421 "too many connections" | Not counted as a failed attempt. The window shrinks to what the provider accepts, then grows again slowly. |
| Connection lost **after** the message body was fully sent | Marked **unknown**, never re-sent (the server may already have accepted it) |

Every recipient's state is written to an append-only log *before* sending (`sending`) and after it (`sent` / `failed` / `retry` / `unknown`). If the server reboots or the worker is killed, the dashboard shows **Interrupted**. Resume continues where it stopped, and messages that were mid-send are reported as "unknown" rather than sent twice. Duplicate addresses in the file are removed at import.

### Large files

Uploads are sent in chunks sized to the server's own PHP limits (`upload_max_filesize` / `post_max_size`), so no `php.ini` changes are needed. Files are parsed as a stream (OpenSpout), so memory stays flat. Measured: 300,000-row CSV in 3.1 s, 100,000-row XLSX in 4.3 s, including validation and de-duplication.

### Personalization

Every column becomes a field: `{{first_name}}`, `{{company}}`, and `{{email}}`. A fallback is written `{{first_name|there}}`. Values are HTML-escaped in the message body. The preview warns about placeholders that don't match any column, and shows the plain-text alternative that is sent alongside the HTML.

---

## Project structure

```
bin/
  send-worker.php       background sender (one per running campaign)
  spawn-worker.sh       detaches the worker from the web server
  cleanup.php           cron: removes expired temporary data
  generate-key.php      prints a new APP_KEY
database/schema.sql     users table
public/
  index.php             front controller / routes
  assets/               app.js, app.css, vendor/bootstrap
src/
  Auth/Auth.php                     register / login
  Http/                             Session, Request, Response, Guard, Controllers/
  Import/RecipientImporter.php      streaming CSV/XLSX import, validation, de-dup
  Mail/SmtpConnection.php           non-blocking SMTP client (state machine)
  Mail/MessageBuilder.php           MIME message (HTML + text) per recipient
  Mail/SmtpSettings.php
  Sender/SlidingWindowSender.php    concurrent engine, retries, pause/cancel
  Sender/WorkerLauncher.php         starts the background worker
  Sender/TestSender.php             test emails
  Storage/Workspace.php             per-user temporary files
  Storage/ServerCapacity.php        optional server-wide ceiling
  Support/CampaignConfig.php        session-only settings
  Template/Renderer.php             {{placeholder}} rendering
tools/fake_smtp.py      local test SMTP server with failure injection
views/                  login, register, main page
```

---

## Testing without sending real email

`tools/fake_smtp.py` is a local SMTP server that accepts mail without delivering it. It records peak concurrency and detects duplicates:

```bash
python3 tools/fake_smtp.py --port 2525 --user demo --password secret --delay 0.25 --stats stats.json
```

Configure the app with Server `127.0.0.1`, Port `2525`, TLS `None`, Username `demo`, Password `secret`.

Failures can be injected per address: `*fail550*@…` (permanent), `*temp451*@…` (temporary, succeeds on the 3rd try), `*slow*@…` (4 s), `*hang*@…` (never answers). `--max-conn 8` simulates a provider connection limit. With `--cert/--key` (and `--ssl-port`) it supports STARTTLS and implicit TLS.

---

## Practical limits and advice

- **Your SMTP provider decides real throughput.** Gmail and Outlook personal accounts allow only a few connections and a limited number of messages per day. For real bulk volume, use a provider built for it (Amazon SES, SendGrid, Mailgun, Postmark…), and set Send Limit and "Max emails per minute" to their published limits.
- Set up SPF, DKIM and DMARC for the From domain, and fill in the **Unsubscribe link** (Advanced settings), which adds the `List-Unsubscribe` header that major inboxes now expect from bulk senders.
- `stream_select()` handles up to about 1,000 sockets per process, so a single campaign's Send Limit should stay below ~1,000. Different users' campaigns run in separate processes.
- Only send to recipients who agreed to receive your email.
