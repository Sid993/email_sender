<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use App\Import\RecipientImporter;

/**
 * Receives a file sent by the browser in pieces (a few MB each), so large files never
 * hit upload_max_filesize / post_max_size and are never buffered in memory.
 *
 * Request fields: upload_id, index, total, name, size, chunk (the file piece).
 */
final class ChunkedUpload
{
    private const MAX_CHUNK_BYTES = 16 * 1024 * 1024;
    private const STALE_SECONDS = 86400;

    /**
     * Stores one chunk in $dir. Returns null while more chunks are expected, or the
     * finished upload: ['path', 'name', 'ext', 'size'].
     */
    public static function receive(string $dir, ?callable $beforeFirstChunk = null): ?array
    {
        $uploadId = Request::str('upload_id');
        $index = (int) Request::input('index', -1);
        $total = (int) Request::input('total', 0);
        $name = trim(basename(str_replace('\\', '/', Request::str('name'))));
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

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $part = $dir . '/' . $uploadId . '.part';
        $metaFile = $dir . '/' . $uploadId . '.json';

        if ($index === 0) {
            if ($beforeFirstChunk !== null) {
                $beforeFirstChunk($name);
            }
            // Several files may be uploading one after another; only drop abandoned ones.
            foreach (glob($dir . '/*') ?: [] as $old) {
                if (time() - (int) @filemtime($old) > self::STALE_SECONDS) {
                    @unlink($old);
                }
            }
            @unlink($part);
            file_put_contents($metaFile, json_encode(['next' => 0, 'name' => $name, 'size' => $size, 'total' => $total]));
        }
        $meta = json_decode((string) @file_get_contents($metaFile), true);
        if (!is_array($meta) || $meta['next'] !== $index || $meta['total'] !== $total || $meta['name'] !== $name) {
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
            @unlink($metaFile);
            Response::error('File is too large.', 413);
        }
        $meta['next'] = $index + 1;
        file_put_contents($metaFile, json_encode($meta));

        if ($index + 1 < $total) {
            return null;
        }
        @unlink($metaFile);
        return ['path' => $part, 'name' => $name, 'ext' => $ext, 'size' => (int) filesize($part)];
    }
}
