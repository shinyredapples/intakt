<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Symfony\Component\HttpClient\Response\MockResponse;

// Answers the engine's log call for the fake engine, so the proxy's standard output and the job's standard error stay apart as on a real engine.
final class FakeContainerLogs
{
    // A passed start check, as docker/job/start-check.sh logs it, so a test that plays no log plays only the job after it.
    private const string PASSED_START_CHECK = "intakt-start-check: full mode\n";

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
        $framed = str_starts_with($container->name, 'intakt-egress-')
            ? $this->framedIf('stdout', $query, 1, $this->proxyLogs[$container->job] ?? '')
            : $this->framedIf('stderr', $query, 2, $this->standardErrors[$container->job] ?? self::PASSED_START_CHECK);

        if (in_array($container->job, $this->broken, true)) {
            $framed = pack('CxxxN', 1, 100).'short';
        }

        return new MockResponse($framed, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/vnd.docker.multiplexed-stream']]);
    }

    /**
     * @param array<int|string, mixed> $query
     */
    private function framedIf(string $asked, array $query, int $stream, string $log): string
    {
        if ('true' !== ($query[$asked] ?? null)) {
            return '';
        }

        $lines = preg_split('/(?<=\n)/', $log, flags: PREG_SPLIT_NO_EMPTY);

        return implode('', array_map(static fn (string $line): string => pack('CxxxN', $stream, strlen($line)).$line, false === $lines ? [] : $lines));
    }
}
