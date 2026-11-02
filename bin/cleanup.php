<?php

/**
 * Removes expired temporary campaign data. Run from cron, e.g. every 15 minutes:
 *   0,15,30,45 * * * * www-data php /var/www/bulk-mailer/bin/cleanup.php >/dev/null 2>&1
 *
 * A workspace is removed when no worker is running for it and it has been idle longer
 * than JOB_RETENTION_HOURS (finished campaigns) or SESSION_LIFETIME (abandoned uploads).
 */

declare(strict_types=1);

use App\Config;
use App\Storage\ServerCapacity;
use App\Storage\Workspace;

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__));

$retention = Config::int('JOB_RETENTION_HOURS', 24) * 3600;
$idleUpload = Config::int('SESSION_LIFETIME', 480) * 60;
$now = time();
$removed = 0;

foreach (glob(Config::storagePath('tmp/u*'), GLOB_ONLYDIR) ?: [] as $dir) {
    $ws = Workspace::fromDir($dir);
    if ($ws->isWorkerRunning()) {
        continue;
    }
    $latest = 0;
    foreach (glob($dir . '/*') ?: [] as $f) {
        $latest = max($latest, (int) filemtime($f));
    }
    $status = $ws->readJson('status.json');
    $limit = $status !== null ? $retention : $idleUpload;
    if ($latest === 0 || $now - $latest > $limit) {
        $ws->deleteAll();
        $removed++;
    }
}

foreach (glob(Config::storagePath('run/u*.json')) ?: [] as $file) {
    $entry = json_decode((string) file_get_contents($file), true);
    if (!ServerCapacity::alive((int) ($entry['pid'] ?? 0))) {
        @unlink($file);
    }
}

echo "Removed $removed expired workspace(s)\n";
