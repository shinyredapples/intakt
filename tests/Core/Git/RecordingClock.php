<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

// A clock that never waits: it records each sleep and runs what the test wants to happen meanwhile.
final class RecordingClock implements ClockInterface
{
    /** @var list<float|int> */
    public array $sleeps = [];

    public function __construct(
        private readonly ?Closure $meanwhile = null,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-10 12:00:00 UTC');
    }

    public function sleep(float|int $seconds): void
    {
        $this->sleeps[] = $seconds;

        if (null !== $this->meanwhile) {
            ($this->meanwhile)(count($this->sleeps));
        }
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return clone $this;
    }
}
