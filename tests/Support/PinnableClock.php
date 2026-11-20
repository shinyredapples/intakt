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

    private bool $working = false;

    // A time-zone view runs the work through the clock it came from, so work that sleeps through any view finds the one guard already raised.
    private ?self $origin = null;

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
        $clock = $this->origin ?? $this;
        $clock->onSleep = $work;
    }

    public function now(): DateTimeImmutable
    {
        return ($this->pinned ?? $this->real)->now();
    }

    public function sleep(float|int $seconds): void
    {
        ($this->origin ?? $this)->work();

        ($this->pinned ?? $this->real)->sleep($seconds);
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        $clock = new self($this->real->withTimeZone($timezone));
        $clock->pinned = $this->pinned?->withTimeZone($timezone);
        $clock->origin = $this->origin ?? $this;

        return $clock;
    }

    // A handler the work runs may sleep on this clock too; it must not start the work again inside itself.
    private function work(): void
    {
        if (!$this->onSleep instanceof Closure || $this->working) {
            return;
        }

        $this->working = true;

        try {
            ($this->onSleep)();
        } finally {
            $this->working = false;
        }
    }
}
