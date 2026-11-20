<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class JudgeDone
{
    /**
     * @param list<JudgeDoneFinding>  $findings
     * @param list<DroppedFinding>    $dropped
     * @param array<string, Decision> $votes
     */
    public function __construct(
        public string $round,
        public FindingIds $act,
        public FindingIds $followUps,
        public array $findings,
        // Empty by default, because a judge_done row written before drop reasons were recorded holds no dropped findings.
        public array $dropped = [],
        // Empty by default, because a judge_done row written before the judge named causes links no findings.
        public SameCause $sameCause = new SameCause(),
        // Each reviewer's vote as the quorum counts it, by reviewer id, so the console, the loop and the review page decide one outcome; empty by default, because a judge_done row written before votes were counted holds none.
        public array $votes = [],
    ) {
    }
}
