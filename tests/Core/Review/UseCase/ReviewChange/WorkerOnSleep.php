<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

// Lets the workers run while the command waits: each sleep of the command is the moment a worker takes its turn, so no test sleeps for real.
final readonly class WorkerOnSleep implements ClockInterface
{
    // A set $sleepsFor moves the clock that far on each sleep instead of the asked seconds, because the real upper wait is hours and a test should reach it in a few polls.
    /**
     * @param Closure(): void $work
     */
    public function __construct(private MockClock $clock, private Closure $work, private ?int $sleepsFor = null)
    {
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    #[Override]
    public function sleep(float|int $seconds): void
    {
        ($this->work)();
        $this->clock->sleep($this->sleepsFor ?? $seconds);
    }

    #[Override]
    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return new self($this->clock->withTimeZone($timezone), $this->work, $this->sleepsFor);
    }
}
