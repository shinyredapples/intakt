<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Matched by id because the judge's answer is data, and prose of a finding is never read; a P1 or P2 is always on the act list whatever its mark (a defer or reject keeps only the judge's note, as given, because the ledger redacts it when it stores it), and a P3 with no mark or marked reject is on neither list.
#[Exclude]
final readonly class JudgedFindings
{
    /**
     * @param list<Finding>        $findings
     * @param list<Finding>        $actList
     * @param list<FollowUp>       $followUps
     * @param list<DroppedFinding> $dropped
     * @param list<Finding>        $rejected
     */
    private function __construct(
        public array $findings,
        public array $actList,
        public array $followUps,
        public array $dropped,
        public SameCause $sameCause,
        // The P3 findings the judge marked reject, apart from the ones it gave no mark, because only a judge's own mark lets a changes verdict count as approval.
        public array $rejected,
        public JudgeNotes $notes,
        // True when the fallback for an answer that cannot be read stands in for the judge, taken from the judgement that chose it, because the marks cannot tell: a fallback over P1 and P2 findings alone marks every one.
        public bool $fallback,
    ) {
    }

    /**
     * @param list<Finding>        $findings
     * @param list<DroppedFinding> $dropped
     */
    public static function of(Judgement $judgement, array $findings, array $dropped = []): self
    {
        $marks = [];
        $sameCause = new SameCause();
        $given = array_map(static fn (Finding $finding): string => $finding->id, $findings);

        foreach ($judgement->marks as $judged) {
            $marks[$judged->id] = $judged->mark;
            if (null !== $judged->same_cause_as && $judged->same_cause_as !== $judged->id && in_array($judged->id, $given, true) && in_array($judged->same_cause_as, $given, true)) {
                $sameCause = $sameCause->with($judged->id, $judged->same_cause_as);
            }
        }

        $blocking = static fn (Finding $finding): bool => Severity::P3 !== $finding->severity;
        $actList = array_values(array_filter($findings, static fn (Finding $finding): bool => $blocking($finding) || Mark::Act === ($marks[$finding->id] ?? null)));
        $deferred = array_values(array_filter($findings, static fn (Finding $finding): bool => !$blocking($finding) && Mark::Defer === ($marks[$finding->id] ?? null)));
        $rejected = array_values(array_filter($findings, static fn (Finding $finding): bool => !$blocking($finding) && Mark::Reject === ($marks[$finding->id] ?? null)));

        return new self($findings, $actList, array_map(static fn (Finding $finding): FollowUp => new FollowUp($finding), $deferred), $dropped, $sameCause, $rejected, self::notesOf($judgement, $findings), $judgement->fallback());
    }

    // Only a P1 or P2 marked defer or reject keeps a note, because plain code does not apply that mark (the finding stays on the act list), so the note takes the place of the mark.
    /**
     * @param list<Finding> $findings
     */
    private static function notesOf(Judgement $judgement, array $findings): JudgeNotes
    {
        $blocking = array_map(static fn (Finding $finding): string => $finding->id, array_filter($findings, static fn (Finding $finding): bool => Severity::P3 !== $finding->severity));
        $notes = new JudgeNotes();

        foreach ($judgement->marks as $judged) {
            if (null !== $judged->note && Mark::Act !== $judged->mark && in_array($judged->id, $blocking, true)) {
                $notes = $notes->with($judged->id, $judged->note);
            }
        }

        return $notes;
    }
}
