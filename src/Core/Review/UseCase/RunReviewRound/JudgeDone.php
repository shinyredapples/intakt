<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

final readonly class JudgeDone
{
    /**
     * @param list<string>        $act
     * @param list<string>        $followUps
     * @param list<JudgedFinding> $findings
     */
    public function __construct(
        public string $round,
        public array $act,
        public array $followUps,
        public array $findings,
    ) {
    }
}
