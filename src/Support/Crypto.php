<?php

declare(strict_types=1);

namespace App\Support;

use App\Config;

/**
 * Authenticated encryption (libsodium secretbox) for secrets kept in the session,
 * e.g. the SMTP password. Never stored in MySQL or on disk in plain text.
 */
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, Config::appKey()));
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, Config::appKey());
        return $plain === false ? null : $plain;
    }
}
