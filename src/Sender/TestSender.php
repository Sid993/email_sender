<?php

declare(strict_types=1);

namespace App\Sender;

use App\Mail\MessageBuilder;
use App\Mail\SmtpConnection;
use App\Mail\SmtpSettings;

/**
 * Sends test emails (all addresses in parallel, one connection each) and reports
 * the per-address SMTP outcome. Uses the same non-blocking client as campaigns.
 */
final class TestSender
{
    /** @return list<array{email:string, ok:bool, code:int, message:string}> */
    public static function send(SmtpSettings $smtp, string $subject, string $html, array $addresses, array $sampleRow): array
    {
        $builder = new MessageBuilder($smtp, $subject, $html, 'test' . bin2hex(random_bytes(4)));
        $address = $smtp->resolveAddress();
        $results = [];
        $conns = [];

        foreach (array_values($addresses) as $i => $addr) {
            try {
                [$mime] = $builder->build($addr, $sampleRow ?: ['email' => $addr], (string) $i, '[TEST] ');
            } catch (\Throwable $e) {
                $results[$i] = ['email' => $addr, 'ok' => false, 'code' => 0, 'message' => $e->getMessage()];
                continue;
            }
            $conn = new SmtpConnection($smtp, $address, static function (?array $item, array $r) use (&$results): void {
                if ($item === null) {
                    return;
                }
                $results[$item['i']] = [
                    'email' => $item['email'],
                    'ok' => $r['type'] === 'ok',
                    'code' => $r['code'],
                    'message' => $r['type'] === 'ok' ? 'Accepted by the server' : $r['message'],
                ];
            });
            $conn->assign(['i' => $i, 'email' => $addr], $mime);
            $conn->connect();
            $conns[$i] = $conn;
        }

        $deadline = microtime(true) + $smtp->timeout + 10;
        while (count($results) < count($addresses) && microtime(true) < $deadline) {
            self::poll($conns, 0.1);
        }
        foreach ($conns as $c) {
            $c->quit();
        }
        $quitBy = microtime(true) + 1.5;
        while (array_filter($conns, static fn ($c) => !$c->isClosed()) && microtime(true) < $quitBy) {
            self::poll($conns, 0.05);
        }
        foreach ($conns as $i => $c) {
            $c->close();
            $results[$i] ??= ['email' => $addresses[$i], 'ok' => false, 'code' => 0, 'message' => 'Timed out waiting for the server.'];
        }
        ksort($results);
        return array_values($results);
    }

    /** @param array<int, SmtpConnection> $conns */
    private static function poll(array $conns, float $timeout): void
    {
        $read = $write = [];
        foreach ($conns as $i => $c) {
            if (($s = $c->stream()) !== null) {
                $read[$i] = $s;
                if ($c->wantsWrite()) {
                    $write[$i] = $s;
                }
            }
        }
        if (!$read) {
            usleep((int) ($timeout * 1e6));
        } else {
            $except = null;
            @stream_select($read, $write, $except, 0, (int) ($timeout * 1e6));
        }
        $r = array_flip(array_map('intval', $read));
        $w = array_flip(array_map('intval', $write));
        $now = microtime(true);
        foreach ($conns as $c) {
            if (($s = $c->stream()) !== null) {
                $c->pump(isset($r[(int) $s]), isset($w[(int) $s]));
            }
            $c->tick($now);
        }
    }
}
