<?php

/**
 * Background sending worker: one process per running campaign.
 * Started by App\Sender\WorkerLauncher; never run from the web.
 *
 *   php bin/send-worker.php <user-id>      (password JSON on stdin)
 */

declare(strict_types=1);

use App\Config;
use App\Sender\SlidingWindowSender;
use App\Storage\ServerCapacity;
use App\Storage\Workspace;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__));

set_time_limit(0);
ini_set('memory_limit', '1024M');
error_reporting(E_ALL);
cli_set_process_title('bulk-mailer send-worker');

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    fwrite(STDERR, "Missing user id\n");
    exit(1);
}
$ws = Workspace::forUser($userId);
$stamp = static fn (string $m) => fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $m\n");

$secret = json_decode((string) stream_get_contents(STDIN), true);
$password = (string) ($secret['password'] ?? '');

// One worker per user workspace, enforced with an exclusive lock held for life.
$lock = fopen($ws->path('worker.lock'), 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    $stamp('Another worker is already running for this user; exiting.');
    exit(0);
}

$job = $ws->readJson('job.json');
$failStatus = static function (string $message) use ($ws): void {
    $s = $ws->readJson('status.json') ?? [];
    $s['state'] = 'paused';
    $s['last_error'] = $message;
    $s['updated_at'] = $s['heartbeat'] = time();
    $ws->writeJson('status.json', $s);
};

if ($job === null) {
    $failStatus('Campaign settings are missing. Please start again.');
    exit(1);
}

// Cancel requested while no worker was running: just finalise without sending.
$granted = $ws->control() === 'cancel' ? 1 : ServerCapacity::acquire($userId, (int) $job['limit'], getmypid());
if ($granted < 1) {
    $failStatus('The server is at its sending capacity right now (other campaigns are running). Click Resume in a moment.');
    exit(0);
}

$stamp("Worker started (pid " . getmypid() . ", limit {$job['limit']}, granted $granted)");
$sender = new SlidingWindowSender($ws, $job, $password, $granted);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
        pcntl_signal($sig, static fn () => $sender->requestStop('pause'));
    }
}

try {
    $final = $sender->run();
    $stamp("Worker finished: $final");
    if (in_array($final, ['completed', 'cancelled'], true)) {
        $ws->purgeAfterCompletion();
    }
} catch (\Throwable $e) {
    $stamp('Worker error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $failStatus('Sending stopped: ' . $e->getMessage());
} finally {
    ServerCapacity::release($userId);
    flock($lock, LOCK_UN);
    fclose($lock);
}
