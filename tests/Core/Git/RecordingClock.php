<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

// Because a test must never wait: it records each sleep and runs what should happen meanwhile.
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
        return new MockClock('2026-11-10 12:00:00 UTC')->now();
    }

    public function sleep(float|int $seconds): void
    {
        $this->sleeps[] = $seconds;

        if ($this->meanwhile instanceof Closure) {
            ($this->meanwhile)(count($this->sleeps));
        }
    }

    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        return clone $this;
    }
}
