<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Intakt\Core\Ledger\Contract\RecordedEvent;

final readonly class ReviewRows
{
    /**
     * @param list<string>        $rounds
     * @param list<RecordedEvent> $events
     * @param list<string>        $models
     */
    public function __construct(
        public array $rounds,
        public array $events,
        public array $models,
    ) {
    }
}
