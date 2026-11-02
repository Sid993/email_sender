<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config;
use App\Http\Guard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Import\ImportException;
use App\Import\RecipientImporter;
use App\Storage\Workspace;

/**
 * Chunked upload: the browser sends the file in pieces (a few MB each) so large files
 * never hit upload_max_filesize / post_max_size, and the server never buffers the
 * whole file in memory. When the last chunk arrives the file is imported (streamed).
 */
final class UploadController
{
    private const MAX_CHUNK_BYTES = 16 * 1024 * 1024;

    public function chunk(): never
    {
        $ws = Guard::api();
        Session::release();

        if (in_array($ws->status()['state'] ?? '', [...Workspace::ACTIVE_STATES, 'paused', 'interrupted'], true)) {
            Response::error('A campaign is in progress. Cancel it or wait for it to finish before uploading a new file.', 409);
        }

        $uploadId = Request::str('upload_id');
        $index = (int) Request::input('index', -1);
        $total = (int) Request::input('total', 0);
        $name = basename(str_replace('\\', '/', Request::str('name')));
        $size = (int) Request::input('size', 0);
        $maxBytes = Config::int('UPLOAD_MAX_MB', 200) * 1024 * 1024;

        if (!preg_match('/^[a-f0-9]{16,64}$/', $uploadId) || $index < 0 || $total < 1 || $index >= $total) {
            Response::error('Invalid upload request.');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, RecipientImporter::SUPPORTED, true)) {
            Response::error('Please upload a .csv or .xlsx file. (Old .xls files: open in Excel and save as .xlsx.)', 415);
        }
        if ($size > $maxBytes) {
            Response::error('File is too large. Maximum size is ' . Config::int('UPLOAD_MAX_MB', 200) . ' MB.', 413);
        }
        $file = $_FILES['chunk'] ?? null;
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            Response::error('Upload chunk exceeds the PHP upload_max_filesize / post_max_size setting. Reload the page and try again.', 413);
        }
        if (!$file || $err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            Response::error('Upload failed (chunk ' . ($index + 1) . '). Please try again.');
        }
        if ($file['size'] > self::MAX_CHUNK_BYTES) {
            Response::error('Chunk too large.');
        }

        $dir = $ws->path('upload');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $part = $dir . '/' . $uploadId . '.part';
        $metaFile = $dir . '/' . $uploadId . '.json';

        if ($index === 0) {
            // A new upload replaces any older, unfinished one.
            foreach (glob($dir . '/*') ?: [] as $old) {
                @unlink($old);
            }
            file_put_contents($metaFile, json_encode(['next' => 0, 'name' => $name, 'size' => $size, 'total' => $total]));
        }
        $meta = json_decode((string) @file_get_contents($metaFile), true);
        if (!is_array($meta) || $meta['next'] !== $index || $meta['total'] !== $total) {
            Response::error('Upload out of order. Please choose the file again.', 409);
        }

        $in = fopen($file['tmp_name'], 'rb');
        $out = fopen($part, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        clearstatcache(true, $part);
        if (filesize($part) > $maxBytes) {
            @unlink($part);
            Response::error('File is too large.', 413);
        }
        $meta['next'] = $index + 1;
        file_put_contents($metaFile, json_encode($meta));

        if ($index + 1 < $total) {
            Response::json(['ok' => true, 'received' => $index + 1]);
        }

        // Last chunk: import (streaming).
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        $error = null;
        try {
            $summary = $ws->withActionLock(function () use ($ws, $part, $ext, $name) {
                $summary = (new RecipientImporter())->import($part, $ext, $name, $ws->path('recipients.ndjson'), $ws->path('invalid.csv'));
                $ws->clearCampaign(true);
                $ws->writeJson('import.json', $summary);
                return $summary;
            });
        } catch (ImportException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Import failed: ' . $e);
            $error = 'Could not read the file. Make sure it is a valid CSV or Excel (.xlsx) file.';
        }
        Workspace::rrmdir($dir); // the raw upload is never kept
        if ($error !== null) {
            Response::error($error, 422);
        }
        Response::json(['ok' => true, 'done' => true, 'import' => AppController::publicImport($summary)]);
    }

    public function remove(): never
    {
        $ws = Guard::api();
        Session::release();
        if ($ws->isWorkerRunning() || in_array($ws->status()['state'] ?? '', [...Workspace::ACTIVE_STATES, 'paused', 'interrupted'], true)) {
            Response::error('Cancel the current campaign first.', 409);
        }
        $ws->clearCampaign(false);
        Response::json(['ok' => true]);
    }

    public function invalidRows(): never
    {
        $ws = Guard::api(true);
        $file = $ws->path('invalid.csv');
        if (!is_file($file)) {
            Response::error('No skipped rows file available.', 404);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="skipped-rows.csv"');
        readfile($file);
        exit;
    }
}
