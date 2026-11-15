<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use DateTimeImmutable;

final readonly class Recording
{
    /**
     * @param list<string>              $models
     * @param list<RecordedLedgerEvent> $events
     * @param list<CapturedUpdate>      $updates
     */
    public function __construct(
        public DateTimeImmutable $reviewedAt,
        public string $intaktVersion,
        public array $models,
        public string $project,
        public string $change,
        public array $events,
        public array $updates,
    ) {
    }
}
