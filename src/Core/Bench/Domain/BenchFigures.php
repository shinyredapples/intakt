<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

final readonly class BenchFigures
{
    /**
     * @param list<string> $harnessFailures
     */
    private function __construct(
        public int $planted,
        public int $caught,
        public int $missedByReviewer,
        public int $missedByBench,
        public int $cleanRuns,
        public int $cleanRunsWithFalseAlarm,
        public int $falseAlarms,
        public array $harnessFailures,
    ) {
    }

    /**
     * @param list<RunVerdict> $verdicts
     */
    public static function from(array $verdicts): self
    {
        $harness = array_values(array_filter($verdicts, static fn (RunVerdict $verdict): bool => $verdict->isHarnessFailure()));
        $counted = array_values(array_filter($verdicts, static fn (RunVerdict $verdict): bool => !$verdict->isHarnessFailure()));
        $clean = array_filter($counted, static fn (RunVerdict $verdict): bool => $verdict->defectFree);

        $missedBy = static fn (FailureCause $cause): int => array_sum(array_map(
            static fn (RunVerdict $verdict): int => count(array_filter($verdict->missed, static fn (FailureCause $missed): bool => $missed === $cause)),
            $counted,
        ));

        return new self(
            array_sum(array_map(static fn (RunVerdict $verdict): int => count($verdict->caught) + count($verdict->missed), $counted)),
            array_sum(array_map(static fn (RunVerdict $verdict): int => count($verdict->caught), $counted)),
            $missedBy(FailureCause::Reviewer),
            $missedBy(FailureCause::Bench),
            count($clean),
            count(array_filter($clean, static fn (RunVerdict $verdict): bool => [] !== $verdict->falseAlarms)),
            array_sum(array_map(static fn (RunVerdict $verdict): int => count($verdict->falseAlarms), $counted)),
            array_map(static fn (RunVerdict $verdict): string => (string) $verdict->harnessFailure, $harness),
        );
    }

    public function detectionRate(): ?float
    {
        return 0 === $this->planted ? null : $this->caught / $this->planted;
    }

    public function falseAlarmRate(): ?float
    {
        return 0 === $this->cleanRuns ? null : $this->cleanRunsWithFalseAlarm / $this->cleanRuns;
    }
}
