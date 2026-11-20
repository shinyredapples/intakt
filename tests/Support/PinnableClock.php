<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

// Real time until a test pins it, so a test moves the clock the container already created while kernel tests that wait on a deadline still wait.
final class PinnableClock implements ClockInterface
{
    private ?MockClock $pinned = null;

    /** @var (Closure(): void)|null */
    private ?Closure $onSleep = null;

    public function __construct(private readonly ClockInterface $real)
    {
    }

    public function pinAt(string $moment): void
    {
        $this->pinned = new MockClock($moment);
    }

    // A test runs a worker during a command's waits on this clock, because on an install a worker process runs beside the command.
    /**
     * @param Closure(): void $work
     */
    public function workOnEachSleep(Closure $work): void
    {
        // The guard lives in the closure, so every time-zone view of this clock shares it: work that sleeps through any view must not start itself again.
        $working = false;
        $this->onSleep = static function () use ($work, &$working): void {
            if ($working) {
                return;
            }

            $working = true;

            try {
                $work();
            } finally {
                $working = false;
            }
        };
    }

    public function now(): DateTimeImmutable
    {
        return ($this->pinned ?? $this->real)->now();
    }

    public function sleep(float|int $seconds): void
    {
        if ($this->onSleep instanceof Closure) {
            ($this->onSleep)();
        }

        ($this->pinned ?? $this->real)->sleep($seconds);
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        $clock = new self($this->real->withTimeZone($timezone));
        $clock->pinned = $this->pinned?->withTimeZone($timezone);
        $clock->onSleep = $this->onSleep;

        return $clock;
    }
}
