<?php

declare(strict_types=1);

namespace App\Import;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Streams a CSV / XLSX / ODS file row by row (constant memory, whatever the file size),
 * validates and de-duplicates addresses, and writes a compact NDJSON recipients file.
 *
 * Duplicate detection keeps a set of 64-bit xxHash integers (~32 bytes per address)
 * instead of the strings themselves, so a million rows stays around 32 MB.
 */
final class RecipientImporter
{
    public const SUPPORTED = ['csv', 'txt', 'xlsx', 'ods'];
    private const SAMPLE_ROWS = 20;
    private const EMAIL_HEADERS = ['email', 'e_mail', 'email_address', 'emailaddress', 'email_id', 'emailid', 'mail', 'e_mail_address'];

    /**
     * @return array summary written to import.json
     * @throws ImportException
     */
    public function import(string $sourceFile, string $extension, string $originalName, string $outFile, string $invalidFile): array
    {
        $extension = strtolower($extension);
        if (!in_array($extension, self::SUPPORTED, true)) {
            throw new ImportException('Unsupported file type. Please upload a .csv or .xlsx file (save old .xls files as .xlsx).');
        }

        $reader = $this->openReader($sourceFile, $extension);
        $out = fopen($outFile . '.tmp', 'wb');
        $invalid = fopen($invalidFile, 'wb');
        fputcsv($invalid, ['row', 'value', 'reason'], ',', '"', '\\');

        $headers = null;
        $labels = [];
        $emailIdx = null;
        $seen = [];
        $sample = [];
        $rowNo = 0;
        $valid = $invalidCount = $duplicates = $blank = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNo++;
                    $cells = array_map([$this, 'cellToString'], $row->toArray());

                    if ($headers === null) {
                        if ($this->isBlankRow($cells)) {
                            continue;
                        }
                        [$headers, $labels, $emailIdx, $isHeaderRow] = $this->detectHeaders($cells);
                        if ($isHeaderRow) {
                            continue;
                        }
                    }
                    if ($this->isBlankRow($cells)) {
                        $blank++;
                        continue;
                    }

                    $email = trim($cells[$emailIdx] ?? '');
                    $email = trim($email, " \t\n\r\0\x0B<>;,");
                    if (!$this->validEmail($email)) {
                        $invalidCount++;
                        fputcsv($invalid, [$rowNo, $email, $email === '' ? 'empty' : 'invalid format'], ',', '"', '\\');
                        continue;
                    }
                    $key = unpack('J', hash('xxh64', strtolower($email), true))[1];
                    if (isset($seen[$key])) {
                        $duplicates++;
                        fputcsv($invalid, [$rowNo, $email, 'duplicate'], ',', '"', '\\');
                        continue;
                    }
                    $seen[$key] = true;

                    $data = [];
                    foreach ($headers as $i => $field) {
                        $data[$field] = $cells[$i] ?? '';
                    }
                    $data['email'] = $email;
                    fwrite($out, json_encode(['e' => $email, 'd' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $valid++;
                    if (count($sample) < self::SAMPLE_ROWS) {
                        $sample[] = $data;
                    }
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
            fclose($out);
            fclose($invalid);
        }

        if ($headers === null) {
            @unlink($outFile . '.tmp');
            throw new ImportException('The file is empty.');
        }
        if ($valid === 0) {
            @unlink($outFile . '.tmp');
            throw new ImportException('No valid email addresses were found in the file.');
        }
        rename($outFile . '.tmp', $outFile);

        $fields = array_values(array_unique(array_merge(['email'], $headers)));
        return [
            'file_name' => $originalName,
            'total' => $valid,
            'rows_read' => $rowNo,
            'invalid' => $invalidCount,
            'duplicates' => $duplicates,
            'blank' => $blank,
            'fields' => $fields,
            'labels' => $labels,
            'email_field' => $headers[$emailIdx] ?? 'email',
            'sample' => $sample,
            'imported_at' => time(),
        ];
    }

    private function openReader(string $file, string $ext): CsvReader|XlsxReader|OdsReader
    {
        try {
            if ($ext === 'xlsx') {
                $o = new XlsxOptions();
                $o->SHOULD_FORMAT_DATES = true;
                $r = new XlsxReader($o);
            } elseif ($ext === 'ods') {
                $o = new OdsOptions();
                $o->SHOULD_FORMAT_DATES = true;
                $r = new OdsReader($o);
            } else {
                $o = new CsvOptions();
                [$o->FIELD_DELIMITER, $o->ENCODING] = $this->sniffCsv($file);
                $r = new CsvReader($o);
            }
            $r->open($file);
            return $r;
        } catch (\Throwable $e) {
            throw new ImportException('Could not read the file: ' . $e->getMessage());
        }
    }

    /** Detects delimiter (, ; tab |) and encoding (UTF-8 or Windows-1252) from the first bytes. */
    private function sniffCsv(string $file): array
    {
        $head = (string) file_get_contents($file, false, null, 0, 64 * 1024);
        $head = preg_replace('/^\xEF\xBB\xBF/', '', $head);
        $lines = array_slice(preg_split('/\r\n|\n|\r/', $head) ?: [], 0, 5);
        $best = ',';
        $bestScore = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $counts = array_map(fn ($l) => count(str_getcsv($l, $d, '"', '\\')), array_filter($lines, 'strlen'));
            if (!$counts) {
                continue;
            }
            $score = min($counts) > 1 ? min($counts) * 10 - (max($counts) - min($counts)) : 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $d;
            }
        }
        $encoding = mb_check_encoding($head, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        return [$best, $encoding];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: int, 3: bool} */
    private function detectHeaders(array $cells): array
    {
        $keys = [];
        $labels = [];
        $emailIdx = null;
        foreach ($cells as $i => $label) {
            $label = trim((string) $label);
            $key = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $label) ?? '');
            $key = trim($key, '_');
            if ($key === '' || ctype_digit($key[0])) {
                $key = 'col_' . ($i + 1) . ($key !== '' ? '_' . $key : '');
            }
            $base = $key;
            $n = 2;
            while (in_array($key, $keys, true)) {
                $key = $base . '_' . $n++;
            }
            $keys[$i] = $key;
            $labels[$i] = $label !== '' ? $label : $key;
            if ($emailIdx === null && in_array($key, self::EMAIL_HEADERS, true)) {
                $emailIdx = $i;
            }
        }
        if ($emailIdx === null) {
            foreach ($keys as $i => $k) {
                if (str_contains($k, 'email') || str_contains($k, 'mail')) {
                    $emailIdx = $i;
                    break;
                }
            }
        }
        if ($emailIdx !== null) {
            return [$keys, $labels, $emailIdx, true];
        }
        // No header row: the first row is data. Find the column holding an address.
        foreach ($cells as $i => $v) {
            if ($this->validEmail(trim((string) $v))) {
                $keys = [];
                foreach ($cells as $j => $_) {
                    $keys[$j] = $j === $i ? 'email' : 'col_' . ($j + 1);
                }
                return [$keys, $keys, $i, false];
            }
        }
        throw new ImportException('Could not find an email column. Add a header named "email" to the column holding addresses.');
    }

    private function cellToString(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            $v instanceof \DateInterval => $v->format('%H:%I:%S'),
            is_bool($v) => $v ? 'TRUE' : 'FALSE',
            is_float($v) && floor($v) == $v && abs($v) < 1e15 => (string) (int) $v,
            default => trim((string) $v),
        };
    }

    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }

    private function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false;
    }
}
