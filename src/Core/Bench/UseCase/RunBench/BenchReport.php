<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\RunBench;

use Closure;
use Intakt\Core\Bench\Contract\BenchFigures;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\FailureCause;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Contract\RunVerdict;

// The causes come before any figure, because a figure read without its failed runs' causes misleads.
final readonly class BenchReport
{
    /**
     * @param list<RepeatedChange> $changes
     *
     * @return list<string>
     */
    public function linesOf(array $changes): array
    {
        return [
            'failure causes:',
            ...$this->failureCauses($changes),
            'figures:',
            ...array_merge(...array_map($this->changeFigures(...), $changes)),
            'totals per reviewer and tier over every run:',
            ...$this->totals($changes),
        ];
    }

    /**
     * @param list<RepeatedChange> $changes
     *
     * @return list<string>
     */
    private function failureCauses(array $changes): array
    {
        $lines = [];
        foreach ($changes as $change) {
            foreach ($change->runs as $index => $run) {
                foreach ($run->reviewers as $reviewer) {
                    array_push($lines, ...$this->causesOf(sprintf('  %s run %d: %s (%s)', $change->change, $index + 1, $reviewer->role, $reviewer->tier->value), $reviewer));
                }
            }
        }

        return [] === $lines ? ['  none'] : $lines;
    }

    // A harness failure is one line with its reason, because its misses all share that cause.
    /**
     * @return list<string>
     */
    private function causesOf(string $prefix, BenchReviewerResult $reviewer): array
    {
        $verdict = $reviewer->verdict;
        if ($verdict->isHarnessFailure()) {
            return [$prefix.' harness failure, left out of the figures: '.$verdict->harnessFailure];
        }

        return array_map(
            static fn (string $id, FailureCause $cause): string => $prefix.' missed '.$id.', cause '.$cause->value,
            array_keys($verdict->missed),
            $verdict->missed,
        );
    }

    /**
     * @return list<string>
     */
    private function changeFigures(RepeatedChange $change): array
    {
        $lines = [];
        foreach ($change->runs as $index => $run) {
            $lines[] = sprintf('%s run %d of %d: decision %s, measured %d act list findings', $change->change, $index + 1, count($change->runs), $run->decision->value, count($run->actList));
            array_push($lines, ...array_map($this->reviewerLine(...), $run->reviewers));
        }
        $lines[] = sprintf('%s: %s, measured over %d compared of %d runs', $change->change, $this->agreementOf($change), count($change->comparedRuns()), count($change->runs));

        return $lines;
    }

    private function reviewerLine(BenchReviewerResult $reviewer): string
    {
        $verdict = $reviewer->verdict;
        $line = sprintf(
            '  %s (%s), measured: %d caught, %d missed, %d false alarms, %d prompt tokens, %d completion tokens, %d calls, %s',
            $reviewer->role,
            $reviewer->tier->value,
            count($verdict->caught),
            count($verdict->missed),
            count($verdict->falseAlarms),
            $reviewer->tokens->prompt,
            $reviewer->tokens->completion,
            $reviewer->tokens->calls,
            null === $reviewer->milliseconds ? 'no end' : $reviewer->milliseconds.' ms',
        );

        return $verdict->isHarnessFailure() ? $line.', harness failure' : $line;
    }

    private function agreementOf(RepeatedChange $change): string
    {
        return match ($change->agreed()) {
            true => 'runs agree',
            false => 'runs disagree',
            null => 'runs not comparable',
        };
    }

    /**
     * @param list<RepeatedChange> $changes
     *
     * @return list<string>
     */
    private function totals(array $changes): array
    {
        $byReviewer = [];
        foreach ($changes as $change) {
            foreach ($change->runs as $run) {
                foreach ($run->reviewers as $reviewer) {
                    $byReviewer[$reviewer->role][$reviewer->tier->value][] = $reviewer;
                }
            }
        }

        $lines = [];
        foreach ($byReviewer as $role => $tiers) {
            foreach ($tiers as $tier => $results) {
                $lines[] = $this->totalLine(sprintf('  %s (%s)', $role, $tier), $results);
            }
        }

        return $lines;
    }

    /**
     * @param non-empty-list<BenchReviewerResult> $results
     */
    private function totalLine(string $reviewer, array $results): string
    {
        $figures = BenchFigures::from(array_map(static fn (BenchReviewerResult $result): RunVerdict => $result->verdict, $results));
        $sum = static fn (Closure $of): int => array_sum(array_map($of, $results));

        return sprintf(
            '%s, measured over %d rounds: %d of %d planted defects found, %d false alarms, %d harness failures left out, %d tokens (%d prompt, %d completion, %d calls)',
            $reviewer,
            count($results),
            $figures->caught,
            $figures->planted,
            $figures->falseAlarms,
            count($figures->harnessFailures),
            $sum(static fn (BenchReviewerResult $result): int => $result->tokens->total),
            $sum(static fn (BenchReviewerResult $result): int => $result->tokens->prompt),
            $sum(static fn (BenchReviewerResult $result): int => $result->tokens->completion),
            $sum(static fn (BenchReviewerResult $result): int => $result->tokens->calls),
        );
    }
}
