<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Auth;
use App\Config;
use App\Http\Guard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Sender\TestSender;
use App\Support\CampaignConfig;
use App\Template\Renderer;

final class AppController
{
    public function home(): never
    {
        Guard::page();
        Response::view('app', [
            'user' => Auth::user(),
            'csrf' => Session::csrfToken(),
            'appName' => Config::get('APP_NAME', 'SMTP MAIL BULK MAIL - SERVER'),
            'smtpSupported' => extension_loaded('openssl'),
            'uploadMaxMb' => Config::int('UPLOAD_MAX_MB', 200),
            'chunkBytes' => self::chunkBytes(),
        ]);
    }

    /** Largest chunk the PHP config accepts (upload_max_filesize / post_max_size), capped at 4 MB. */
    public static function chunkBytes(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
            };
        };
        $limits = array_filter([$toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size'))]);
        $max = $limits ? min($limits) : 2 * 1024 * 1024;
        return max(256 * 1024, min(4 * 1024 * 1024, $max - 64 * 1024));
    }

    /** Everything the page needs to restore itself after a reload. */
    public function state(): never
    {
        $ws = Guard::api(true);
        $import = $ws->readJson('import.json');
        Response::json([
            'ok' => true,
            'config' => CampaignConfig::toPublic(CampaignConfig::get()),
            'import' => $import ? self::publicImport($import) : null,
            'status' => $ws->status(),
            'worker_running' => $ws->isWorkerRunning(),
        ]);
    }

    public function saveConfig(): never
    {
        Guard::api();
        $c = CampaignConfig::update(Request::json());
        Session::release();
        Response::json(['ok' => true, 'config' => CampaignConfig::toPublic($c)]);
    }

    public function preview(): never
    {
        $ws = Guard::api();
        $c = CampaignConfig::update(Request::json());
        Session::release();

        $import = $ws->readJson('import.json');
        $sample = $import['sample'] ?? [];
        $row = max(1, (int) Request::input('row', 1));
        $data = $sample[$row - 1] ?? ($sample[0] ?? []);
        $fields = $import['fields'] ?? ['email'];

        $subject = (new Renderer($c['subject']))->render($data, false);
        $html = (new Renderer($c['message']))->render($data, true);
        Response::json([
            'ok' => true,
            'subject' => $subject,
            'html' => $html,
            'text' => Renderer::htmlToText($html),
            'row' => $sample ? min($row, count($sample)) : 0,
            'rows' => count($sample),
            'data' => $data,
            'unknown_fields' => Renderer::unknownFields($c['subject'] . ' ' . $c['message'], $fields),
            'from' => trim($c['from_name'] . ' <' . $c['from_email'] . '>'),
        ]);
    }

    public function testSend(): never
    {
        $ws = Guard::api();
        $c = CampaignConfig::update(Request::json());
        Session::release(); // don't hold the session lock while talking to SMTP

        $addresses = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $c['test_to']) ?: []))));
        if (!$addresses) {
            Response::error('Enter at least one test email address in "Send To (Test Email Id)".');
        }
        $bad = array_filter($addresses, fn ($a) => !filter_var($a, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE));
        if ($bad) {
            Response::error('Invalid test address: ' . implode(', ', $bad));
        }
        if (count($addresses) > 25) {
            Response::error('Please use at most 25 test addresses.');
        }
        $errors = CampaignConfig::validateForSending($c + ['send_limit' => '1']);
        if ($errors) {
            Response::error(implode(' ', $errors), 422, ['errors' => $errors]);
        }

        set_time_limit(max(60, (int) $c['timeout'] + 20));
        $sample = $ws->readJson('import.json')['sample'][0] ?? [];
        $results = TestSender::send(CampaignConfig::smtp($c), $c['subject'], $c['message'], $addresses, $sample);
        Response::json(['ok' => true, 'results' => $results, 'personalized_with' => $sample ? ($sample['email'] ?? '') : null]);
    }

    public static function publicImport(array $import): array
    {
        return [
            'file_name' => $import['file_name'],
            'total' => $import['total'],
            'invalid' => $import['invalid'],
            'duplicates' => $import['duplicates'],
            'fields' => $import['fields'],
            'email_field' => $import['email_field'],
            'sample_rows' => count($import['sample'] ?? []),
            'purged' => !empty($import['purged']),
            'imported_at' => $import['imported_at'],
        ];
    }

}
