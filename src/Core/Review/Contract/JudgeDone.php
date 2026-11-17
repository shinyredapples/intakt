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
     * @param array<string, string>  $sameCause
     */
    public function __construct(
        public string $round,
        public array $act,
        public array $followUps,
        public array $findings,
        // Empty by default, because a judge_done row written before drop reasons were recorded holds no dropped findings.
        public array $dropped = [],
        // Empty by default, because a judge_done row written before the judge named causes links no findings.
        public array $sameCause = [],
    ) {
    }
}
