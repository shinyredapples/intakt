<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Ledger\Contract\Ledger;
use Symfony\Component\Clock\ClockInterface;

// Notes the clock's moment and the ledger's row count at each publish, so a test reads when a page received an update and which rows it could already load.
final class ClockedLiveUpdates implements LiveUpdates
{
    /** @var list<array{at: string, topic: string, stream: string, rows: int}> */
    private array $published = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Ledger $ledger,
    ) {
    }

    public function publish(LiveEvent $event): bool
    {
        $this->published[] = [
            'at' => $this->clock->now()->format('H:i:s.u'),
            'topic' => $event->topic,
            'stream' => $event->turboStream,
            'rows' => count(iterator_to_array($this->ledger->readInOrder(), false)),
        ];

        return true;
    }

    /**
     * @return list<array{at: string, topic: string, stream: string, rows: int}>
     */
    public function published(): array
    {
        return $this->published;
    }
}
