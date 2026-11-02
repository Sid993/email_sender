<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Session;
use App\Mail\SmtpSettings;

/**
 * The user's form settings, kept in the PHP session only (temporary, per user).
 * The SMTP password is stored encrypted and never sent back to the browser.
 */
final class CampaignConfig
{
    private const KEY = 'campaign_config';

    public const DEFAULTS = [
        'host' => '',
        'port' => '',
        'security' => '',
        'username' => '',
        'password_enc' => '',
        'from_name' => '',
        'from_email' => '',
        'subject' => '',
        'message' => '',
        'send_limit' => '10',
        'test_to' => '',
        'max_retries' => '3',
        'timeout' => '30',
        'rate_per_minute' => '0',
        'unsubscribe' => '',
    ];

    public static function get(): array
    {
        $c = Session::get(self::KEY, []);
        return array_merge(self::DEFAULTS, is_array($c) ? $c : []);
    }

    /** Merges submitted form values into the session copy. */
    public static function update(array $in): array
    {
        $c = self::get();
        foreach (self::DEFAULTS as $k => $_) {
            if ($k === 'password_enc' || !array_key_exists($k, $in) || !is_scalar($in[$k])) {
                continue;
            }
            $v = (string) $in[$k];
            $c[$k] = $k === 'message' ? $v : trim($v);
        }
        if (isset($in['password']) && is_string($in['password']) && $in['password'] !== '') {
            $c['password_enc'] = Crypto::encrypt($in['password']);
        }
        if (!empty($in['clear_password'])) {
            $c['password_enc'] = '';
        }
        Session::set(self::KEY, $c);
        return $c;
    }

    public static function password(array $c): string
    {
        return $c['password_enc'] !== '' ? (Crypto::decrypt($c['password_enc']) ?? '') : '';
    }

    public static function toPublic(array $c): array
    {
        $out = $c;
        unset($out['password_enc']);
        $out['has_password'] = $c['password_enc'] !== '';
        return $out;
    }

    public static function smtp(array $c): SmtpSettings
    {
        return SmtpSettings::fromArray([
            'host' => $c['host'],
            'port' => $c['port'],
            'security' => $c['security'],
            'username' => $c['username'],
            'from_email' => $c['from_email'],
            'from_name' => $c['from_name'],
            'timeout' => $c['timeout'],
            'unsubscribe' => $c['unsubscribe'],
        ], self::password($c));
    }

    /** @return list<string> */
    #[\NoDiscard('Validation errors must be checked')]
    public static function validateForSending(array $c): array
    {
        $errors = self::smtp($c)->validate();
        if (trim($c['subject']) === '') {
            $errors[] = 'Subject Line is required.';
        }
        if (trim($c['message']) === '') {
            $errors[] = 'Message is required.';
        }
        if (!ctype_digit((string) $c['send_limit']) || (int) $c['send_limit'] < 1) {
            $errors[] = 'Send Limit must be a whole number of at least 1 (the number of emails sent at the same time).';
        }
        if (!ctype_digit((string) $c['max_retries']) || (int) $c['max_retries'] > 10) {
            $errors[] = 'Max retries must be between 0 and 10.';
        }
        if (!ctype_digit((string) $c['timeout']) || (int) $c['timeout'] < 5 || (int) $c['timeout'] > 300) {
            $errors[] = 'Timeout must be between 5 and 300 seconds.';
        }
        if (!ctype_digit((string) $c['rate_per_minute'])) {
            $errors[] = 'Max emails per minute must be 0 (no limit) or a positive number.';
        }
        return $errors;
    }
}
