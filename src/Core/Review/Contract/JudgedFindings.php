<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Matched by id because the judge's answer is data, and prose of a finding is never read; a P1 or P2 is always on the act list whatever its mark, a reject or defer of a P1 or P2 leaves a note with its reason, and a P3 with no mark or marked reject is on neither list.
#[Exclude]
final readonly class JudgedFindings
{
    /**
     * @param list<Finding>         $findings
     * @param list<Finding>         $actList
     * @param list<FollowUp>        $followUps
     * @param list<DroppedFinding>  $dropped
     * @param array<string, string> $sameCause
     * @param array<string, string> $judgeNotes
     */
    private function __construct(
        public array $findings,
        public array $actList,
        public array $followUps,
        public array $dropped,
        public array $sameCause,
        public array $judgeNotes = [],
    ) {
    }

    /**
     * @param list<Finding>        $findings
     * @param list<DroppedFinding> $dropped
     */
    public static function of(Judgement $judgement, array $findings, array $dropped = []): self
    {
        $marks = [];
        $sameCause = [];
        $notes = [];
        $severities = [];
        foreach ($findings as $finding) {
            $severities[$finding->id] = $finding->severity;
        }
        $given = array_map(static fn (Finding $finding): string => $finding->id, $findings);

        foreach ($judgement->marks as $judged) {
            $marks[$judged->id] = $judged->mark;
            if (null !== $judged->same_cause_as && in_array($judged->id, $given, true) && in_array($judged->same_cause_as, $given, true)) {
                $sameCause[$judged->id] = $judged->same_cause_as;
            }

            $reason = null === $judged->reason ? '' : trim($judged->reason);
            $kept = Mark::Act !== $judged->mark && isset($severities[$judged->id]) && Severity::P3 !== $severities[$judged->id];
            if ($kept && '' !== $reason) {
                $notes[$judged->id] = $reason;
            }
        }

        $blocking = static fn (Finding $finding): bool => Severity::P3 !== $finding->severity;
        $actList = array_values(array_filter($findings, static fn (Finding $finding): bool => $blocking($finding) || Mark::Act === ($marks[$finding->id] ?? null)));
        $deferred = array_values(array_filter($findings, static fn (Finding $finding): bool => !$blocking($finding) && Mark::Defer === ($marks[$finding->id] ?? null)));

        return new self($findings, $actList, array_map(static fn (Finding $finding): FollowUp => new FollowUp($finding), $deferred), $dropped, $sameCause, $notes);
    }
}
