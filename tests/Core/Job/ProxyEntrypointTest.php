<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\EgressProxy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Runs the proxy sidecar's entrypoint with the real tinyproxy, because only the real proxy shows which log lines it writes and where.
#[CoversNothing]
final class ProxyEntrypointTest extends TestCase
{
    private const string ENTRYPOINT = __DIR__.'/../../../docker/job-proxy/entrypoint.sh';

    private string $folder = '';
    private ?Process $proxy = null;

    protected function setUp(): void
    {
        if (null === new ExecutableFinder()->find('tinyproxy')) {
            self::markTestSkipped('tinyproxy is not on the PATH, see docs/architecture/requirements.md.');
        }

        $this->folder = sys_get_temp_dir().'/intakt-proxy-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        $this->proxy?->stop(0);
        new Filesystem()->remove($this->folder);
    }

    public function testAHostOutsideTheAllowlistIsRefusedAndTheRefusalIsInTheLogTheRunnerReads(): void
    {
        $response = $this->connectThroughTheProxy('evil.example');

        self::assertStringStartsWith('HTTP/1.0 403', $response);
        self::assertSame(1, preg_match('/Proxying refused on filtered domain "evil\.example"$/', implode("\n", $this->refusals())));
    }

    public function testAnAllowedHostIsNotRefused(): void
    {
        $this->connectThroughTheProxy('api.example');

        self::assertSame([], $this->refusals());
    }

    private function connectThroughTheProxy(string $host): string
    {
        $port = $this->freePort();
        $this->proxy = new Process(['sh', self::ENTRYPOINT], null, ['INTAKT_PROXY_ALLOW' => 'api.example,registry.example', 'INTAKT_PROXY_PORT' => (string) $port, 'TMPDIR' => $this->folder]);
        $this->proxy->start();
        $socket = $this->connected($port);
        stream_set_timeout($socket, 5);
        fwrite($socket, "CONNECT {$host}:443 HTTP/1.0\r\n\r\n");
        $response = (string) fgets($socket);
        fclose($socket);
        $this->proxy->stop(1);

        return $response;
    }

    /**
     * @return list<string>
     */
    private function refusals(): array
    {
        return new EgressProxy('p')->refusalsIn((string) $this->proxy?->getOutput());
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if (false === $server) {
            throw new RuntimeException('No free port.');
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return resource
     */
    private function connected(int $port)
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $code, $error, 0.2);

            if (false !== $socket) {
                return $socket;
            }

            usleep(100_000);
        }

        throw new RuntimeException('The proxy did not listen: '.$this->proxy?->getErrorOutput());
    }
}
