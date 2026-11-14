<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Matched by id because the judge's answer is data, and prose of a finding is never read; a P1 or P2 is always on the act list whatever its mark, and a P3 with no mark or marked reject is on neither list.
#[Exclude]
final readonly class JudgedFindings
{
    /**
     * @param list<Finding>  $findings
     * @param list<Finding>  $actList
     * @param list<FollowUp> $followUps
     */
    private function __construct(
        public array $findings,
        public array $actList,
        public array $followUps,
    ) {
    }

    /**
     * @param list<Finding> $findings
     */
    public static function of(Judgement $judgement, array $findings): self
    {
        $marks = [];

        foreach ($judgement->marks as $judged) {
            $marks[$judged->id] = $judged->mark;
        }

        $blocking = static fn (Finding $finding): bool => Severity::P3 !== $finding->severity;
        $actList = array_values(array_filter($findings, static fn (Finding $finding): bool => $blocking($finding) || Mark::Act === ($marks[$finding->id] ?? null)));
        $deferred = array_values(array_filter($findings, static fn (Finding $finding): bool => !$blocking($finding) && Mark::Defer === ($marks[$finding->id] ?? null)));

        return new self($findings, $actList, array_map(static fn (Finding $finding): FollowUp => new FollowUp($finding), $deferred));
    }
}
