<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\RunBench;

use Closure;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\BenchFigures;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Review\Contract\Finding;

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
            'totals per reviewer and model over every run:',
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
                    array_push($lines, ...$this->causesOf(sprintf('  %s run %d: %s', $change->change, $index + 1, $reviewer->name()), $reviewer));
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

        return [
            ...array_map(
                static fn (string $id, FailureCause $cause): string => $prefix.' missed '.$id.', cause '.$cause->value,
                array_keys($verdict->missed),
                $verdict->missed,
            ),
            // The reviewer's, because the classifier counts a false alarm only on a change that plants no defect, so no expectation of the bench can be wrong about it.
            ...array_map(
                fn (Finding $finding): string => $prefix.' false alarm '.$finding->id.' '.$this->placeOf($finding).', cause '.FailureCause::Reviewer->value,
                $verdict->falseAlarms,
            ),
        ];
    }

    private function placeOf(Finding $finding): string
    {
        return match (true) {
            null === $finding->file => 'without a place',
            null === $finding->line => 'at '.$finding->file,
            default => 'at '.$finding->file.':'.$finding->line,
        };
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
            '  %s, measured: %d caught, %d missed, %d false alarms, %d prompt tokens, %d completion tokens, %d calls, %s',
            $reviewer->name(),
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
        $results = $this->everyReviewerResult($changes);
        $byReviewer = [];
        foreach ($results as $reviewer) {
            $byReviewer[$this->totalsName($reviewer, $results)][] = $reviewer;
        }

        return array_map(
            fn (string $name, array $results): string => $this->totalLine('  '.$name, $results),
            array_keys($byReviewer),
            $byReviewer,
        );
    }

    /**
     * @param list<RepeatedChange> $changes
     *
     * @return list<BenchReviewerResult>
     */
    private function everyReviewerResult(array $changes): array
    {
        $runs = array_merge(...array_map(static fn (RepeatedChange $change): array => $change->runs, $changes));

        return array_merge(...array_map(static fn (BenchReview $run): array => $run->reviewers, $runs));
    }

    // A reviewer that failed before any call recorded a model joins the one model line of its role and tier, so its harness failure is not apart from the rest.
    /**
     * @param list<BenchReviewerResult> $results
     */
    private function totalsName(BenchReviewerResult $reviewer, array $results): string
    {
        if (!$reviewer->models->isEmpty()) {
            return $reviewer->name();
        }

        $names = [];
        foreach ($results as $other) {
            if (!$other->models->isEmpty() && $other->role === $reviewer->role && $other->tier === $reviewer->tier) {
                $names[$other->name()] = true;
            }
        }

        return 1 === count($names) ? array_key_first($names) : $reviewer->name();
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
