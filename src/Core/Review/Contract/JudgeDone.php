<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class JudgeDone
{
    /**
     * @param list<string>           $act
     * @param list<string>           $followUps
     * @param list<JudgeDoneFinding> $findings
     * @param list<DroppedFinding>   $dropped
     */
    public function __construct(
        public string $round,
        public array $act,
        public array $followUps,
        public array $findings,
        // Empty by default, because a judge_done row written before quotes were checked holds no dropped findings.
        public array $dropped = [],
    ) {
    }
}
