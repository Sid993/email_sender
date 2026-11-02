<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Storage\Workspace;

final class Guard
{
    /** Session + login + CSRF check for JSON endpoints. Returns the user's workspace. */
    public static function api(bool $readOnly = false): Workspace
    {
        $readOnly ? Session::startReadOnly() : Session::start();
        $uid = Auth::userId();
        if ($uid === null) {
            Response::error('Your session has expired. Please log in again.', 401, ['login' => true]);
        }
        if (Request::method() !== 'GET' && !Session::verifyCsrf(Request::csrfHeader())) {
            Response::error('Security token mismatch. Please reload the page.', 419);
        }
        return Workspace::forUser($uid);
    }

    public static function page(): void
    {
        Session::start();
        if (Auth::userId() === null) {
            Response::redirect('/login');
        }
    }
}
