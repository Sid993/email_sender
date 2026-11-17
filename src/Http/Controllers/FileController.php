<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Auth;
use App\Http\Guard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Storage\SharedFiles;
use App\Storage\Workspace;

/**
 * Employee side of shared files: type the file name the admin shared, click Load File.
 * Nothing is downloaded or uploaded; the workspace just points at the server's copy.
 */
final class FileController
{
    public function load(): never
    {
        $ws = Guard::api();
        $uid = $ws->userId;
        Session::release();
        $isAdmin = Auth::isAdmin();

        $name = Request::str('name');
        if ($name === '') {
            Response::error('Enter the file name your admin shared with you.', 422);
        }
        $file = SharedFiles::findForUser($name, $uid, $isAdmin);
        if ($file === null) {
            Response::error("No file named \"$name\" is assigned to you. Check the exact name with your admin.", 404);
        }

        $error = $ws->withActionLock(function () use ($ws, $file): ?string {
            $state = $ws->status()['state'] ?? '';
            if ($ws->isWorkerRunning() || in_array($state, [...Workspace::ACTIVE_STATES, 'paused', 'interrupted'], true)) {
                return 'A batch is running or paused. Let it finish, or cancel it, before loading another file.';
            }
            $id = (int) $file['id'];
            $meta = SharedFiles::meta($id);
            if ($meta === null) {
                return 'This file is incomplete on the server. Ask your admin to upload it again.';
            }
            $progress = SharedFiles::progress($id, (int) $meta['total']);
            SharedFiles::writeProgress($id, $progress['total'], $progress['sent'], $progress['failed'], $progress['unknown']);
            $ws->clearCampaign(false);
            $ws->writeJson('import.json', $meta + ['shared_file_id' => $id, 'loaded_at' => time()]);
            return null;
        });
        if ($error !== null) {
            Response::error($error, 409);
        }
        $import = $ws->readJson('import.json');
        Response::json(['ok' => true, 'import' => AppController::publicImport($import)]);
    }

    /** Names of the files assigned to me, for the File Name suggestions. */
    public function mine(): never
    {
        $ws = Guard::api(true);
        Response::json(['ok' => true, 'files' => SharedFiles::namesFor($ws->userId)]);
    }
}
