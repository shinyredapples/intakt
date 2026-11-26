<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the PHPStan rule intakt.flagParameter accepts the boolean `fallback` only as data a readonly value object marked #[Exclude] stores.
#[Exclude]
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
        // Each P1 finding's key by finding id, null for one that gets none, so a later round can tell the same finding on unchanged files; empty by default, because a judge_done row written before keys were recorded holds none.
        public P1Keys $p1Keys = new P1Keys(),
        // True when the fallback for an answer that cannot be read stood in for the judge (it acts on every P1 and P2 and marks no P3), so the dashboard counts no fallback's marks as the judge's calls; null on a row written before it was recorded.
        public ?bool $fallback = null,
        // Whether plain code or a model call marked the round, so every reader can tell which path ran; a model call by default, because before it was recorded every round with a finding left after the quote check asked the model.
        public VerdictPath $path = VerdictPath::ModelCall,
    ) {
    }
}
