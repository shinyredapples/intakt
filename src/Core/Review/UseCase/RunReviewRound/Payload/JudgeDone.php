<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound\Payload;

final readonly class JudgeDone
{
    /**
     * @param list<string>           $act
     * @param list<string>           $followUps
     * @param list<JudgedFindingRow> $findings
     */
    public function __construct(
        public string $round,
        public array $act,
        public array $followUps,
        public array $findings,
    ) {
    }
}
