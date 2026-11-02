<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Guard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Sender\WorkerLauncher;
use App\Storage\Workspace;
use App\Support\CampaignConfig;

/**
 * Start / Pause / Resume / Cancel, live status and failed-email details.
 * All actions act only on the logged-in user's own workspace and worker.
 */
final class CampaignController
{
    private const IN_PROGRESS = ['starting', 'running', 'pausing', 'cancelling', 'paused', 'interrupted'];

    public function start(): never
    {
        $ws = Guard::api();
        $c = CampaignConfig::update(Request::json());
        $password = CampaignConfig::password($c);
        Session::release();

        $errors = CampaignConfig::validateForSending($c);
        if ($errors) {
            Response::error(implode(' ', $errors), 422, ['errors' => $errors]);
        }
        $import = $ws->readJson('import.json');
        if ($import === null || !empty($import['purged']) || !is_file($ws->path('recipients.ndjson'))) {
            Response::error('Please upload a recipient file (File Name) first.', 422);
        }

        $error = $ws->withActionLock(function () use ($ws, $c, $import, $password): ?string {
            if ($ws->isWorkerRunning() || in_array($ws->status()['state'] ?? '', self::IN_PROGRESS, true)) {
                return 'A campaign is already in progress. Resume or cancel it first.';
            }
            $ws->clearCampaign(true);
            $job = [
                'id' => date('YmdHis') . bin2hex(random_bytes(3)),
                'user_id' => $ws->userId,
                'smtp' => [
                    'host' => $c['host'], 'port' => (int) $c['port'], 'security' => $c['security'],
                    'username' => $c['username'], 'from_email' => $c['from_email'], 'from_name' => $c['from_name'],
                    'timeout' => (int) $c['timeout'], 'unsubscribe' => $c['unsubscribe'],
                ],
                'subject' => $c['subject'],
                'message' => $c['message'],
                'limit' => (int) $c['send_limit'],
                'max_retries' => (int) $c['max_retries'],
                'rate_per_minute' => (int) $c['rate_per_minute'],
                'total' => (int) $import['total'],
                'file_name' => $import['file_name'],
                'started_at' => time(),
            ];
            $ws->writeJson('job.json', $job);
            $ws->setControl('run');
            $ws->writeJson('status.json', self::initialStatus($job));
            return self::launch($ws, $password);
        });

        $error === null ? Response::json(['ok' => true, 'status' => $ws->status()]) : Response::error($error, 409);
    }

    public function pause(): never
    {
        $ws = Guard::api(true);
        $state = $ws->status()['state'] ?? '';
        if (!in_array($state, ['starting', 'running'], true)) {
            Response::error('Nothing is sending right now.', 409);
        }
        $ws->setControl('pause');
        Response::json(['ok' => true]);
    }

    public function resume(): never
    {
        $ws = Guard::api();
        $in = Request::json();
        // Only the password and Send Limit may change on resume; everything else is frozen.
        $c = CampaignConfig::update(array_intersect_key($in, ['password' => 1, 'send_limit' => 1]));
        $password = CampaignConfig::password($c);
        Session::release();

        $error = $ws->withActionLock(function () use ($ws, $c, $password): ?string {
            $status = $ws->status();
            if ($ws->isWorkerRunning()) {
                return 'The campaign is still running.';
            }
            if (!in_array($status['state'] ?? '', ['paused', 'interrupted'], true)) {
                return 'There is no paused campaign to resume.';
            }
            if (!is_file($ws->path('recipients.ndjson')) || ($job = $ws->readJson('job.json')) === null) {
                return 'Campaign data has expired. Please upload the file and start again.';
            }
            if (($job['smtp']['username'] ?? '') !== '' && $password === '') {
                return 'Please re-enter the SMTP password to resume.';
            }
            if (ctype_digit((string) $c['send_limit']) && (int) $c['send_limit'] >= 1 && (int) $c['send_limit'] !== (int) $job['limit']) {
                $job['limit'] = (int) $c['send_limit'];
                $ws->writeJson('job.json', $job);
            }
            $status['state'] = 'starting';
            $status['last_error'] = null;
            $status['limit'] = (int) $job['limit'];
            $status['dispatched_before'] = (int) ($status['dispatched'] ?? 0);
            $status['in_flight'] = [];
            $status['in_flight_count'] = 0;
            $status['updated_at'] = $status['heartbeat'] = time();
            $ws->setControl('run');
            $ws->writeJson('status.json', $status);
            return self::launch($ws, $password);
        });

        $error === null ? Response::json(['ok' => true, 'status' => $ws->status()]) : Response::error($error, 409);
    }

    public function cancel(): never
    {
        $ws = Guard::api();
        $password = CampaignConfig::password(CampaignConfig::get());
        Session::release();

        $error = $ws->withActionLock(function () use ($ws, $password): ?string {
            $status = $ws->status();
            $state = $status['state'] ?? '';
            if (!in_array($state, self::IN_PROGRESS, true)) {
                return 'There is no campaign to cancel.';
            }
            $ws->setControl('cancel');
            if ($ws->isWorkerRunning()) {
                return null; // the running worker stops dispatching and finalises
            }
            // Paused / interrupted: start a worker that only reconciles the log and finalises.
            $status['state'] = 'cancelling';
            $status['updated_at'] = $status['heartbeat'] = time();
            $ws->writeJson('status.json', $status);
            return self::launch($ws, $password);
        });

        $error === null ? Response::json(['ok' => true]) : Response::error($error, 409);
    }

    public function status(): never
    {
        $ws = Guard::api(true);
        $import = $ws->readJson('import.json');
        Response::json([
            'ok' => true,
            'status' => $ws->status(),
            'import' => $import ? AppController::publicImport($import) : null,
        ]);
    }

    /** Clears a finished campaign so a new one can be prepared. */
    public function reset(): never
    {
        $ws = Guard::api(true);
        $out = $ws->withActionLock(function () use ($ws): ?string {
            if ($ws->isWorkerRunning() || in_array($ws->status()['state'] ?? '', self::IN_PROGRESS, true)) {
                return 'Cancel the current campaign first.';
            }
            $ws->clearCampaign(false);
            return null;
        });
        $out === null ? Response::json(['ok' => true]) : Response::error($out, 409);
    }

    public function failed(): never
    {
        $ws = Guard::api(true);
        $page = max(1, (int) Request::input('page', 1));
        $perPage = min(200, max(10, (int) Request::input('per_page', 50)));
        $q = strtolower(Request::str('q'));

        $rows = self::failures($ws, $q);
        $total = count($rows);
        $rows = array_slice(array_reverse($rows), ($page - 1) * $perPage, $perPage);
        Response::json(['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    public function export(): never
    {
        $ws = Guard::api(true);
        $rows = self::failures($ws, '');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="failed-emails-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['email', 'status', 'smtp_code', 'error', 'attempts', 'time'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [$r['email'], $r['status'], $r['code'] ?: '', $r['error'], $r['attempts'], date('Y-m-d H:i:s', $r['time'])], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------------------

    /** Final failures (failed / unknown) streamed from the append-only results log. */
    private static function failures(Workspace $ws, string $q): array
    {
        $file = $ws->path('results.log');
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        $fh = fopen($file, 'rb');
        while (($line = fgets($fh)) !== false) {
            if (!str_contains($line, '"s":"failed"') && !str_contains($line, '"s":"unknown"')) {
                continue;
            }
            $r = json_decode($line, true);
            if (!is_array($r)) {
                continue;
            }
            if ($q !== '' && !str_contains(strtolower(($r['e'] ?? '') . ' ' . ($r['m'] ?? '')), $q)) {
                continue;
            }
            $out[] = [
                'email' => (string) ($r['e'] ?? ''),
                'status' => (string) $r['s'],
                'code' => (int) ($r['c'] ?? 0),
                'error' => (string) ($r['m'] ?? ''),
                'attempts' => (int) ($r['a'] ?? 0),
                'time' => (int) ($r['t'] ?? 0),
            ];
        }
        fclose($fh);
        return $out;
    }

    private static function launch(Workspace $ws, string $password): ?string
    {
        try {
            WorkerLauncher::launch($ws, $password);
            return null;
        } catch (\Throwable $e) {
            $s = $ws->readJson('status.json') ?? [];
            $s['state'] = 'paused';
            $s['last_error'] = $e->getMessage();
            $ws->writeJson('status.json', $s);
            return $e->getMessage();
        }
    }

    private static function initialStatus(array $job): array
    {
        return [
            'state' => 'starting',
            'campaign_id' => $job['id'],
            'file_name' => $job['file_name'],
            'total' => $job['total'],
            'sent' => 0, 'failed' => 0, 'unknown' => 0, 'cancelled' => 0,
            'remaining' => $job['total'],
            'limit' => $job['limit'],
            'effective_limit' => null,
            'window_limit' => null,
            'in_flight_count' => 0,
            'in_flight' => [],
            'retry_waiting' => 0,
            'dispatched' => 0,
            'dispatched_before' => 0,
            'rate_per_min' => 0,
            'eta_seconds' => null,
            'started_at' => $job['started_at'],
            'updated_at' => time(),
            'heartbeat' => time(),
            'finished_at' => null,
            'last_error' => null,
        ];
    }
}
