<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal .env loader + typed accessors. Real environment variables win over .env values.
 */
final class Config
{
    private static array $values = [];
    private static bool $loaded = false;
    public static string $root = '';

    public static function load(string $root): void
    {
        self::$root = rtrim($root, '/');
        $file = self::$root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                self::$values[$k] = $v;
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $v = self::$values[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function storagePath(string $sub = ''): string
    {
        $base = self::get('STORAGE_PATH', 'storage');
        if ($base[0] !== '/') {
            $base = self::$root . '/' . $base;
        }
        return rtrim($base, '/') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public static function appKey(): string
    {
        $raw = self::get('APP_KEY');
        if ($raw === null) {
            throw new \RuntimeException('APP_KEY is not set. Run: php bin/generate-key.php');
        }
        $key = base64_decode(preg_replace('/^base64:/', '', $raw), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY must be 32 bytes, base64 encoded.');
        }
        return $key;
    }
}
