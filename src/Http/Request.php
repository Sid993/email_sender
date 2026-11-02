<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    private static ?array $json = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        return $path;
    }

    public static function json(): array
    {
        if (self::$json === null) {
            $raw = file_get_contents('php://input') ?: '';
            $data = json_decode($raw, true);
            self::$json = is_array($data) ? $data : [];
        }
        return self::$json;
    }

    /** Reads from JSON body, then POST, then GET. */
    public static function input(string $key, mixed $default = null): mixed
    {
        $json = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? self::json() : [];
        return $json[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function csrfHeader(): ?string
    {
        return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
    }
}
