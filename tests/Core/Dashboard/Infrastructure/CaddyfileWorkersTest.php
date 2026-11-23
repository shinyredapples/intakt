<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Closure;
use Generator;
use Intakt\Tests\Support\WorkerPorts;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

    // One megabyte, because a worker holding its booted kernel (about 5 MB) is past it and a new worker before its first request (about 0.5 MB) is not.
    private const int CEILING_MEGABYTES = 1;

    private const int CEILING_BYTES = self::CEILING_MEGABYTES * 1_048_576;

    // One value limits both polls, so the request-count wait and the memory wait share a deadline.
    private const int SECONDS_TO_FINISH = 10;

    private string $frankenphp;

    private Process $server;

    private string $directory;

    private string $origin;

    private string $admin;

    private HttpClientInterface $client;

    public function testTheDashboardIsServedByTheAppWorkerAndTheMcpRouteByTheMcpWorker(): void
    {
        $this->start();
        $response = $this->client->request('GET', $this->origin.'/projects');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['index.php' => 1, 'mcp.php' => 0], $this->requestsPerWorkerFile(1));

        $this->client->request('POST', $this->origin.'/mcp/owner')->getStatusCode();

        self::assertSame(['index.php' => 1, 'mcp.php' => 1], $this->requestsPerWorkerFile(2));
    }

    public function testAWorkerPastTheMemoryCeilingIsReplacedWithoutAFailedRequest(): void
    {
        $this->start(['WORKER_MEMORY_CEILING_MB' => (string) self::CEILING_MEGABYTES]);

        $statuses = [];

        for ($request = 0; $request < 10; ++$request) {
            // Read so the server's debug log never fills its pipe and blocks the next answer.
            $this->server->getIncrementalErrorOutput();
            $statuses[] = $this->client->request('GET', $this->origin.'/projects')->getStatusCode();
        }

        self::assertSame(array_fill(0, 10, 200), $statuses);
        self::assertTrue($this->server->isRunning());
        self::assertLessThan(self::CEILING_BYTES, $this->bytesHeldByTheAppWorkers());
    }

    public static function testAMissingMemoryReadingIsNotTakenForAReplacedWorker(): void
    {
        $thread = ['Name' => 'Worker /app/public/index.php', 'RequestCount' => 10];

        self::assertSame(PHP_INT_MAX, self::largestMemoryOfAppWorkers([$thread]));
        self::assertSame(PHP_INT_MAX, self::largestMemoryOfAppWorkers([]));
        self::assertSame(526_224, self::largestMemoryOfAppWorkers([$thread + ['MemoryUsage' => 526_224]]));
    }

    public static function testRequestsAreCountedOnceTheWorkerHasFinishedTheLastAnsweredOne(): void
    {
        $reads = self::threadReads([self::appThreadWithRequests(9), self::appThreadWithRequests(10)]);

        $counts = self::requestsOnceServed(static fn (): array => self::nextRead($reads), 10);

        self::assertSame(['index.php' => 10, 'mcp.php' => 0], $counts);
    }

    public static function testRequestsAlreadyCountedAreReadOnce(): void
    {
        $reads = self::threadReads([self::appThreadWithRequests(10)]);

        $counts = self::requestsOnceServed(static fn (): array => self::nextRead($reads), 10);

        self::assertSame(['index.php' => 10, 'mcp.php' => 0], $counts);
    }

    public function testTheHelpersThatReadNoServerStayPrivateStatic(): void
    {
        foreach (['requestsOnceServed', 'largestMemoryOfAppWorkers'] as $name) {
            $method = new ReflectionMethod(self::class, $name);

            // Static callers keep them static, because Rector turns a private static helper with no static caller into an instance method.
            self::assertTrue($method->isPrivate() && $method->isStatic(), $name.' must be private static.');
        }
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

    /**
     * @param array<string, string> $extraEnvironment
     */
    private function start(array $extraEnvironment = []): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $this->frankenphp = $frankenphp;
        $ports = WorkerPorts::ofThisWorker();
        $port = $ports->next();
        $this->origin = 'http://localhost:'.$port;
        $this->admin = 'localhost:'.$ports->next();
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
        ] + $extraEnvironment;
        $this->migrate($environment);
        $this->server = new Process([$this->frankenphp, 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'], dirname(__DIR__, 4), $environment);
        $this->server->start();
        $this->client = HttpClient::create();
        $this->waitForTheWorkers();
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
    private function requestsPerWorkerFile(int $served): array
    {
        return self::requestsOnceServed($this->threads(...), $served);
    }

    // A worker that ends inside its last request is never counted, so the sign is memory: a replaced worker has not booted the kernel yet, and one that served past the ceiling still holds it. Polled, because the worker ends only after its answer is sent.
    private function bytesHeldByTheAppWorkers(): int
    {
        $deadline = hrtime(true) + self::SECONDS_TO_FINISH * 1_000_000_000;

        do {
            $held = $this->largestServingAppWorkerMemory();

            if ($held < self::CEILING_BYTES) {
                return $held;
            }

            usleep(100_000);
        } while (hrtime(true) < $deadline);

        return $held;
    }

    private function largestServingAppWorkerMemory(): int
    {
        return self::largestMemoryOfAppWorkers($this->threads());
    }

    /**
     * @param list<array<mixed>> $threads
     */
    // No app worker with a memory reading counts as past the ceiling, so a missing observation can never pass as a replacement.
    private static function largestMemoryOfAppWorkers(array $threads): int
    {
        $held = null;

        foreach ($threads as $thread) {
            $name = $thread['Name'] ?? null;
            $memory = $thread['MemoryUsage'] ?? null;

            if (is_string($name) && str_starts_with($name, 'Worker') && 'index.php' === basename($name) && is_int($memory)) {
                $held = max($held ?? 0, $memory);
            }
        }

        return $held ?? PHP_INT_MAX;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function appThreadWithRequests(int $requests): array
    {
        return [['Name' => 'Worker /app/public/index.php', 'RequestCount' => $requests]];
    }

    /**
     * @param list<list<array<mixed>>> $reads
     *
     * @return Generator<int, list<array<mixed>>>
     */
    private static function threadReads(array $reads): Generator
    {
        yield from $reads;
    }

    /**
     * @param Generator<int, list<array<mixed>>> $reads
     *
     * @return list<array<mixed>>
     */
    private static function nextRead(Generator $reads): array
    {
        self::assertTrue($reads->valid(), 'The thread states were read more often than the test scripted.');
        $read = $reads->current();
        $reads->next();

        return $read;
    }

    /**
     * @param Closure(): list<array<mixed>> $threads
     *
     * @return array<string, int>
     */
    // Polled, because FrankenPHP counts a request only when the worker has finished it, after its answer is sent.
    private static function requestsOnceServed(Closure $threads, int $served): array
    {
        $deadline = hrtime(true) + self::SECONDS_TO_FINISH * 1_000_000_000;

        do {
            $counts = self::requestsPerWorkerFileOf($threads());

            if (array_sum($counts) >= $served) {
                return $counts;
            }

            usleep(100_000);
        } while (hrtime(true) < $deadline);

        return $counts;
    }

    /**
     * @param list<array<mixed>> $threads
     *
     * @return array<string, int>
     */
    // Counted by the file each thread runs, because the admin API names worker threads by file, not by worker name.
    private static function requestsPerWorkerFileOf(array $threads): array
    {
        $counts = ['index.php' => 0, 'mcp.php' => 0];

        foreach ($threads as $thread) {
            $name = $thread['Name'] ?? null;
            $requests = $thread['RequestCount'] ?? null;

            if (is_string($name) && is_int($requests) && str_starts_with($name, 'Worker') && isset($counts[basename($name)])) {
                $counts[basename($name)] += $requests;
            }
        }

        return $counts;
    }

    /**
     * @return list<array<mixed>>
     */
    private function threads(): array
    {
        $threads = $this->client->request('GET', 'http://'.$this->admin.'/frankenphp/threads')->toArray()['ThreadDebugStates'] ?? [];
        self::assertIsArray($threads);

        return array_values(array_filter($threads, is_array(...)));
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
