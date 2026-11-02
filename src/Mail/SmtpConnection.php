<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * One NON-BLOCKING SMTP client connection, driven by an explicit state machine.
 *
 * Why not cURL / PHPMailer / Symfony Mailer? They all block somewhere in the SMTP
 * conversation (libcurl blocks while waiting for the final reply after DATA), which
 * silently serialises "parallel" sends. Here every phase (TCP connect, TLS handshake,
 * EHLO, STARTTLS, AUTH, MAIL/RCPT/DATA, body upload, final reply) is non-blocking, so
 * many connections progress at the same time inside one stream_select() loop.
 *
 * A connection is reused for many messages (MAIL FROM ... DATA repeated), which avoids
 * re-doing TCP + TLS + AUTH for every email.
 *
 * Every finished message is reported through the $onResult callback with:
 *   type: ok | transient | permanent | fatal | unknown
 *   code: SMTP reply code (0 = no reply / network)
 *   message, connection (network-level problem), throttle (provider asked us to slow
 *   down), requeue (failed before anything was sent on a reused connection; safe to
 *   retry without counting an attempt).
 */
final class SmtpConnection
{
    public const MAX_MESSAGES_PER_CONNECTION = 100;
    private const IDLE_CLOSE_AFTER = 45.0;

    private const CONNECTING = 'connecting';
    private const TLS = 'tls';
    private const GREETING = 'greeting';
    private const EHLO = 'ehlo';
    private const HELO = 'helo';
    private const STARTTLS = 'starttls';
    private const AUTH = 'auth';
    private const AUTH_LOGIN_USER = 'auth_login_user';
    private const AUTH_LOGIN_PASS = 'auth_login_pass';
    private const READY = 'ready';
    private const MAIL = 'mail';
    private const RCPT = 'rcpt';
    private const DATA = 'data';
    private const BODY = 'body';
    private const RSET = 'rset';
    private const QUIT = 'quit';
    private const CLOSED = 'closed';

    /** @var resource|null */
    private $sock = null;
    private string $state = self::CLOSED;
    private string $in = '';
    private string $out = '';
    private array $replyLines = [];
    private bool $tlsActive = false;
    private array $caps = [];
    private float $deadline = INF;
    private float $idleSince = 0.0;
    private ?array $item = null;
    private string $payload = '';
    private bool $bodyQueued = false;
    private int $sent = 0;
    private bool $reused = false;
    /** @var callable(?array, array): void */
    private $onResult;

    public function __construct(private readonly SmtpSettings $s, private readonly string $address, callable $onResult)
    {
        $this->onResult = $onResult;
    }

    /** Starts the (non-blocking) TCP connection. Assign the first message before calling. */
    public function connect(): void
    {
        $this->open();
    }

    // ------------------------------------------------------------------ public

    /** @return resource|null */
    public function stream()
    {
        return $this->sock;
    }

    public function isClosed(): bool
    {
        return $this->state === self::CLOSED;
    }

    /** Ready (or still connecting) and not carrying a message. */
    public function isIdle(): bool
    {
        return $this->item === null && $this->state === self::READY;
    }

    /** Past the greeting/handshake: the server has accepted this session. */
    public function isEstablished(): bool
    {
        return !in_array($this->state, [self::CONNECTING, self::TLS, self::GREETING, self::CLOSED], true);
    }

    public function hasItem(): bool
    {
        return $this->item !== null;
    }

    public function wantsWrite(): bool
    {
        return $this->state === self::CONNECTING || $this->out !== '';
    }

    public function item(): ?array
    {
        return $this->item;
    }

    /** Hands a message to this connection; it is sent as soon as the session is ready. */
    public function assign(array $item, string $mime): void
    {
        $this->item = $item;
        $body = str_replace(["\r\n", "\r"], "\n", $mime);
        $body = str_replace("\n", "\r\n", $body);
        if (!str_ends_with($body, "\r\n")) {
            $body .= "\r\n";
        }
        // Dot-stuffing (RFC 5321 4.5.2): a line starting with "." gets an extra ".".
        $body = (str_starts_with($body, '.') ? '.' : '') . str_replace("\r\n.", "\r\n..", $body);
        $this->payload = $body . ".\r\n";
        if ($this->state === self::READY) {
            $this->beginTransaction();
        }
    }

    /** Drive the connection: called every loop pass (readable/writable from stream_select). */
    public function pump(bool $readable, bool $writable): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        if ($this->state === self::CONNECTING) {
            if (!$readable && !$writable) {
                return;
            }
            if (@stream_socket_get_name($this->sock, true) === false) {
                $this->failConnection('Could not connect to ' . $this->s->host . ':' . $this->s->port . ' (connection refused or unreachable).');
                return;
            }
            if ($this->s->security === 'ssl') {
                $this->enter(self::TLS, $this->s->timeout);
            } else {
                $this->enter(self::GREETING, $this->s->timeout);
            }
        }
        if ($this->state === self::TLS) {
            $this->stepTls();
            if ($this->state === self::TLS || $this->state === self::CLOSED) {
                return;
            }
        }
        $this->flush();
        if ($this->state === self::CLOSED) {
            return;
        }
        // Always try to read: TLS streams can hold decrypted data that select() doesn't see.
        $this->read();
    }

    /** Timeouts and idle housekeeping. */
    public function tick(float $now): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        if ($this->state === self::READY && $this->item === null) {
            if ($now - $this->idleSince > self::IDLE_CLOSE_AFTER) {
                $this->quit();
            }
            return;
        }
        if ($now > $this->deadline) {
            if ($this->state === self::QUIT) {
                $this->close();
                return;
            }
            $this->failConnection(sprintf('Timed out after %ds waiting for the server (%s).', $this->s->timeout, $this->state));
        }
    }

    /** Polite shutdown of an idle connection. */
    public function quit(): void
    {
        if ($this->state === self::READY && $this->item === null) {
            $this->send('QUIT');
            $this->enter(self::QUIT, 3);
        } elseif ($this->item === null) {
            $this->close();
        }
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
        $this->state = self::CLOSED;
    }

    // ----------------------------------------------------------------- network

    private function open(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'peer_name' => $this->s->host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ]]);
        $target = filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$this->address}]" : $this->address;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            "tcp://{$target}:{$this->s->port}",
            $errno,
            $errstr,
            min(30, $this->s->timeout),
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $ctx
        );
        if ($sock === false) {
            $this->state = self::CONNECTING; // so failConnection reports it correctly
            $this->failConnection("Could not connect to {$this->s->host}:{$this->s->port}: " . ($errstr ?: "error $errno"));
            return;
        }
        stream_set_blocking($sock, false);
        $this->sock = $sock;
        $this->enter(self::CONNECTING, min(30, $this->s->timeout));
    }

    private function stepTls(): void
    {
        error_clear_last();
        $r = @stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($r === 0) {
            return; // handshake in progress
        }
        if ($r === false) {
            $why = error_get_last()['message'] ?? 'TLS handshake failed';
            $why = preg_replace('/^stream_socket_enable_crypto\(\): /', '', $why);
            $this->failConnection('TLS/SSL handshake failed: ' . $why . '. Check the TLS option and port.', 'fatal');
            return;
        }
        $this->tlsActive = true;
        if ($this->s->security === 'ssl') {
            $this->enter(self::GREETING, $this->s->timeout);
        } else {
            $this->sendEhlo(); // STARTTLS: say EHLO again over the encrypted channel
        }
    }

    private function flush(): void
    {
        while ($this->out !== '') {
            $n = @fwrite($this->sock, $this->out);
            if ($n === false) {
                $this->handleEof();
                return;
            }
            if ($n === 0) {
                return; // socket buffer full, continue on next writable
            }
            $this->out = substr($this->out, $n);
            $this->deadline = microtime(true) + $this->s->timeout; // progress resets the timer
        }
    }

    private function read(): void
    {
        $chunk = @fread($this->sock, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->sock))) {
            $this->handleEof();
            return;
        }
        if ($chunk === '') {
            return;
        }
        $this->in .= $chunk;
        while (($pos = strpos($this->in, "\n")) !== false) {
            $line = rtrim(substr($this->in, 0, $pos), "\r");
            $this->in = substr($this->in, $pos + 1);
            $this->replyLines[] = $line;
            // Final line of a reply: "250 text" (a "250-text" line means more follow).
            if (strlen($line) < 4 || $line[3] !== '-') {
                $code = (int) substr($line, 0, 3);
                $lines = array_map(static fn ($l) => substr($l, 4), $this->replyLines);
                $this->replyLines = [];
                $this->onReply($code, $lines);
                if ($this->state === self::CLOSED || $this->state === self::TLS) {
                    return;
                }
            }
        }
    }

    private function send(string $command): void
    {
        $this->out .= $command . "\r\n";
        $this->flush();
    }

    private function enter(string $state, float $timeout): void
    {
        $this->state = $state;
        $this->deadline = microtime(true) + $timeout;
    }

    // ------------------------------------------------------------ SMTP dialogue

    private function onReply(int $code, array $lines): void
    {
        $text = trim(implode(' ', $lines));
        $short = $code . ($text !== '' ? ' ' . mb_substr($text, 0, 300) : '');

        // 421 = server is shutting the channel (often "too many connections / slow down").
        if ($code === 421 && $this->state !== self::QUIT) {
            $this->failConnection("Server closed the connection: $short", 'transient', 421, true);
            return;
        }

        switch ($this->state) {
            case self::GREETING:
                if ($code === 220) {
                    $this->sendEhlo();
                } elseif ($code >= 500) {
                    $this->failConnection("Server refused the connection: $short", 'fatal', $code);
                } else {
                    $this->failConnection("Server is not accepting connections: $short", 'transient', $code, true);
                }
                return;

            case self::EHLO:
                if ($code === 250) {
                    $this->parseCaps($lines);
                    $this->afterEhlo();
                } else {
                    $this->send('HELO ' . self::heloName());
                    $this->enter(self::HELO, $this->s->timeout);
                }
                return;

            case self::HELO:
                if ($code === 250) {
                    $this->caps = [];
                    $this->afterEhlo();
                } else {
                    $this->failConnection("Server rejected EHLO/HELO: $short", 'fatal', $code);
                }
                return;

            case self::STARTTLS:
                if ($code === 220) {
                    $this->enter(self::TLS, $this->s->timeout);
                    $this->stepTls();
                } else {
                    $this->failConnection("STARTTLS was refused: $short. Try the SSL/TLS option or another port.", 'fatal', $code);
                }
                return;

            case self::AUTH_LOGIN_USER:
                if ($code === 334) {
                    $this->send(base64_encode($this->s->username));
                    $this->enter(self::AUTH_LOGIN_PASS, $this->s->timeout);
                } else {
                    $this->authFailed($code, $short);
                }
                return;

            case self::AUTH_LOGIN_PASS:
                if ($code === 334) {
                    $this->send(base64_encode($this->s->password));
                    $this->enter(self::AUTH, $this->s->timeout);
                } else {
                    $this->authFailed($code, $short);
                }
                return;

            case self::AUTH:
                $code === 235 ? $this->becomeReady() : $this->authFailed($code, $short);
                return;

            case self::MAIL:
                if ($code === 250) {
                    $this->send('RCPT TO:<' . $this->item['email'] . '>');
                    $this->enter(self::RCPT, $this->s->timeout);
                } elseif ($code === 530) {
                    $this->finishWith('fatal', $code, "The server requires authentication: $short. Enter a username and password.", true);
                } elseif ($code >= 500 && $code !== 552) {
                    // The sender is rejected: every message would fail the same way.
                    $this->finishWith('fatal', $code, "The server rejected the From address: $short", true);
                } else {
                    $this->finishWith($code >= 500 ? 'permanent' : 'transient', $code, $short, true);
                }
                return;

            case self::RCPT:
                if ($code === 250 || $code === 251) {
                    $this->send('DATA');
                    $this->enter(self::DATA, $this->s->timeout);
                } else {
                    $this->finishWith($code >= 500 ? 'permanent' : 'transient', $code, "Recipient rejected: $short", true);
                }
                return;

            case self::DATA:
                if ($code === 354) {
                    $this->enter(self::BODY, $this->s->timeout);
                    $this->bodyQueued = true;
                    $this->out .= $this->payload;
                    $this->flush();
                } else {
                    $this->finishWith($code >= 500 ? 'permanent' : 'transient', $code, "DATA refused: $short", true);
                }
                return;

            case self::BODY:
                $this->bodyQueued = false;
                $this->sent++;
                $this->reused = true;
                if ($code >= 200 && $code < 300) {
                    $this->finishWith('ok', $code, $short, false);
                } else {
                    $this->finishWith($code >= 500 ? 'permanent' : 'transient', $code, "Message rejected: $short", false);
                }
                return;

            case self::RSET:
                $code === 250 ? $this->becomeReady() : $this->close();
                return;

            case self::QUIT:
                $this->close();
                return;

            default:
                // Unsolicited reply (e.g. while idle): the server is probably closing.
                if ($code >= 400) {
                    $this->close();
                }
        }
    }

    private function sendEhlo(): void
    {
        $this->send('EHLO ' . self::heloName());
        $this->enter(self::EHLO, $this->s->timeout);
    }

    private function afterEhlo(): void
    {
        if ($this->s->security === 'starttls' && !$this->tlsActive) {
            if (!isset($this->caps['STARTTLS'])) {
                $this->failConnection('The server does not support STARTTLS on this port. Choose "SSL/TLS" (port 465) or "None".', 'fatal');
                return;
            }
            $this->send('STARTTLS');
            $this->enter(self::STARTTLS, $this->s->timeout);
            return;
        }
        if ($this->s->username === '') {
            $this->becomeReady();
            return;
        }
        $mechs = $this->caps['AUTH'] ?? [];
        if (in_array('PLAIN', $mechs, true)) {
            $this->send('AUTH PLAIN ' . base64_encode("\0" . $this->s->username . "\0" . $this->s->password));
            $this->enter(self::AUTH, $this->s->timeout);
        } elseif (in_array('LOGIN', $mechs, true)) {
            $this->send('AUTH LOGIN');
            $this->enter(self::AUTH_LOGIN_USER, $this->s->timeout);
        } elseif (!$mechs && !$this->tlsActive) {
            $this->failConnection('The server does not offer login on an unencrypted connection. Choose STARTTLS or SSL/TLS.', 'fatal');
        } else {
            $this->failConnection('The server does not support PLAIN or LOGIN authentication (offers: ' . (implode(', ', $mechs) ?: 'none') . ').', 'fatal');
        }
    }

    private function authFailed(int $code, string $short): void
    {
        if ($code >= 400 && $code < 500) {
            $this->failConnection("Temporary authentication failure: $short", 'transient', $code);
        } else {
            $this->failConnection("Login failed: $short. Check the SMTP username and password.", 'fatal', $code);
        }
    }

    private function parseCaps(array $lines): void
    {
        $this->caps = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = preg_split('/[\s=]+/', strtoupper(trim($line))) ?: [];
            $key = array_shift($parts);
            if ($key !== null && $key !== '') {
                $this->caps[$key] = array_merge($this->caps[$key] ?? [], $parts);
            }
        }
    }

    private function becomeReady(): void
    {
        $this->state = self::READY;
        $this->deadline = INF;
        $this->idleSince = microtime(true);
        if ($this->item !== null) {
            $this->beginTransaction();
        } elseif ($this->sent >= self::MAX_MESSAGES_PER_CONNECTION) {
            $this->quit();
        }
    }

    private function beginTransaction(): void
    {
        $params = '';
        if (isset($this->caps['SMTPUTF8']) && !mb_check_encoding($this->s->fromEmail . $this->item['email'], 'ASCII')) {
            $params .= ' SMTPUTF8';
        }
        $this->bodyQueued = false;
        $this->send('MAIL FROM:<' . $this->s->fromEmail . '>' . $params);
        $this->enter(self::MAIL, $this->s->timeout);
    }

    /** Reports the current message and, if the transaction is still open, resets it. */
    private function finishWith(string $type, int $code, string $message, bool $rset): void
    {
        $item = $this->item;
        $this->item = null;
        $this->payload = '';
        ($this->onResult)($item, ['type' => $type, 'code' => $code, 'message' => $message, 'connection' => false, 'throttle' => false, 'requeue' => false]);
        if ($this->state === self::CLOSED) {
            return;
        }
        if ($rset) {
            $this->send('RSET');
            $this->enter(self::RSET, $this->s->timeout);
        } else {
            $this->becomeReady();
        }
    }

    private function handleEof(): void
    {
        if ($this->state === self::QUIT || ($this->state === self::READY && $this->item === null)) {
            $this->close(); // normal close / idle timeout by the server
            return;
        }
        $this->failConnection('The server closed the connection unexpectedly.');
    }

    /** Ends the connection; the message it was carrying (if any) gets the right outcome. */
    private function failConnection(string $message, string $type = 'transient', int $code = 0, bool $throttle = false): void
    {
        $item = $this->item;
        $state = $this->state;
        $wholeBodySent = $state === self::BODY && $this->bodyQueued && $this->out === '';
        $this->item = null;
        $this->payload = '';
        $this->close();

        $result = ['type' => $type, 'code' => $code, 'message' => $message, 'connection' => $code === 0, 'throttle' => $throttle, 'requeue' => false];
        if ($item !== null && $type === 'transient' && $wholeBodySent) {
            // The server may already have accepted it: never risk a duplicate.
            $result['type'] = 'unknown';
            $result['message'] = 'Connection lost after the message was sent; delivery status unknown (not re-sent to avoid a duplicate). ' . $message;
        } elseif ($item !== null && $type === 'transient' && $this->reused && $state === self::MAIL && $code === 0) {
            // A reused idle connection had been dropped by the server: nothing was sent.
            $result['requeue'] = true;
        }
        ($this->onResult)($item, $result);
    }

    private static function heloName(): string
    {
        static $name = null;
        if ($name === null) {
            $h = (string) gethostname();
            $name = preg_match('/^[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $h) ? $h : 'localhost';
        }
        return $name;
    }
}
