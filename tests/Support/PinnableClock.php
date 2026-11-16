<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

// Real time until a test pins it, so a test moves the clock the container already created while kernel tests that wait on a deadline still wait.
final class PinnableClock implements ClockInterface
{
    private ?MockClock $pinned = null;

    public function __construct(private readonly ClockInterface $real)
    {
    }

    public function pinAt(string $moment): void
    {
        $this->pinned = new MockClock($moment);
    }

    public function now(): DateTimeImmutable
    {
        return ($this->pinned ?? $this->real)->now();
    }

    public function sleep(float|int $seconds): void
    {
        ($this->pinned ?? $this->real)->sleep($seconds);
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        $clock = new self($this->real->withTimeZone($timezone));
        $clock->pinned = $this->pinned?->withTimeZone($timezone);

        return $clock;
    }
}
