<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Symfony\Component\HttpClient\Response\MockResponse;

// Answers the engine's log call for the fake engine, so the proxy's standard output and the job's standard error stay apart as on a real engine.
final class FakeContainerLogs
{
    // Stands for the start check's own prefix with the run's mark, which only the container knows, so a test can tell the script's lines from lines the job forges.
    public const string CHECK = '{start-check}';

    // A passed start check, as docker/job/start-check.sh logs it, so a test that plays no log plays only the job after it.
    private const string PASSED_START_CHECK = self::CHECK."full mode\n";

    /** @var array<string, string> */
    private array $proxyLogs = [];

    /** @var array<string, string> */
    private array $standardErrors = [];

    /** @var list<string> */
    private array $broken = [];

    public function proxyLogs(string $job, string $log): void
    {
        $this->proxyLogs[$job] = $log;
    }

    public function jobStandardError(string $job, string $log): void
    {
        $this->standardErrors[$job] = $log;
    }

    public function breakFrames(string $job): void
    {
        $this->broken[] = $job;
    }

    /**
     * @param array<int|string, mixed> $query
     */
    // Framed as the engine frames the log of a container without a terminal (the stream, three zero bytes, the length, the chunk), so the client's frame reading is exercised; only the stream the call asks for is sent, as the engine does.
    public function answer(FakeContainer $container, array $query): MockResponse
    {
        $proxy = str_starts_with($container->name, 'intakt-egress-');
        $stream = $proxy ? 1 : 2;
        $asked = 'true' === ($query[$proxy ? 'stdout' : 'stderr'] ?? null);
        $played = $proxy ? $this->proxyLogs[$container->job] ?? '' : $this->standardErrors[$container->job] ?? self::PASSED_START_CHECK;
        $log = str_replace(self::CHECK, sprintf('intakt-start-check %s: ', $container->startCheckMark), $played);
        $framed = $asked ? $this->framed($stream, $log) : '';

        if (in_array($container->job, $this->broken, true)) {
            $framed = pack('CxxxN', 1, 100).'short';
        }

        return new MockResponse($framed, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/vnd.docker.multiplexed-stream']]);
    }

    private function framed(int $stream, string $log): string
    {
        $lines = preg_split('/(?<=\n)/', $log, flags: PREG_SPLIT_NO_EMPTY);

        return implode('', array_map(static fn (string $line): string => pack('CxxxN', $stream, strlen($line)).$line, false === $lines ? [] : $lines));
    }
}
