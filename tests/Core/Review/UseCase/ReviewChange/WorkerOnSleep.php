<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Closure;
use Override;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

// Lets the workers run while the command waits: each sleep of the command is the moment a worker takes its turn, so no test sleeps for real.
final readonly class WorkerOnSleep implements ClockInterface
{
    /**
     * @param Closure(): void $work
     */
    public function __construct(private MockClock $clock, private Closure $work)
    {
    }

    #[Override]
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    #[Override]
    public function sleep(float|int $seconds): void
    {
        ($this->work)();
        $this->clock->sleep($seconds);
    }

    #[Override]
    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return new self($this->clock->withTimeZone($timezone), $this->work);
    }
}
