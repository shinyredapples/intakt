<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\RecordedEvent;

final readonly class ReviewRows
{
    /**
     * @param non-empty-list<string> $rounds
     * @param list<RecordedEvent>    $events
     * @param list<string>           $models
     */
    public function __construct(
        public DateTimeImmutable $startedAt,
        public array $rounds,
        public array $events,
        public array $models,
    ) {
    }
}
