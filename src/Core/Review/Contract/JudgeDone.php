<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class JudgeDone
{
    /**
     * @param array<int|string, string> $act
     * @param list<string>              $followUps
     * @param list<JudgeDoneFinding>    $findings
     */
    public function __construct(
        public string $round,
        public array $act,
        public array $followUps,
        public array $findings,
    ) {
    }
}
