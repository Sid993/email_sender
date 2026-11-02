<?php

declare(strict_types=1);

namespace App\Sender;

use App\Mail\MessageBuilder;
use App\Mail\SmtpConnection;
use App\Mail\SmtpSettings;
use App\Storage\Workspace;

/**
 * Genuine concurrent sending: a sliding window of non-blocking SMTP connections.
 *
 * - Up to N messages are in flight at once (N = the user's Send Limit), each on its
 *   own SMTP connection, all driven by one stream_select() event loop.
 * - The moment ANY message finishes, that connection takes the next recipient, so the
 *   window stays full. There is no wait-for-the-whole-batch and no queue system.
 * - Connections are reused for many messages (no reconnect / re-login per email).
 * - Every recipient is tracked independently in results.log, written ahead of the send
 *   ("sending") and after it (sent / failed / retry / unknown). On restart, anything
 *   still marked "sending" becomes "unknown" instead of being re-sent (no duplicates).
 * - Transient errors are retried with exponential backoff + jitter without holding a slot.
 * - Fatal errors (login, TLS, rejected sender, unreachable server) pause the campaign.
 * - Pause / Cancel are read from the control file on every loop pass.
 */
final class SlidingWindowSender
{
    private const STATUS_INTERVAL = 0.5;   // seconds between status.json writes
    private const CONTROL_INTERVAL = 0.25; // seconds between control-file checks
    private const MAX_INFLIGHT_LIST = 200;

    private SmtpSettings $smtp;
    private MessageBuilder $builder;
    private string $address;      // server address resolved once (no DNS per connection)
    private int $limit;           // granted window size
    private int $windowLimit;     // current window (temporarily shrinks when the provider throttles)
    private int $maxAttempts;
    private float $minInterval;   // from "max emails per minute" (0 = no rate limit)
    private int $total;

    /** One byte per recipient: 0 pending, S sent, F failed, U unknown, R in flight / retrying */
    private string $state;
    private int $sent = 0;
    private int $failed = 0;
    private int $unknown = 0;
    private int $dispatched = 0;

    /** @var list<array> recipients waiting for their retry time */
    private array $retry = [];
    /** @var array<int, SmtpConnection> */
    private array $conns = [];
    private int $connSeq = 0;

    /** @var resource */
    private $recipients;
    private int $fileIndex = 0;
    private bool $fileDone = false;
    /** @var resource */
    private $log;

    private bool $stopping = false;
    private string $stopReason = '';   // pause | cancel | fatal
    private ?string $fatalError = null;
    private int $connFailStreak = 0;
    private int $throttleOkStreak = 0;
    private float $lastDispatch = 0.0;
    private float $lastStatus = 0.0;
    private float $lastControl = 0.0;
    private array $completions = [];
    private float $startedAt;
    private ?string $signalStop = null;

    public function __construct(private readonly Workspace $ws, private readonly array $job, string $password, int $grantedLimit)
    {
        $this->smtp = SmtpSettings::fromArray($job['smtp'], $password);
        $this->builder = new MessageBuilder($this->smtp, (string) $job['subject'], (string) $job['message'], (string) $job['id']);
        $this->limit = max(1, $grantedLimit);
        $this->windowLimit = $this->limit;
        $this->maxAttempts = 1 + max(0, (int) ($job['max_retries'] ?? 3));
        $rate = (int) ($job['rate_per_minute'] ?? 0);
        $this->minInterval = $rate > 0 ? 60.0 / $rate : 0.0;
        $this->total = (int) $job['total'];
        $this->state = str_repeat('0', $this->total);
        $this->startedAt = (float) ($job['started_at'] ?? microtime(true));
    }

    public function requestStop(string $why): void
    {
        $this->signalStop = $why;
    }

    public function run(): string
    {
        $this->log = fopen($this->ws->path('results.log'), 'a+b');
        $this->recover();
        $this->recipients = fopen($this->ws->path('recipients.ndjson'), 'rb');
        if ($this->recipients === false) {
            throw new \RuntimeException('Recipient data is missing. Please upload the file again.');
        }
        $this->address = $this->smtp->resolveAddress();
        $this->writeStatus('running', true);

        while (true) {
            $this->checkControl();
            if (!$this->stopping) {
                $this->fill();
            }

            $inFlight = $this->inFlight();
            if ($inFlight === 0 && ($this->stopping || ($this->fileDone && !$this->retry))) {
                break;
            }

            $this->poll($inFlight === 0 ? 0.1 : 0.05);
            $this->maybeWriteStatus();
        }

        $this->shutdownConnections();
        fclose($this->recipients);

        $final = match (true) {
            $this->stopReason === 'cancel' => 'cancelled',
            $this->stopReason !== '' => 'paused',
            default => 'completed',
        };
        $this->writeStatus($final, true);
        fclose($this->log);
        return $final;
    }

    // ------------------------------------------------------------------ event loop

    /** One pass of stream_select over every open connection. */
    private function poll(float $timeout): void
    {
        $read = $write = [];
        $byStream = [];
        foreach ($this->conns as $id => $c) {
            $s = $c->stream();
            if ($s === null) {
                continue;
            }
            $read[$id] = $s;
            if ($c->wantsWrite()) {
                $write[$id] = $s;
            }
            $byStream[(int) $s] = $id;
        }

        if ($read) {
            $except = null;
            $r = $read;
            $w = $write;
            $sec = (int) $timeout;
            $n = @stream_select($r, $w, $except, $sec, (int) (($timeout - $sec) * 1e6));
            if ($n === false) {
                usleep(10000);
                $r = $w = [];
            }
            $readable = array_flip(array_map(fn ($s) => (int) $s, $r));
            $writable = array_flip(array_map(fn ($s) => (int) $s, $w));
            foreach ($this->conns as $c) {
                $s = $c->stream();
                if ($s !== null) {
                    $c->pump(isset($readable[(int) $s]), isset($writable[(int) $s]));
                }
            }
        } else {
            usleep((int) ($timeout * 1e6));
        }

        $now = microtime(true);
        foreach ($this->conns as $id => $c) {
            $c->tick($now);
            if ($c->isClosed()) {
                unset($this->conns[$id]);
            }
        }
    }

    private function inFlight(): int
    {
        return count(array_filter($this->conns, static fn (SmtpConnection $c) => $c->hasItem()));
    }

    // -------------------------------------------------------------------- dispatch

    private function fill(): void
    {
        $inFlight = $this->inFlight();
        while ($inFlight < $this->windowLimit) {
            if ($this->minInterval > 0 && microtime(true) - $this->lastDispatch < $this->minInterval) {
                return;
            }
            // Reuse an idle, already logged-in connection, or open a new one if the window allows.
            $conn = array_find($this->conns, static fn (SmtpConnection $c) => $c->isIdle());
            if ($conn === null && count($this->conns) >= $this->windowLimit) {
                return; // every connection is busy (or finishing a RSET/QUIT); wait for one
            }
            $item = $this->nextRetry() ?? $this->nextFromFile();
            if ($item === null) {
                return;
            }
            if ($this->dispatch($item, $conn)) {
                $inFlight++;
            }
        }
    }

    private function nextRetry(): ?array
    {
        $now = microtime(true);
        $k = array_find_key($this->retry, static fn (array $r) => $r['ready'] <= $now);
        if ($k === null) {
            return null;
        }
        $item = $this->retry[$k];
        unset($this->retry[$k]);
        return $item;
    }

    private function nextFromFile(): ?array
    {
        while (!$this->fileDone) {
            $line = fgets($this->recipients);
            if ($line === false) {
                $this->fileDone = true;
                return null;
            }
            $idx = $this->fileIndex++;
            if ($idx >= $this->total || $this->state[$idx] !== '0') {
                continue; // already handled in a previous run, or waiting in retry
            }
            $row = json_decode($line, true);
            if (!is_array($row) || empty($row['e'])) {
                $this->finish($idx, '', 'F', 0, 'Corrupt recipient row', 0);
                continue;
            }
            return ['idx' => $idx, 'email' => $row['e'], 'data' => $row['d'] ?? [], 'attempt' => 0];
        }
        return null;
    }

    private function dispatch(array $item, ?SmtpConnection $conn): bool
    {
        $item['attempt']++;
        try {
            [$mime] = $this->builder->build($item['email'], $item['data'], (string) $item['idx']);
        } catch (\Throwable $e) {
            $this->finish($item['idx'], $item['email'], 'F', 0, 'Could not build message: ' . $e->getMessage(), $item['attempt']);
            return false;
        }
        $item['started'] = microtime(true);

        // Write-ahead: if the process dies now, this recipient is reported as "unknown",
        // never silently re-sent.
        $this->append(['i' => $item['idx'], 'e' => $item['email'], 's' => 'sending', 'a' => $item['attempt']]);
        $this->state[$item['idx']] = 'R';
        $this->dispatched++;
        $this->lastDispatch = microtime(true);

        if ($conn !== null) {
            $conn->assign($item, $mime);
        } else {
            $id = ++$this->connSeq;
            $conn = new SmtpConnection($this->smtp, $this->address, fn (?array $it, array $r) => $this->onResult($it, $r));
            $this->conns[$id] = $conn;
            $conn->assign($item, $mime);
            $conn->connect();
        }
        return true;
    }

    // ------------------------------------------------------------------ completion

    private function onResult(?array $item, array $r): void
    {
        $this->completions[] = microtime(true);
        if ($r['throttle']) {
            $this->throttle();
        }
        if ($item !== null && $r['throttle'] && $r['type'] === 'transient') {
            // "Too many connections / slow down" is not the recipient's fault: try again
            // shortly without using up one of its retry attempts.
            $this->scheduleRetry($item, $r, 1.0 + mt_rand(0, 1000) / 1000, false);
            return;
        }
        if ($item === null) {
            if ($r['type'] === 'fatal') {
                $this->fatal($r['message']);
            }
            return;
        }

        switch ($r['type']) {
            case 'ok':
                $this->connFailStreak = 0;
                $this->finish($item['idx'], $item['email'], 'S', $r['code'], '', $item['attempt']);
                $this->recoverWindow();
                return;

            case 'unknown':
                $this->finish($item['idx'], $item['email'], 'U', $r['code'], $r['message'], $item['attempt']);
                return;

            case 'fatal':
                // Not the recipient's fault: keep it for when the campaign resumes.
                $this->scheduleRetry($item, $r, 0.0, false);
                $this->fatal($r['message']);
                return;

            case 'permanent':
                $this->connFailStreak = 0;
                $this->finish($item['idx'], $item['email'], 'F', $r['code'], $r['message'], $item['attempt']);
                return;

            default: // transient
                if ($r['requeue']) {
                    $this->scheduleRetry($item, $r, 0.0, false);
                    return;
                }
                if ($r['connection']) {
                    $this->connFailStreak++;
                    // Unreachable for many consecutive attempts and nothing ever delivered:
                    // stop instead of burning every recipient's retries.
                    if ($this->connFailStreak >= max(10, $this->limit * 2) && $this->sent === 0) {
                        $this->scheduleRetry($item, $r, 0.0, false);
                        $this->fatal('Cannot reach the SMTP server: ' . $r['message']);
                        return;
                    }
                }
                if ($item['attempt'] >= $this->maxAttempts) {
                    $this->finish($item['idx'], $item['email'], 'F', $r['code'], $r['message'] . " (gave up after {$item['attempt']} attempts)", $item['attempt']);
                    return;
                }
                $backoff = min(60.0, 2 ** $item['attempt']) + mt_rand(0, 1000) / 1000;
                $this->scheduleRetry($item, $r, $backoff, true);
        }
    }

    private function scheduleRetry(array $item, array $r, float $delay, bool $countAttempt): void
    {
        if (!$countAttempt) {
            $item['attempt']--;
        }
        unset($item['started']);
        $item['ready'] = microtime(true) + $delay;
        $this->retry[] = $item;
        $this->state[$item['idx']] = 'R';
        // Logged so that a restart retries it instead of reporting "unknown".
        $this->append(['i' => $item['idx'], 'e' => $item['email'], 's' => 'retry', 'a' => $item['attempt'], 'c' => $r['code'], 'm' => $r['message']]);
    }

    private function finish(int $idx, string $email, string $s, int $code, string $message, int $attempts): void
    {
        $this->state[$idx] = $s;
        match ($s) {
            'S' => $this->sent++,
            'F' => $this->failed++,
            'U' => $this->unknown++,
        };
        $this->append(['i' => $idx, 'e' => $email, 's' => ['S' => 'sent', 'F' => 'failed', 'U' => 'unknown'][$s], 'c' => $code, 'm' => $message, 'a' => $attempts]);
    }

    private function fatal(string $message): void
    {
        $this->fatalError ??= $message;
        $this->stopping = true;
        $this->stopReason = 'fatal';
    }

    /**
     * Provider says "too many connections / slow down". The connections it has already
     * accepted show what it tolerates, so shrink the window to that number; then probe
     * upwards again slowly as messages succeed.
     */
    private function throttle(): void
    {
        $established = 0;
        foreach ($this->conns as $c) {
            if ($c->isEstablished()) {
                $established++;
            }
        }
        $target = $established > 0 ? $established : intdiv($this->windowLimit, 2);
        $this->windowLimit = max(1, min($this->windowLimit, $target));
        $this->throttleOkStreak = 0;
    }

    private function recoverWindow(): void
    {
        if ($this->windowLimit < $this->limit && ++$this->throttleOkStreak >= 50) {
            $this->windowLimit++;
            $this->throttleOkStreak = 0;
        }
    }

    private function shutdownConnections(): void
    {
        foreach ($this->conns as $c) {
            $c->quit();
        }
        $deadline = microtime(true) + 2.0;
        while ($this->conns && microtime(true) < $deadline) {
            $this->poll(0.05);
        }
        foreach ($this->conns as $c) {
            $c->close();
        }
        $this->conns = [];
    }

    // ----------------------------------------------------------------- control / IO

    private function checkControl(): void
    {
        $now = microtime(true);
        if ($now - $this->lastControl < self::CONTROL_INTERVAL && $this->signalStop === null) {
            return;
        }
        $this->lastControl = $now;
        $ctl = $this->signalStop ?? $this->ws->control();
        if ($ctl === 'cancel' && $this->stopReason !== 'cancel') {
            $this->stopping = true;
            $this->stopReason = 'cancel';
            $this->writeStatus('cancelling', true);
        } elseif ($ctl === 'pause' && !$this->stopping) {
            $this->stopping = true;
            $this->stopReason = 'pause';
            $this->writeStatus('pausing', true);
        }
    }

    /** Rebuilds counters from results.log (resume / crash recovery). */
    private function recover(): void
    {
        $last = [];
        rewind($this->log);
        while (($line = fgets($this->log)) !== false) {
            $r = json_decode($line, true);
            if (is_array($r) && isset($r['i'])) {
                $last[(int) $r['i']] = $r;
            }
        }
        $retryIdx = array_keys(array_filter($last, static fn ($x) => ($x['s'] ?? '') === 'retry'));
        $data = $retryIdx ? $this->loadRecipientData($retryIdx) : [];
        foreach ($last as $idx => $r) {
            if ($idx >= $this->total) {
                continue;
            }
            switch ($r['s']) {
                case 'sent':
                    $this->state[$idx] = 'S';
                    $this->sent++;
                    break;
                case 'failed':
                    $this->state[$idx] = 'F';
                    $this->failed++;
                    break;
                case 'unknown':
                    $this->state[$idx] = 'U';
                    $this->unknown++;
                    break;
                case 'sending':
                    // The process died mid-send: the server may already have accepted it.
                    $this->finish($idx, (string) ($r['e'] ?? ''), 'U', 0, 'Sending was interrupted; delivery status unknown (not re-sent to avoid a duplicate).', (int) ($r['a'] ?? 1));
                    break;
                case 'retry':
                    $this->state[$idx] = 'R';
                    $this->retry[] = ['idx' => $idx, 'email' => (string) $r['e'], 'data' => $data[$idx] ?? ['email' => $r['e']], 'attempt' => (int) ($r['a'] ?? 0), 'ready' => 0.0];
                    break;
            }
        }
        fseek($this->log, 0, SEEK_END);
    }

    private function loadRecipientData(array $indexes): array
    {
        $want = array_flip($indexes);
        $out = [];
        $fh = fopen($this->ws->path('recipients.ndjson'), 'rb');
        if ($fh === false) {
            return [];
        }
        $i = 0;
        while ($want && ($line = fgets($fh)) !== false) {
            if (isset($want[$i])) {
                $row = json_decode($line, true);
                $out[$i] = $row['d'] ?? [];
                unset($want[$i]);
            }
            $i++;
        }
        fclose($fh);
        return $out;
    }

    private function append(array $record): void
    {
        $record['t'] = time();
        fwrite($this->log, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    private function maybeWriteStatus(): void
    {
        if (microtime(true) - $this->lastStatus >= self::STATUS_INTERVAL) {
            $this->writeStatus($this->stopping ? ($this->stopReason === 'cancel' ? 'cancelling' : 'pausing') : 'running');
        }
    }

    private function writeStatus(string $state, bool $force = false): void
    {
        $now = microtime(true);
        if (!$force && $now - $this->lastStatus < self::STATUS_INTERVAL) {
            return;
        }
        $this->lastStatus = $now;
        fflush($this->log);

        $this->completions = array_values(array_filter($this->completions, static fn ($t) => $t > $now - 60));
        $window = min(60.0, max(1.0, $now - $this->startedAt));
        $ratePerMin = (int) round(count($this->completions) * 60 / $window);

        $done = $this->sent + $this->failed + $this->unknown;
        $cancelled = $state === 'cancelled' ? $this->total - $done : 0;
        $remaining = max(0, $this->total - $done - $cancelled);

        $inflight = [];
        foreach ($this->conns as $c) {
            $it = $c->item();
            if ($it === null) {
                continue;
            }
            if (count($inflight) < self::MAX_INFLIGHT_LIST) {
                $inflight[] = ['email' => $it['email'], 'attempt' => $it['attempt'], 'secs' => round($now - ($it['started'] ?? $now), 1)];
            }
        }

        $prev = $this->ws->readJson('status.json') ?? [];
        $before = (int) ($prev['dispatched_before'] ?? 0);
        $this->ws->writeJson('status.json', [
            'state' => $state,
            'campaign_id' => $this->job['id'],
            'file_name' => $this->job['file_name'] ?? '',
            'total' => $this->total,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'unknown' => $this->unknown,
            'cancelled' => $cancelled,
            'remaining' => $remaining,
            'limit' => (int) $this->job['limit'],
            'effective_limit' => $this->limit,
            'window_limit' => $this->windowLimit,
            'in_flight_count' => $this->inFlight(),
            'in_flight' => $inflight,
            'open_connections' => count($this->conns),
            'retry_waiting' => count($this->retry),
            'dispatched' => $this->dispatched + $before,
            'dispatched_before' => $before,
            'rate_per_min' => $ratePerMin,
            'eta_seconds' => $ratePerMin > 0 && $remaining > 0 ? (int) ceil($remaining / $ratePerMin * 60) : null,
            'started_at' => (int) $this->startedAt,
            'updated_at' => time(),
            'heartbeat' => time(),
            'finished_at' => in_array($state, ['completed', 'cancelled'], true) ? time() : null,
            'last_error' => $this->fatalError,
            'pid' => getmypid(),
        ]);
    }
}
