<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config;
use App\Database;

/**
 * Recipient files uploaded by an admin and assigned to one employee each.
 *
 * MySQL (table shared_files) holds the list, counts and the assignment. The data lives
 * on the server in storage/shared/f{id}/ and is never copied to a user:
 *
 *   recipients.ndjson  parsed, validated, de-duplicated recipients (line N = recipient N)
 *   meta.json          columns, email column, sample rows for preview, import counts
 *   invalid.csv        rows skipped at import (admin download)
 *   results.log        append-only per-email log for this file, across every batch
 *   progress.json      cached sent / failed / remaining counts, written by the sender
 *
 * Because progress belongs to the file, a recipient is never emailed twice from the
 * same file, whoever sends the next batch and however often the file is loaded.
 */
final class SharedFiles
{
    public const NAME_MAX = 190;

    public static function dir(int $id): string
    {
        return Config::storagePath('shared/f' . $id);
    }

    public static function path(int $id, string $name): string
    {
        return self::dir($id) . '/' . $name;
    }

    /** File names are what employees type, so keep them simple and unambiguous. */
    public static function validateName(string $name): ?string
    {
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return 'The file name must be 1 to ' . self::NAME_MAX . ' characters.';
        }
        if (preg_match('~[/\\\\\x00-\x1F\x7F]~', $name) || $name[0] === '.') {
            return 'The file name contains characters that are not allowed.';
        }
        return null;
    }

    public static function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM shared_files WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function findByName(string $name): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM shared_files WHERE LOWER(name) = LOWER(?)');
        $st->execute([trim($name)]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * The file this user may load under that name: an employee only gets a file assigned
     * to them; an admin can load any file. Returns null for "no such file" and for
     * "not yours" alike, so names of other people's files can't be discovered.
     */
    public static function findForUser(string $name, int $userId, bool $isAdmin): ?array
    {
        $row = self::findByName($name);
        if ($row === null || !is_file(self::path((int) $row['id'], 'recipients.ndjson'))) {
            return null;
        }
        if (!$isAdmin && (int) ($row['assigned_user_id'] ?? 0) !== $userId) {
            return null;
        }
        return $row;
    }

    public static function canAccess(int $fileId, int $userId, bool $isAdmin): bool
    {
        $row = self::find($fileId);
        if ($row === null || !is_file(self::path($fileId, 'recipients.ndjson'))) {
            return false;
        }
        return $isAdmin || (int) ($row['assigned_user_id'] ?? 0) === $userId;
    }

    /** @return list<string> names of the files assigned to a user, for autocomplete */
    public static function namesFor(int $userId): array
    {
        $st = Database::pdo()->prepare('SELECT name FROM shared_files WHERE assigned_user_id = ? ORDER BY name');
        $st->execute([$userId]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** All files with the assignee's name, newest first. */
    public static function all(): array
    {
        $rows = Database::pdo()->query(
            'SELECT f.*, u.name AS assignee_name, u.email AS assignee_email
               FROM shared_files f LEFT JOIN users u ON u.id = f.assigned_user_id
              ORDER BY f.created_at DESC, f.id DESC'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['progress'] = self::cachedProgress((int) $r['id'], (int) $r['recipients']);
        }
        return $rows;
    }

    /**
     * Registers an imported file: inserts the row, then moves the parsed data into the
     * file's own folder. $summary comes from RecipientImporter::import().
     */
    public static function create(string $name, int $uploaderId, array $summary, string $recipientsTmp, string $invalidTmp, int $sizeBytes): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('INSERT INTO shared_files (name, recipients, invalid_rows, duplicate_rows, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$name, (int) $summary['total'], (int) $summary['invalid'], (int) $summary['duplicates'], $sizeBytes, $uploaderId]);
        $id = (int) $pdo->lastInsertId();

        $dir = self::dir($id);
        Workspace::rrmdir($dir); // a leftover folder from a deleted file with a reused id
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $pdo->prepare('DELETE FROM shared_files WHERE id = ?')->execute([$id]);
            throw new \RuntimeException('Cannot create the folder for this file on the server.');
        }
        rename($recipientsTmp, $dir . '/recipients.ndjson');
        if (is_file($invalidTmp)) {
            rename($invalidTmp, $dir . '/invalid.csv');
        }
        $meta = [
            'file_name' => $name,
            'total' => (int) $summary['total'],
            'invalid' => (int) $summary['invalid'],
            'duplicates' => (int) $summary['duplicates'],
            'fields' => $summary['fields'],
            'labels' => $summary['labels'] ?? [],
            'email_field' => $summary['email_field'],
            'sample' => $summary['sample'] ?? [],
            'imported_at' => (int) ($summary['imported_at'] ?? time()),
        ];
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return self::find($id) ?? [];
    }

    public static function meta(int $id): ?array
    {
        $raw = @file_get_contents(self::path($id, 'meta.json'));
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public static function assign(int $id, ?int $userId): void
    {
        Database::pdo()->prepare('UPDATE shared_files SET assigned_user_id = ?, assigned_at = ' . ($userId === null ? 'NULL' : 'CURRENT_TIMESTAMP') . ' WHERE id = ?')
            ->execute([$userId, $id]);
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM shared_files WHERE id = ?')->execute([$id]);
        Workspace::rrmdir(self::dir($id));
    }

    /**
     * Exact progress for a file, rebuilt from its results log (last record per recipient).
     * "remaining" includes recipients waiting to be retried.
     */
    public static function progress(int $id, int $total): array
    {
        $sent = $failed = $unknown = 0;
        $fh = @fopen(self::path($id, 'results.log'), 'rb');
        if ($fh !== false) {
            $last = [];
            while (($line = fgets($fh)) !== false) {
                // Cheap parse: records look like {"i":12,"e":"...","s":"sent",...}
                if (preg_match('/^\{"i":(\d+),.*?"s":"([a-z]+)"/', $line, $m)) {
                    $last[(int) $m[1]] = $m[2];
                }
            }
            fclose($fh);
            foreach ($last as $idx => $state) {
                if ($idx >= $total) {
                    continue;
                }
                match ($state) {
                    'sent' => $sent++,
                    'failed' => $failed++,
                    'unknown', 'sending' => $unknown++,
                    default => null,
                };
            }
        }
        return self::counts($total, $sent, $failed, $unknown);
    }

    /** Progress as last written by the sender (cheap enough for listing many files). */
    public static function cachedProgress(int $id, int $total): array
    {
        $raw = @file_get_contents(self::path($id, 'progress.json'));
        $p = $raw === false ? null : json_decode($raw, true);
        if (!is_array($p)) {
            return self::counts($total, 0, 0, 0);
        }
        return self::counts($total, (int) ($p['sent'] ?? 0), (int) ($p['failed'] ?? 0), (int) ($p['unknown'] ?? 0)) + ['updated_at' => (int) ($p['updated_at'] ?? 0)];
    }

    public static function writeProgress(int $id, int $total, int $sent, int $failed, int $unknown): void
    {
        $file = self::path($id, 'progress.json');
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode(self::counts($total, $sent, $failed, $unknown) + ['updated_at' => time()])) !== false) {
            @rename($tmp, $file);
        }
    }

    private static function counts(int $total, int $sent, int $failed, int $unknown): array
    {
        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'unknown' => $unknown,
            'remaining' => max(0, $total - $sent - $failed - $unknown),
        ];
    }

    /**
     * Is someone sending from this file right now (or has a paused batch on it)?
     * Such files can't be reassigned or deleted until the batch finishes or is cancelled.
     */
    public static function inUseBy(int $id): ?int
    {
        foreach (glob(Config::storagePath('tmp/u*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $ws = Workspace::fromDir($dir);
            $import = $ws->readJson('import.json');
            if ((int) ($import['shared_file_id'] ?? 0) !== $id) {
                continue;
            }
            $state = $ws->status()['state'] ?? '';
            if ($ws->isWorkerRunning() || in_array($state, [...Workspace::ACTIVE_STATES, 'paused', 'interrupted'], true)) {
                return $ws->userId;
            }
        }
        return null;
    }
}
