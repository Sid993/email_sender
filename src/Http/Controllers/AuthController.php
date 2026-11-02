<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Storage\Workspace;

final class AuthController
{
    public function showLogin(): never
    {
        Session::start();
        if (Auth::userId() !== null) {
            Response::redirect('/');
        }
        Response::view('login', ['error' => Session::flash('error'), 'notice' => Session::flash('notice'), 'old' => ['email' => Session::flash('old_email') ?? '']]);
    }

    public function login(): never
    {
        Session::start();
        if (!Session::verifyCsrf(Request::csrfHeader())) {
            Session::flash('error', 'Your session expired. Please try again.');
            Response::redirect('/login');
        }
        $email = Request::str('email');
        $error = Auth::attempt($email, (string) Request::input('password', ''));
        if ($error !== null) {
            Session::flash('error', $error);
            Session::flash('old_email', $email);
            Response::redirect('/login');
        }
        Response::redirect('/');
    }

    public function showRegister(): never
    {
        Session::start();
        if (Auth::userId() !== null) {
            Response::redirect('/');
        }
        Response::view('register', ['error' => Session::flash('error'), 'old' => json_decode(Session::flash('old') ?? '[]', true) ?: []]);
    }

    public function register(): never
    {
        Session::start();
        if (!Session::verifyCsrf(Request::csrfHeader())) {
            Session::flash('error', 'Your session expired. Please try again.');
            Response::redirect('/register');
        }
        $name = Request::str('name');
        $email = Request::str('email');
        $error = Auth::register($name, $email, (string) Request::input('password', ''), (string) Request::input('password_confirmation', ''));
        if ($error !== null) {
            Session::flash('error', $error);
            Session::flash('old', json_encode(['name' => $name, 'email' => $email]));
            Response::redirect('/register');
        }
        Response::redirect('/');
    }

    public function logout(): never
    {
        Session::start();
        $uid = Auth::userId();
        if ($uid !== null && Session::verifyCsrf(Request::csrfHeader())) {
            $ws = Workspace::forUser($uid);
            // A running campaign keeps going in the background; idle temp data is removed.
            if (!$ws->isWorkerRunning() && !in_array($ws->status()['state'] ?? '', Workspace::ACTIVE_STATES, true)) {
                $ws->deleteAll();
            }
        }
        Session::destroy();
        Session::start();
        Session::flash('notice', 'You have been logged out.');
        Response::redirect('/login');
    }
}
