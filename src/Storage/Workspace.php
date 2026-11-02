<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config;

/**
 * A user's temporary campaign workspace: storage/tmp/u{userId}/
 *
 *   upload/            chunk staging for the file being uploaded
 *   recipients.ndjson  parsed, validated, de-duplicated recipients (line N = recipient N)
 *   import.json        import summary (counts, fields, sample rows, file name)
 *   job.json           non-secret campaign settings frozen at Start
 *   status.json        live progress, written atomically by the worker
 *   results.log        append-only per-email log (write-ahead: "sending" then final state)
 *   control            desired state written by the web UI: run | pause | cancel
 *   worker.lock        held (flock) by the running worker for its whole lifetime
 *   worker.log         worker stderr / diagnostics
 *
 * Paths are derived ONLY from the authenticated user id, never from request input,
 * so one user can never reach another user's data or control their worker.
 */
final class Workspace
{
    public const ACTIVE_STATES = ['starting', 'running', 'pausing', 'cancelling'];
    private const STALE_HEARTBEAT = 20; // seconds

    private function __construct(public readonly int $userId, public readonly string $dir)
    {
    }

    public static function forUser(int $userId): self
    {
        $dir = Config::storagePath('tmp/u' . $userId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create workspace directory.');
        }
        return new self($userId, $dir);
    }

    public static function fromDir(string $dir): self
    {
        if (!preg_match('~/u(\d+)$~', rtrim($dir, '/'), $m)) {
            throw new \InvalidArgumentException('Invalid workspace directory');
        }
        return new self((int) $m[1], rtrim($dir, '/'));
    }

    public function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    public function readJson(string $name): ?array
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Atomic write (temp file + rename) so readers never see a half-written file. */
    public function writeJson(string $name, array $data): void
    {
        $file = $this->path($name);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        rename($tmp, $file);
    }

    public function control(): string
    {
        $v = @file_get_contents($this->path('control'));
        return $v === false ? 'run' : trim($v);
    }

    public function setControl(string $value): void
    {
        $tmp = $this->path('control.' . bin2hex(random_bytes(4)) . '.tmp');
        file_put_contents($tmp, $value);
        rename($tmp, $this->path('control'));
    }

    /** True while a worker process holds the workspace lock. */
    public function isWorkerRunning(): bool
    {
        $file = $this->path('worker.lock');
        // Open read-only: checking must never create the file (that would reset idle timers).
        $fh = is_file($file) ? @fopen($file, 'r') : false;
        if ($fh === false) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    /** Short exclusive lock used by Start/Resume/Cancel so double clicks can't race. */
    public function withActionLock(callable $fn): mixed
    {
        $fh = fopen($this->path('action.lock'), 'c');
        flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function hasRecipients(): bool
    {
        return is_file($this->path('recipients.ndjson')) && $this->readJson('import.json') !== null;
    }

    /**
     * Status as the UI should see it. If the file says "running" but no worker holds
     * the lock and the heartbeat is stale, the worker died: report "interrupted".
     */
    public function status(): ?array
    {
        $s = $this->readJson('status.json');
        if ($s === null) {
            return null;
        }
        if (in_array($s['state'] ?? '', self::ACTIVE_STATES, true)) {
            $age = time() - (int) ($s['heartbeat'] ?? $s['updated_at'] ?? 0);
            if ($age > self::STALE_HEARTBEAT && !$this->isWorkerRunning()) {
                $s['state'] = 'interrupted';
                $s['last_error'] = $s['last_error'] ?? 'The sending process stopped unexpectedly. Click Resume to continue.';
                $s['in_flight'] = [];
                $s['in_flight_count'] = 0;
            }
        }
        return $s;
    }

    /** Removes campaign artefacts; keeps the imported recipients when $keepRecipients. */
    public function clearCampaign(bool $keepRecipients): void
    {
        foreach (['job.json', 'status.json', 'results.log', 'control', 'worker.log'] as $f) {
            @unlink($this->path($f));
        }
        if (!$keepRecipients) {
            foreach (['recipients.ndjson', 'import.json', 'invalid.csv'] as $f) {
                @unlink($this->path($f));
            }
            self::rrmdir($this->path('upload'));
        }
    }

    /** Frees bulky temporary data once a campaign is finished (results are kept for review). */
    public function purgeAfterCompletion(): void
    {
        @unlink($this->path('recipients.ndjson'));
        @unlink($this->path('invalid.csv'));
        self::rrmdir($this->path('upload'));
        $import = $this->readJson('import.json');
        if ($import !== null) {
            $import['purged'] = true;
            $import['sample'] = [];
            $this->writeJson('import.json', $import);
        }
    }

    public function deleteAll(): void
    {
        self::rrmdir($this->dir);
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
