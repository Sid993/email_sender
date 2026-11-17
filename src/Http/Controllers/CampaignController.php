<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Guard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Auth\Auth;
use App\Sender\WorkerLauncher;
use App\Storage\SharedFiles;
use App\Storage\Workspace;
use App\Support\CampaignConfig;

/**
 * Send Now / Pause / Resume / Cancel, live status and failed-email details.
 * All actions act only on the logged-in user's own workspace and worker.
 *
 * Send Now sends one batch: the next `Send Limit` recipients of the loaded file that
 * haven't been sent yet, all at the same time. The rest stay for the next click.
 * Cancel stops the current batch only.
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
        $notice = AppController::checkLoadedFile($ws);
        if ($notice !== null) {
            Response::error($notice, 409, ['unloaded' => true]);
        }
        $import = $ws->readJson('import.json');
        $fileId = $ws->sharedFileId();
        if ($import === null || $fileId === null || !is_file(SharedFiles::path($fileId, 'recipients.ndjson'))) {
            Response::error('Load your file first: enter its name under File Name and click Load File.', 422);
        }

        $error = $ws->withActionLock(function () use ($ws, $c, $import, $fileId, $password): ?string {
            $previous = $ws->status();
            if ($ws->isWorkerRunning() || in_array($previous['state'] ?? '', self::IN_PROGRESS, true)) {
                return 'A batch is already in progress. Resume or cancel it first.';
            }
            $progress = SharedFiles::progress($fileId, (int) $import['total']);
            if ($progress['remaining'] === 0) {
                return 'Every recipient in "' . $import['file_name'] . '" has already been processed. Load another file to send more.';
            }
            $ws->clearCampaign(true);
            $job = [
                'id' => date('YmdHis') . bin2hex(random_bytes(3)),
                'user_id' => $ws->userId,
                'shared_file_id' => $fileId,
                'batch_no' => (int) ($previous['batch_no'] ?? 0) + 1,
                'batch_size' => (int) $c['send_limit'],
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
            $ws->writeJson('status.json', self::initialStatus($job, $progress));
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
                return 'There is no paused batch to resume.';
            }
            $fileId = $ws->sharedFileId();
            if ($fileId === null || ($job = $ws->readJson('job.json')) === null || !SharedFiles::canAccess($fileId, $ws->userId, Auth::isAdmin())) {
                return 'This file is no longer available to you. Cancel the batch and load your file again.';
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
                return 'There is no batch to cancel.';
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
        AppController::checkLoadedFile($ws);
        $import = $ws->readJson('import.json');
        Response::json([
            'ok' => true,
            'status' => $ws->status(),
            'import' => $import ? AppController::publicImport($import) : null,
        ]);
    }

    /** Unloads the file. Its sending progress stays on the server for the next load. */
    public function reset(): never
    {
        $ws = Guard::api(true);
        $out = $ws->withActionLock(function () use ($ws): ?string {
            if ($ws->isWorkerRunning() || in_array($ws->status()['state'] ?? '', self::IN_PROGRESS, true)) {
                return 'Cancel the current batch first.';
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

    /** Final failures (failed / unknown) of the loaded file, streamed from its results log. */
    private static function failures(Workspace $ws, string $q): array
    {
        $file = $ws->resultsPath();
        if ($file === null || !is_file($file)) {
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

    private static function initialStatus(array $job, array $progress): array
    {
        return [
            'state' => 'starting',
            'campaign_id' => $job['id'],
            'file_name' => $job['file_name'],
            'total' => $job['total'],
            'sent' => $progress['sent'], 'failed' => $progress['failed'], 'unknown' => $progress['unknown'], 'cancelled' => 0,
            'remaining' => $progress['remaining'],
            'shared_file_id' => $job['shared_file_id'],
            'batch_id' => $job['id'],
            'batch_no' => $job['batch_no'],
            'batch_size' => min($job['batch_size'], $progress['remaining']),
            'batch_limit' => $job['batch_size'],
            'batch_done' => 0,
            'batch_unsent' => 0,
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
