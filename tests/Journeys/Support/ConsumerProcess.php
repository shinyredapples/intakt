<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Process\Process;

// One concurrent messenger consumer of a journey, started as the image's entrypoint starts its async consumer, so a journey can stop it with a signal and time how long it takes to end.
final class ConsumerProcess
{
    // The image's default (WORKER_MEMORY_CEILING_MB in the Dockerfile), so the consumer ends on the same limit as an install's.
    private const int MEMORY_LIMIT_MB = 256;

    private const float POLL_SECONDS = 0.05;

    // Room for a shell between a consumer and its worker, so a worker started through one still counts while a far descendant does not.
    private const int WORKER_DEPTH = 4;

    private ?float $signalledAt = null;

    /**
     * @param Closure(int): ?ProcessEntry $entryOf
     */
    public function __construct(
        private readonly Process $process,
        private readonly ClockInterface $clock,
        private readonly Closure $entryOf,
    ) {
    }

    /**
     * @param array<string, string> $variables
     */
    public static function startedIn(string $projectDirectory, int $concurrency, array $variables): self
    {
        $process = new Process(self::command($concurrency), $projectDirectory, $variables, null, null);
        $process->start();

        return new self($process, new MonotonicClock(), new ProcParents()->entryOf(...));
    }

    /**
     * @return list<string>
     */
    public static function command(int $concurrency): array
    {
        return ['php', 'bin/console', 'messenger:consume', 'async', '--concurrency='.$concurrency, '--memory-limit='.self::MEMORY_LIMIT_MB.'M', '--time-limit=3600', '--quiet'];
    }

    public function signal(int $signal): void
    {
        $this->signalledAt = $this->now();
        $this->process->signal($signal);
    }

    // Null (late) whenever the measured time is over the limit, also when the consumer exits between the last poll and the check, because the time is measured after the exit is seen.
    public function endedWithin(float $seconds): ?float
    {
        $from = $this->signalledAt ?? $this->now();

        while ($this->process->isRunning() && $this->now() - $from <= $seconds) {
            $this->clock->sleep(self::POLL_SECONDS);
        }

        $ended = !$this->process->isRunning();
        $elapsed = $this->now() - $from;

        return $ended && $elapsed <= $seconds ? $elapsed : null;
    }

    // Asked before a journey kills a recorded worker (its PID and start time from its call record), so a PID the system gave to another process after the worker ended is never killed, also not a replacement worker of the same consumer.
    public function isWorker(int $pid, int $processStart): bool
    {
        $consumer = $this->process->getPid();
        // One read for the start time and the parent, because a PID reused between two reads would pair the recorded worker's start with the parent of the process that took its PID.
        $worker = ($this->entryOf)($pid);

        if (null === $consumer || !$this->process->isRunning() || null === $worker || $worker->start !== $processStart) {
            return false;
        }

        $parent = $worker->parent;
        $childStart = $worker->start;

        for ($depth = 1; $depth <= self::WORKER_DEPTH; ++$depth) {
            if ($consumer === $parent) {
                return true;
            }

            $entry = ($this->entryOf)($parent);

            // A parent always started before its child, so an entry that started later is a process that took a vanished parent's PID, not an ancestor.
            if (null === $entry || $entry->start > $childStart) {
                return false;
            }

            $parent = $entry->parent;
            $childStart = $entry->start;
        }

        return false;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
