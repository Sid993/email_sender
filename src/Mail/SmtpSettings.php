<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Immutable SMTP + sender settings for one campaign / test send.
 */
final class SmtpSettings
{
    public const SECURITY = ['none', 'starttls', 'ssl'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $security,
        public readonly string $username,
        public readonly string $password,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly int $timeout = 30,
        public readonly string $unsubscribe = '',
    ) {
    }

    public static function fromArray(array $c, string $password): self
    {
        return new self(
            trim((string) ($c['host'] ?? '')),
            (int) ($c['port'] ?? 0),
            (string) ($c['security'] ?? 'starttls'),
            trim((string) ($c['username'] ?? '')),
            $password,
            trim((string) ($c['from_email'] ?? '')),
            trim((string) ($c['from_name'] ?? '')),
            max(5, (int) ($c['timeout'] ?? 30)),
            trim((string) ($c['unsubscribe'] ?? '')),
        );
    }

    /**
     * Resolves the server name once per campaign, so opening many connections never
     * blocks the event loop on DNS. TLS still verifies the certificate against the name.
     */
    public function resolveAddress(): string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP)) {
            return $this->host;
        }
        $ips = @gethostbynamel($this->host);
        return $ips ? $ips[0] : $this->host;
    }

    /** @return list<string> validation errors */
    #[\NoDiscard('Validation errors must be checked')]
    public function validate(): array
    {
        $e = [];
        if ($this->host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $this->host)) {
            $e[] = 'Server is required (e.g. smtp.example.com).';
        }
        if ($this->port < 1 || $this->port > 65535) {
            $e[] = 'Port must be between 1 and 65535.';
        }
        if (!in_array($this->security, self::SECURITY, true)) {
            $e[] = 'Please select a TLS option.';
        }
        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            $e[] = 'From Email must be a valid email address.';
        }
        if (preg_match('/[\r\n]/', $this->fromName)) {
            $e[] = 'From Name contains invalid characters.';
        }
        if ($this->username !== '' && $this->password === '') {
            $e[] = 'Password is required when a username is set.';
        }
        if ($this->unsubscribe !== '' && !preg_match('~^(mailto:|https?://)\S+$~i', $this->unsubscribe)) {
            $e[] = 'Unsubscribe must start with mailto: or https://';
        }
        return $e;
    }
}
