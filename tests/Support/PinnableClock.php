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

    public function __construct(private readonly ClockInterface $real)
    {
    }

    public function pinAt(string $moment): void
    {
        $this->pinned = new MockClock($moment);
    }

    /**
     * Lets a test run a worker while a command of the container waits on this clock, as a worker process would run beside it.
     *
     * @param Closure(): void $work
     */
    public function workOnEachSleep(Closure $work): void
    {
        $this->onSleep = $work;
    }

    public function now(): DateTimeImmutable
    {
        return ($this->pinned ?? $this->real)->now();
    }

    public function sleep(float|int $seconds): void
    {
        // A handler the work runs may sleep on this clock too; it must not start the work again inside itself.
        if ($this->onSleep instanceof Closure && !$this->working) {
            $this->working = true;

            try {
                ($this->onSleep)();
            } finally {
                $this->working = false;
            }
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
