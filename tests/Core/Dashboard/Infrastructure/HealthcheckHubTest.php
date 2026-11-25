<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Tests\Support\WorkerPorts;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

// Against a running FrankenPHP whose listening port differs from the dashboard port, because that is how the image runs when the owner publishes `-p 127.0.0.1:8080:7781`.
#[Group('hub')]
#[CoversNothing]
final class HealthcheckHubTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    private const int SECONDS_TO_WAIT = 60;

    private const string PUBLISHED_PORT = '8080';

    private Process $server;

    private string $directory;

    private string $port;

    private string $admin;

    public function testTheHealthCheckPassesAgainstAServerWhoseListeningPortDiffersFromTheDashboardPort(): void
    {
        $this->start();

        // From the project root, as the image's working directory, because the script runs bin/console; the admin URL follows CADDY_ADMIN there.
        $process = new Process([dirname(__DIR__, 4).'/docker/healthcheck.sh'], dirname(__DIR__, 4), [...$this->environment(), 'FRANKENPHP_ADMIN_URL' => false]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame("healthy\n", $process->getOutput());
    }

    public function testTheServerAnswersARequestOnItsPortWithTheHostOfTheDashboardPort(): void
    {
        $this->start();

        $response = HttpClient::create()->request('GET', 'http://localhost:'.$this->port.'/', ['headers' => ['Host' => 'localhost:'.self::PUBLISHED_PORT]]);

        self::assertSame(200, $response->getStatusCode());
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

    private function start(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $ports = WorkerPorts::ofThisWorker();
        $this->port = (string) $ports->next();
        $this->admin = 'localhost:'.$ports->next();
        $this->directory = sys_get_temp_dir().'/intakt-healthcheck-hub-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        $php = new PhpExecutableFinder()->find();
        self::assertNotFalse($php);
        new Process([$php, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], dirname(__DIR__, 4), $this->environment())->mustRun();
        $this->server = new Process([$frankenphp, 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'], dirname(__DIR__, 4), $this->environment());
        $this->server->start();
        $this->waitForTheServer();
    }

    /**
     * @return array<string, false|string>
     */
    private function environment(): array
    {
        return [
            'SERVER_PORT' => $this->port,
            'DASHBOARD_PORT' => self::PUBLISHED_PORT,
            'DATABASE_URL' => 'sqlite:///'.$this->directory.'/intakt.db',
            'CADDY_ADMIN' => $this->admin,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => 'http://localhost:'.$this->port,
            'MERCURE_BOLT_PATH' => $this->directory.'/hub.db',
            'INTAKT_SAMPLE_REPOSITORY' => false,
        ];
    }

    private function waitForTheServer(): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && $this->server->isRunning()) {
            $connection = @fsockopen('127.0.0.1', (int) $this->port, $code, $message, 0.2);

            if (false !== $connection) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('FrankenPHP did not start: '.$this->server->getErrorOutput());
    }
}
