<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class JudgeDone
{
    /**
     * @param list<string>            $act
     * @param list<string>            $followUps
     * @param list<JudgeDoneFinding>  $findings
     * @param list<DroppedFinding>    $dropped
     * @param array<string, string>   $sameCause
     * @param array<string, Decision> $votes
     * @param array<string, ?string>  $p1Keys
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
        // Each reviewer's vote as the quorum counts it, by reviewer id, so the console, the loop and the review page decide one outcome; empty by default, because a judge_done row written before votes were counted holds none.
        public array $votes = [],
        // Each P1 finding's key by finding id, null for one that gets none, so a later round can tell the same finding on unchanged files; empty by default, because a judge_done row written before keys were recorded holds none.
        public array $p1Keys = [],
        // True when the judge left a finding without its mark, which is what the fallback for an answer that cannot be read does (it acts on every P1 and P2 and marks no P3), so the dashboard does not count an unmarked P3 as the judge's rejection; null on a row written before it was recorded.
        public ?bool $fallback = null,
    ) {
    }
}
