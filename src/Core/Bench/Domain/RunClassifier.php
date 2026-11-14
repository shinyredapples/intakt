<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Bench\Contract\FailureCause;
use Intakt\Core\Bench\Contract\RunVerdict;
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
        foreach ($expectations->defects as $defect) {
            if ($this->anyFindingMatches($outcome->findings, $defect)) {
                $caught[] = $defect->id;
                continue;
            }

            $missed[$defect->id] = $change->holdsRangeOf($defect) ? FailureCause::Reviewer : FailureCause::Bench;
        }

        $unmatched = $this->unmatchedFindings($expectations, $outcome->findings);

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
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function unmatchedFindings(Expectations $expectations, array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn (Finding $finding): bool => !array_any($expectations->defects, fn (PlantedDefect $defect): bool => $this->matcher->matches($finding, $defect)),
        ));
    }
}
