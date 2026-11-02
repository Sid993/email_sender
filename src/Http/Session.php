<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;

/**
 * Hardened PHP session wrapper.
 *
 * PHP's default file sessions LOCK for the whole request. Long or frequent requests
 * (status polling, uploads) therefore call release() / startReadOnly() so one user's
 * polling never blocks that same user's Pause/Cancel clicks.
 */
final class Session
{
    private static bool $started = false;

    private static function options(): array
    {
        $lifetime = Config::int('SESSION_LIFETIME', 480) * 60;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('BULKMAILSESS');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return [
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'gc_maxlifetime' => $lifetime,
        ];
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        session_start(self::options());
        self::$started = true;
        self::expireIfIdle();
    }

    /** Opens the session, copies data and releases the lock immediately. */
    public static function startReadOnly(): void
    {
        if (self::$started) {
            return;
        }
        session_start(self::options() + ['read_and_close' => true]);
        self::$started = true;
    }

    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private static function expireIfIdle(): void
    {
        $now = time();
        $last = $_SESSION['_last_seen'] ?? $now;
        if ($now - $last > Config::int('SESSION_LIFETIME', 480) * 60) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_seen'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
            session_destroy();
        }
        self::$started = false;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
}
