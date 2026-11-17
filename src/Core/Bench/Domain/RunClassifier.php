<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Finding;

final readonly class RunClassifier
{
    public function __construct(private DefectMatcher $matcher)
    {
    }

    public function classify(Expectations $expectations, ChangeFiles $change, RunOutcome $outcome): RunVerdict
    {
        if (!$outcome->completedNormally()) {
            return new RunVerdict($expectations->isDefectFree(), [], $this->allMissed($expectations, FailureCause::Harness), [], [], $outcome->harnessFailure);
        }

        $caught = [];
        $missed = [];
        $valid = [];
        foreach ($expectations->defects as $defect) {
            // A range the change does not hold is a wrong expectation, so no finding may count as catching it.
            if (!$change->holdsRangeOf($defect)) {
                $missed[$defect->id] = FailureCause::Bench;
                continue;
            }

            $valid[] = $defect;
            if ($this->anyFindingMatches($outcome->findings, $defect)) {
                $caught[] = $defect->id;
                continue;
            }

            $missed[$defect->id] = FailureCause::Reviewer;
        }

        $unmatched = $this->unmatchedFindings($valid, $outcome->findings);

        return new RunVerdict(
            $expectations->isDefectFree(),
            $caught,
            $missed,
            $expectations->isDefectFree() ? $unmatched : [],
            $expectations->isDefectFree() ? [] : $unmatched,
            null,
        );
    }

    /**
     * @return array<string, FailureCause>
     */
    private function allMissed(Expectations $expectations, FailureCause $cause): array
    {
        $missed = [];
        foreach ($expectations->defects as $defect) {
            $missed[$defect->id] = $cause;
        }

        return $missed;
    }

    /**
     * @param list<Finding> $findings
     */
    private function anyFindingMatches(array $findings, PlantedDefect $defect): bool
    {
        return array_any($findings, fn (Finding $finding): bool => $this->matcher->matches($finding, $defect));
    }

    /**
     * @param list<PlantedDefect> $defects
     * @param list<Finding>       $findings
     *
     * @return list<Finding>
     */
    private function unmatchedFindings(array $defects, array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn (Finding $finding): bool => !array_any($defects, fn (PlantedDefect $defect): bool => $this->matcher->matches($finding, $defect)),
        ));
    }
}
