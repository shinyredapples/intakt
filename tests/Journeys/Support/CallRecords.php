<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// What the scripted provider calls of one run wrote: each call's process, start and end, read back for the journey's assertions.
final readonly class CallRecords
{
    /**
     * @param list<array{call: int, pid: int, start: float, end: ?float}> $calls
     */
    private function __construct(public array $calls)
    {
    }

    public static function in(string $directory): self
    {
        $calls = [];

        foreach (glob($directory.'/calls/*.start') ?: [] as $file) {
            $call = (int) basename($file, '.start');
            $start = self::read($file);
            $end = is_file($directory.'/calls/'.$call.'.end') ? self::read($directory.'/calls/'.$call.'.end') : null;
            $calls[] = ['call' => $call, 'pid' => (int) ($start['pid'] ?? 0), 'start' => (float) ($start['at'] ?? 0.0), 'end' => null === $end ? null : (float) ($end['at'] ?? 0.0)];
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

    // The most calls that were running at the same moment; a call without an end counts as running to the end.
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
     * @return array<string,mixed>
     */
    private static function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
