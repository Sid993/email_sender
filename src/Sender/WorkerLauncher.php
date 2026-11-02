<?php

declare(strict_types=1);

namespace App\Sender;

use App\Config;
use App\Storage\Workspace;

/**
 * Starts the detached background worker for one user's campaign.
 *
 * The worker is a separate CLI process (one per running campaign), so:
 *  - no PHP-FPM worker is held while sending, and no max_execution_time applies;
 *  - closing the browser does not stop the campaign;
 *  - each user's campaign is fully isolated in its own process.
 *
 * The SMTP password is handed over through an inherited pipe (fd 3) and never
 * appears on the command line or on disk.
 */
final class WorkerLauncher
{
    public static function launch(Workspace $ws, string $password): void
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open() is disabled in PHP; background sending is not possible on this server.');
        }
        $php = Config::get('PHP_CLI_BINARY', PHP_BINDIR . '/php');
        $spawn = Config::$root . '/bin/spawn-worker.sh';
        $worker = Config::$root . '/bin/send-worker.php';

        $proc = proc_open(
            ['/bin/bash', $spawn, $php, $worker, (string) $ws->userId, $ws->path('worker.log')],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
                3 => ['pipe', 'r'],
            ],
            $pipes,
            Config::$root,
            ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin']
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('Could not start the sending process.');
        }
        fwrite($pipes[3], json_encode(['password' => $password]) . "\n");
        fclose($pipes[3]);
        // The spawn script backgrounds the worker and exits at once, so this returns
        // immediately and leaves no zombie behind (the worker is re-parented to init).
        proc_close($proc);
    }
}
