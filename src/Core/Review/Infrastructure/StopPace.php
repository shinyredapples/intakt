<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One per reviewer call and never shared, like StreamedTextBatch, because it remembers when it last asked.
#[Exclude]
final class StopPace
{
    // Once a second, because progress arrives with every streamed delta and each look reads the ledger, while a person pressing stop does not need the answer sooner.
    public const string INTERVAL = '1 second';

    private ?DateTimeImmutable $lastAsked = null;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function due(): bool
    {
        $now = $this->clock->now();

        if ($this->lastAsked instanceof DateTimeImmutable && $now < $this->lastAsked->modify('+'.self::INTERVAL)) {
            return false;
        }

        $this->lastAsked = $now;

        return true;
    }
}
