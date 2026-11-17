<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Auth;
use App\Config;
use App\Database;
use App\Http\ChunkedUpload;
use App\Http\Guard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Import\ImportException;
use App\Import\RecipientImporter;
use App\Storage\SharedFiles;

/**
 * Admin: upload recipient files to the server and assign each one to an employee.
 */
final class AdminController
{
    public function page(): never
    {
        Guard::adminPage();
        Response::view('admin', [
            'user' => Auth::user(),
            'csrf' => Session::csrfToken(),
            'appName' => Config::get('APP_NAME', 'SMTP MAIL BULK MAIL - SERVER'),
            'uploadMaxMb' => Config::int('UPLOAD_MAX_MB', 200),
            'chunkBytes' => AppController::chunkBytes(),
        ]);
    }

    public function files(): never
    {
        Guard::admin(true);
        $files = array_map(static function (array $f): array {
            return [
                'id' => (int) $f['id'],
                'name' => $f['name'],
                'recipients' => (int) $f['recipients'],
                'invalid' => (int) $f['invalid_rows'],
                'duplicates' => (int) $f['duplicate_rows'],
                'size_bytes' => (int) $f['size_bytes'],
                'created_at' => $f['created_at'],
                'assigned_user_id' => $f['assigned_user_id'] !== null ? (int) $f['assigned_user_id'] : null,
                'assignee' => $f['assignee_name'] !== null ? ['name' => $f['assignee_name'], 'email' => $f['assignee_email']] : null,
                'progress' => $f['progress'],
                'has_skipped' => is_file(SharedFiles::path((int) $f['id'], 'invalid.csv')) && ((int) $f['invalid_rows'] + (int) $f['duplicate_rows']) > 0,
            ];
        }, SharedFiles::all());
        $users = Database::pdo()->query('SELECT id, name, email, role FROM users ORDER BY name, email')->fetchAll();
        $users = array_map(static fn (array $u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']], $users);
        Response::json(['ok' => true, 'files' => $files, 'users' => $users]);
    }

    public function uploadChunk(): never
    {
        $ws = Guard::admin();
        Session::release();

        $done = ChunkedUpload::receive(Config::storagePath('tmp/staging/u' . $ws->userId), static function (string $name): void {
            $bad = SharedFiles::validateName($name);
            if ($bad !== null) {
                Response::error($bad, 422);
            }
            if (SharedFiles::findByName($name) !== null) {
                Response::error("A file named \"$name\" is already on the server. Delete it first, or rename your file and upload it again.", 409);
            }
        });
        if ($done === null) {
            Response::json(['ok' => true, 'received' => (int) Request::input('index', 0) + 1]);
        }

        set_time_limit(0);
        ini_set('memory_limit', '512M');
        $recipientsTmp = $done['path'] . '.ndjson';
        $invalidTmp = $done['path'] . '.invalid.csv';
        $error = null;
        $file = null;
        try {
            if (SharedFiles::findByName($done['name']) !== null) {
                throw new ImportException("A file named \"{$done['name']}\" is already on the server.");
            }
            $summary = (new RecipientImporter())->import($done['path'], $done['ext'], $done['name'], $recipientsTmp, $invalidTmp);
            $file = SharedFiles::create($done['name'], $ws->userId, $summary, $recipientsTmp, $invalidTmp, $done['size']);
        } catch (ImportException $e) {
            $error = $e->getMessage();
        } catch (\PDOException $e) {
            error_log('Shared file insert failed: ' . $e);
            $error = "Could not save \"{$done['name']}\". A file with this name may already exist.";
        } catch (\Throwable $e) {
            error_log('Import failed: ' . $e);
            $error = 'Could not read the file. Make sure it is a valid CSV or Excel (.xlsx) file.';
        }
        foreach ([$done['path'], $recipientsTmp, $invalidTmp] as $f) {
            @unlink($f); // the raw upload is never kept
        }
        if ($error !== null) {
            Response::error($error, 422);
        }
        Response::json(['ok' => true, 'done' => true, 'file' => [
            'id' => (int) $file['id'], 'name' => $file['name'], 'recipients' => (int) $file['recipients'],
            'invalid' => (int) $file['invalid_rows'], 'duplicates' => (int) $file['duplicate_rows'],
        ]]);
    }

    public function assign(): never
    {
        Guard::admin(true);
        $id = (int) Request::input('id', 0);
        $userId = (int) Request::input('user_id', 0);
        $file = SharedFiles::find($id);
        if ($file === null) {
            Response::error('That file no longer exists.', 404);
        }
        if ($userId > 0) {
            $st = Database::pdo()->prepare('SELECT id FROM users WHERE id = ?');
            $st->execute([$userId]);
            if ($st->fetch() === false) {
                Response::error('That user no longer exists.', 404);
            }
        }
        $current = $file['assigned_user_id'] !== null ? (int) $file['assigned_user_id'] : null;
        if ($current === ($userId ?: null)) {
            Response::json(['ok' => true]);
        }
        $busy = SharedFiles::inUseBy($id);
        if ($busy !== null) {
            Response::error(self::busyMessage($file['name'], $busy), 409);
        }
        SharedFiles::assign($id, $userId ?: null);
        Response::json(['ok' => true]);
    }

    public function delete(): never
    {
        Guard::admin(true);
        $id = (int) Request::input('id', 0);
        $file = SharedFiles::find($id);
        if ($file === null) {
            Response::json(['ok' => true]);
        }
        $busy = SharedFiles::inUseBy($id);
        if ($busy !== null) {
            Response::error(self::busyMessage($file['name'], $busy), 409);
        }
        SharedFiles::delete($id);
        Response::json(['ok' => true]);
    }

    public function skipped(): never
    {
        Guard::admin(true);
        $id = (int) Request::input('id', 0);
        $file = SharedFiles::find($id);
        $path = SharedFiles::path($id, 'invalid.csv');
        if ($file === null || !is_file($path)) {
            Response::error('No skipped rows for this file.', 404);
        }
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'file';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '-skipped-rows.csv"');
        readfile($path);
        exit;
    }

    private static function busyMessage(string $name, int $userId): string
    {
        $st = Database::pdo()->prepare('SELECT name FROM users WHERE id = ?');
        $st->execute([$userId]);
        $who = $st->fetchColumn() ?: 'Someone';
        return "$who has a batch running or paused on \"$name\". It can be changed once that batch finishes or is cancelled.";
    }
}
