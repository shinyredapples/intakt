<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

// The dashboard on a port of its own, because a reviewer reads the change through its /mcp/review and the dashboard refuses any host name or port but the one DASHBOARD_PORT names, which the instance's own server does not listen on.
final readonly class ReviewServer
{
    private const string ROOT = __DIR__.'/../../..';
    private const float BOOT_SECONDS = 20.0;

    private function __construct(private Process $process, public int $port)
    {
    }

    public static function start(): self
    {
        $port = self::freePort();
        // E in variables_order, because without it the runtime would never see the instance's environment (as in bin/instance's built-in server).
        $process = new Process([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:'.$port, '-t', self::ROOT.'/public', self::ROOT.'/public/index.php'], self::ROOT, ['DASHBOARD_PORT' => (string) $port]);
        $process->start();
        $server = new self($process, $port);
        $until = MonotonicSeconds::now() + self::BOOT_SECONDS;

        while (MonotonicSeconds::now() < $until) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

            if (false !== $socket) {
                fclose($socket);

                return $server;
            }

            usleep(50000);
        }

        $server->stop();

        throw new RuntimeException('The dashboard did not listen on port '.$port.': '.$process->getErrorOutput());
    }

    public function address(): string
    {
        return 'http://localhost:'.$this->port;
    }

    public function stop(): void
    {
        $this->process->stop(2);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

        if (false === $socket) {
            throw new RuntimeException('No free port: '.$message);
        }

        $port = (int) array_last(explode(':', (string) stream_socket_get_name($socket, false)));
        fclose($socket);

        return $port;
    }
}
