<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

// Read back from the files, because the calls ran in the consumer's worker processes and not in the journey's own.
final readonly class CallRecords
{
    /**
     * @param list<array{call: int, pid: int, started: int, start: float, end: ?float}> $calls
     */
    private function __construct(public array $calls)
    {
    }

    public static function in(string $directory): self
    {
        $calls = [];
        $files = glob($directory.'/calls/*.start');

        foreach (false === $files ? [] : $files as $file) {
            if (!self::written($file)) {
                continue;
            }

            $call = (int) basename($file, '.start');
            $end = $directory.'/calls/'.$call.'.end';
            $calls[] = ['call' => $call, 'pid' => self::pidIn($file), 'started' => self::processStartIn($file), 'start' => self::momentIn($file), 'end' => self::written($end) ? self::momentIn($end) : null];
        }

        usort($calls, static fn (array $a, array $b): int => $a['call'] <=> $b['call']);

        return new self($calls);
    }

    public function started(): int
    {
        return count($this->calls);
    }

    public function ended(): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => null !== $call['end']));
    }

    // A call without an end counts as running to the end, because a held call that never ended still overlapped everything after its start.
    public function mostAtOnce(): int
    {
        $moments = [];

        foreach ($this->calls as $call) {
            $moments[] = [$call['start'], 1];
            $moments[] = [$call['end'] ?? INF, -1];
        }

        // An end sorts before a start at the same moment, so a call that began as another ended never counts as overlapping it.
        usort($moments, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $running = 0;
        $most = 0;

        foreach ($moments as [, $change]) {
            $running += $change;
            $most = max($most, $running);
        }

        return $most;
    }

    /**
     * @return list<int>
     */
    public function processes(): array
    {
        return array_values(array_unique(array_column($this->calls, 'pid')));
    }

    /**
     * @return list<RecordedProcess>
     */
    public function workers(): array
    {
        $workers = [];

        foreach ($this->calls as $call) {
            $workers[$call['pid'].':'.$call['started']] = new RecordedProcess($call['pid'], $call['started']);
        }

        return array_values($workers);
    }

    // Skipped while empty, because a writer that does not publish atomically creates the name before its content, so the record is not there yet rather than broken.
    private static function written(string $file): bool
    {
        clearstatcache(true, $file);

        return is_file($file) && 0 < filesize($file);
    }

    private static function pidIn(string $file): int
    {
        $pid = self::read($file)['pid'] ?? null;

        return is_int($pid) ? $pid : throw new RuntimeException('The call record '.$file.' names no process.');
    }

    private static function processStartIn(string $file): int
    {
        $started = self::read($file)['started'] ?? null;

        return is_int($started) ? $started : throw new RuntimeException('The call record '.$file.' names no process start.');
    }

    private static function momentIn(string $file): float
    {
        $moment = self::read($file)['at'] ?? null;

        return is_float($moment) || is_int($moment) ? (float) $moment : throw new RuntimeException('The call record '.$file.' names no moment.');
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
