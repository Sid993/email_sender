<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config;

/**
 * Server-wide guard on simultaneous SMTP sends across all users.
 *
 * Each running worker registers storage/run/u{id}.json with its pid and slot count.
 * The ceiling comes from SERVER_MAX_CONCURRENT_SENDS (config, not code); 0 = unlimited.
 * Registrations of dead processes are ignored, so a crashed worker never leaks capacity.
 */
final class ServerCapacity
{
    private static function dir(): string
    {
        $dir = Config::storagePath('run');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /** @return int granted slots (0 = server at capacity) */
    public static function acquire(int $userId, int $requested, int $pid): int
    {
        $ceiling = Config::int('SERVER_MAX_CONCURRENT_SENDS', 0);
        $fh = fopen(self::dir() . '/.lock', 'c');
        flock($fh, LOCK_EX);
        try {
            $used = 0;
            foreach (glob(self::dir() . '/u*.json') ?: [] as $file) {
                $entry = json_decode((string) @file_get_contents($file), true);
                if (!is_array($entry) || (int) ($entry['user'] ?? 0) === $userId) {
                    continue;
                }
                if (!self::alive((int) ($entry['pid'] ?? 0))) {
                    @unlink($file);
                    continue;
                }
                $used += (int) ($entry['slots'] ?? 0);
            }
            $granted = $ceiling > 0 ? max(0, min($requested, $ceiling - $used)) : $requested;
            if ($granted > 0) {
                file_put_contents(self::dir() . "/u{$userId}.json", json_encode([
                    'user' => $userId, 'pid' => $pid, 'slots' => $granted, 'since' => time(),
                ]));
            }
            return $granted;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public static function release(int $userId): void
    {
        @unlink(self::dir() . "/u{$userId}.json");
    }

    public static function alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $cmd = @file_get_contents("/proc/{$pid}/cmdline");
        return $cmd !== false && str_contains($cmd, 'send-worker');
    }
}
