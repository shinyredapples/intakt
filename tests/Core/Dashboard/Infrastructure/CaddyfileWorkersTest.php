<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Against a running FrankenPHP, because which worker serves a request is decided by FrankenPHP's matching, which the adapted config alone does not prove.
#[Group('hub')]
#[CoversNothing]
final class CaddyfileWorkersTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    // Longer than the hub test waits, because the workers boot the kernel and may warm its cache first.
    private const int SECONDS_TO_WAIT = 60;

    private Process $server;

    private string $directory;

    private string $origin;

    private string $admin;

    private HttpClientInterface $client;

    protected function setUp(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $port = $this->freePort();
        $this->origin = 'http://localhost:'.$port;
        $this->admin = 'localhost:'.$this->freePort();
        $this->directory = sys_get_temp_dir().'/intakt-workers-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $environment = [
            'SERVER_PORT' => (string) $port,
            'DASHBOARD_PORT' => (string) $port,
            'DATABASE_URL' => 'sqlite:///'.$this->directory.'/intakt.db',
            'CADDY_ADMIN' => $this->admin,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => $this->origin,
            'MERCURE_BOLT_PATH' => $this->directory.'/hub.db',
        ];
        $this->migrate($environment);
        $this->server = new Process([$frankenphp, 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'], dirname(__DIR__, 4), $environment);
        $this->server->start();
        $this->client = HttpClient::create();
        $this->waitForTheWorkers();
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop(5);
        }

        if (isset($this->directory)) {
            new Filesystem()->remove($this->directory);
        }
    }

    public function testTheDashboardIsServedByTheAppWorker(): void
    {
        $response = $this->client->request('GET', $this->origin.'/projects');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['index.php' => 1, 'mcp.php' => 0], $this->requestsPerWorkerFile());
    }

    public function testTheMcpRouteIsServedByTheMcpWorker(): void
    {
        $this->client->request('POST', $this->origin.'/mcp/owner')->getStatusCode();

        self::assertSame(['index.php' => 0, 'mcp.php' => 1], $this->requestsPerWorkerFile());
    }

    /**
     * @param array<string, string> $environment
     */
    private function migrate(array $environment): void
    {
        $php = new PhpExecutableFinder()->find();
        self::assertNotFalse($php);
        new Process([$php, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], dirname(__DIR__, 4), $environment)->mustRun();
    }

    /**
     * @return array<string, int>
     */
    // Counted by the file each thread runs, because the admin API names worker threads by file, not by worker name.
    private function requestsPerWorkerFile(): array
    {
        $threads = $this->client->request('GET', 'http://'.$this->admin.'/frankenphp/threads')->toArray()['ThreadDebugStates'] ?? [];
        self::assertIsArray($threads);
        $counts = ['index.php' => 0, 'mcp.php' => 0];

        foreach (array_filter($threads, is_array(...)) as $thread) {
            $name = $thread['Name'] ?? null;
            $requests = $thread['RequestCount'] ?? null;

            if (is_string($name) && is_int($requests) && str_starts_with($name, 'Worker') && isset($counts[basename($name)])) {
                $counts[basename($name)] += $requests;
            }
        }

        return $counts;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertNotFalse($socket);
        $port = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }

    private function waitForTheWorkers(): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && $this->server->isRunning()) {
            $connection = @fsockopen('127.0.0.1', (int) parse_url($this->origin, PHP_URL_PORT), $code, $message, 0.2);

            if (false !== $connection) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('FrankenPHP did not start: '.$this->server->getErrorOutput());
    }
}
