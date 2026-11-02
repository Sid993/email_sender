<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;
use App\Http\Session;

/**
 * Account registration / login backed by MySQL. Each login gets its own regenerated
 * PHP session; every per-user resource is resolved from the session's user id only.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 300;

    public static function userId(): ?int
    {
        $id = Session::get('user_id');
        return is_int($id) ? $id : null;
    }

    public static function user(): ?array
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        return ['id' => $id, 'name' => (string) Session::get('user_name', ''), 'email' => (string) Session::get('user_email', '')];
    }

    /** @return string|null error message, null on success */
    public static function register(string $name, string $email, string $password, string $confirm): ?string
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Please enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return 'Please enter a valid email address.';
        }
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            return 'Passwords do not match.';
        }
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            return 'An account with this email already exists.';
        }
        try {
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (\PDOException) {
            return 'An account with this email already exists.';
        }
        self::loginAs((int) $pdo->lastInsertId(), $name, $email);
        return null;
    }

    public static function attempt(string $email, string $password): ?string
    {
        $email = strtolower(trim($email));
        $lock = Session::get('_login_lock', 0);
        if ($lock > time()) {
            return 'Too many failed attempts. Please wait a few minutes and try again.';
        }
        $stmt = Database::pdo()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $fails = (int) Session::get('_login_fails', 0) + 1;
            Session::set('_login_fails', $fails);
            if ($fails >= self::MAX_ATTEMPTS) {
                Session::set('_login_lock', time() + self::LOCK_SECONDS);
                Session::set('_login_fails', 0);
            }
            usleep(300000);
            return 'Invalid email or password.';
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        self::loginAs((int) $row['id'], $row['name'], $row['email']);
        return null;
    }

    private static function loginAs(int $id, string $name, string $email): void
    {
        Session::regenerate();
        Session::forget('_login_fails');
        Session::forget('_login_lock');
        Session::set('user_id', $id);
        Session::set('user_name', $name);
        Session::set('user_email', $email);
        try {
            Database::pdo()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
        } catch (\Throwable) {
        }
    }
}
