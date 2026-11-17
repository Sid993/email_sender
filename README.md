# Bulk Mailer (PHP + MySQL)

A responsive bulk email sender with genuine concurrent SMTP delivery. An admin uploads recipient files to the server and assigns each file to an employee. Each employee has their own account, settings and background sending process, so many people can send at the same time without affecting one another.

- SMTP configuration (server, port, username, password, TLS: STARTTLS / SSL/TLS / None)
- Sender name and email, subject, HTML message with `{{field}}` personalization
- **Shared files:** the admin uploads CSV / Excel (.xlsx, .ods) files to the server (streamed: 300k rows import in a few seconds) and assigns each one to an employee. The employee types the file name and clicks **Load File**: nothing is downloaded or re-uploaded.
- Template preview against real rows from the file, plus test emails to one or more addresses
- **Send Limit** = how many recipients one **Send Now** click processes, all at the same time. 1,000 recipients with a limit of 50: each click sends the next 50 (950 left, then 900…). Any value; no hardcoded cap.
- Live dashboard: totals, sent, failed, remaining, current batch, in-flight emails, failed-email details with CSV export
- Send Now, Pause, Resume (optionally with a new Send Limit), Cancel (stops the current batch only)

---

## Requirements

- Linux server / VPS
- PHP **8.2+** with extensions: `openssl`, `mbstring`, `pdo_mysql`, `sodium`, `zip`, `xmlreader`, `dom`
- `proc_open` must be allowed (it is by default; some shared hosts disable it)
- MySQL 8 or MariaDB 10.5+
- Composer
- Nginx or Apache (with PHP-FPM)

## Installation

```bash
cd /var/www
# copy the project here, then:
cd bulk-mailer
composer install --no-dev --optimize-autoloader

cp .env.example .env
php bin/generate-key.php          # paste the output line into .env as APP_KEY
mysql -u root -p < database/schema.sql
# create a DB user and put its credentials in .env (DB_HOST, DB_NAME, DB_USER, DB_PASS)

# storage must be writable by the PHP-FPM user (e.g. www-data)
chown -R www-data:www-data storage
chmod -R 770 storage
chmod +x bin/spawn-worker.sh

# register your own account on the website, then make it the admin:
php bin/make-admin.php you@company.com
```

Upgrading a 1.x installation: run `mysql -u root -p bulk_mailer < database/migrations/2.0-shared-files.sql` once, then `php bin/make-admin.php`. Recipient files that employees uploaded in 1.x are not carried over: upload them again from the admin page.

Set `PHP_CLI_BINARY` in `.env` to the PHP command-line binary (`which php`, e.g. `/usr/bin/php8.3`). Under PHP-FPM, PHP cannot detect this by itself.

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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 300;      # large-file import on the last chunk
    }
}
```

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

### Send Now sends one batch

Each click on **Send Now** takes the next `Send Limit` recipients of the loaded file that haven't been processed yet and sends them all at the same time, using the sliding window above. When every recipient of the batch has a final result (sent, or failed after its retries) the batch ends and the dashboard shows **Batch complete** with the remaining count. Click Send Now again for the next batch; change Send Limit first to make the next batch bigger or smaller.

- **Counts are cumulative** for the file: Successful, Failed and Remaining add up across all batches. "Total Count / Remaining Count" under Send Limit always reflects the file.
- **Pause / Resume** work inside a batch. Resume never goes past the batch size.
- **Cancel stops the current batch only.** In-flight emails finish; recipients the batch didn't reach go back to Remaining for the next Send Now.
- **No one is emailed twice.** Every log record carries its batch id, and the results log belongs to the file, so retries, crashes, logouts and reassignments never resend a recipient.
- A very large Send Limit is still capped at any moment by `SERVER_MAX_CONCURRENT_SENDS`; the batch keeps its full size and simply takes a little longer.

### Shared files: admin upload, employee access

- **Roles** live in MySQL (`users.role`: `admin` or `employee`). Everyone who registers is an employee; `php bin/make-admin.php <email>` makes someone admin (`--remove` to undo). Roles are checked on every request.
- **Admin page** (`/admin`, the "Manage files" link): upload several files at once, see each file's recipients and progress, assign it to one employee, delete it, download its skipped rows.
- Files are stored in `storage/shared/f{id}/` (outside the web root), parsed once at upload. Each folder holds the recipients, the file's columns and sample rows, and its own sending log.
- **Employees** type the file name under File Name and click **Load File**. The server checks the assignment every time. A name that isn't assigned to them gets the same reply as a name that doesn't exist, so nobody can discover other people's files.
- **Each file belongs to one employee at a time.** Progress belongs to the file: reassigning a file lets the new employee continue where it stopped. A file with a batch running or paused on it can't be reassigned or deleted until the batch ends.

### Per-user isolation

- Accounts live in MySQL (`users` table). Each login gets its own regenerated session, with CSRF protection on every write.
- Form settings are kept in the PHP **session** only (the SMTP password is encrypted with `APP_KEY`).
- The current batch's state lives in a per-user folder `storage/tmp/u{id}/`. Paths are derived only from the logged-in user's id, never from request input.
- That folder is removed on logout and by the cleanup cron. A file's progress is kept in its own folder until the admin deletes the file.

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

Admin uploads are sent in chunks sized to the server's own PHP limits (`upload_max_filesize` / `post_max_size`), so no `php.ini` changes are needed. Files are parsed as a stream (OpenSpout), so memory stays flat. Measured: 300,000-row CSV in 3.1 s, 100,000-row XLSX in 4.3 s, including validation and de-duplication.

### Personalization

Every column becomes a field: `{{first_name}}`, `{{company}}`, and `{{email}}`. A fallback is written `{{first_name|there}}`. Values are HTML-escaped in the message body. The preview warns about placeholders that don't match any column, and shows the plain-text alternative that is sent alongside the HTML.

---

## Project structure

```
bin/
  send-worker.php       background sender (one per running batch)
  spawn-worker.sh       detaches the worker from the web server
  cleanup.php           cron: removes expired temporary data
  generate-key.php      prints a new APP_KEY
  make-admin.php        gives an account the admin role
database/
  schema.sql            users and shared_files tables
  migrations/           upgrade from 1.x
public/
  index.php             front controller / routes
  assets/               app.js, admin.js, app.css, vendor/bootstrap
src/
  Auth/Auth.php                     register / login
  Http/                             Session, Request, Response, Guard, ChunkedUpload, Controllers/
  Http/Controllers/AdminController  admin: upload, assign, delete shared files
  Http/Controllers/FileController   employee: Load File
  Import/RecipientImporter.php      streaming CSV/XLSX import, validation, de-dup
  Mail/SmtpConnection.php           non-blocking SMTP client (state machine)
  Mail/MessageBuilder.php           MIME message (HTML + text) per recipient
  Mail/SmtpSettings.php
  Sender/SlidingWindowSender.php    concurrent engine, batches, retries, pause/cancel
  Sender/WorkerLauncher.php         starts the background worker
  Sender/TestSender.php             test emails
  Storage/Workspace.php             per-user batch state
  Storage/SharedFiles.php           shared files, assignment, per-file progress
  Storage/ServerCapacity.php        optional server-wide ceiling
  Support/CampaignConfig.php        session-only settings
  Template/Renderer.php             {{placeholder}} rendering
tools/fake_smtp.py      local test SMTP server with failure injection
views/                  login, register, main page, admin page
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
