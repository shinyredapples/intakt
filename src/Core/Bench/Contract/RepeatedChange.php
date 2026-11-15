<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the bench builds it, not the container.
#[Exclude]
final readonly class RepeatedChange
{
    /**
     * @param list<BenchReview> $runs
     */
    public function __construct(
        public string $change,
        public array $runs,
    ) {
    }

    // Null below two compared runs, because one run agrees with nothing; a run with a harness failure is left out as from the rates.
    public function agreed(): ?bool
    {
        $outcomes = array_map($this->outcomeOf(...), $this->comparedRuns());

        return count($outcomes) < 2 ? null : 1 === count(array_unique($outcomes));
    }

    /**
     * @return list<BenchReview>
     */
    public function comparedRuns(): array
    {
        return array_values(array_filter($this->runs, static fn (BenchReview $run): bool => !$run->hasHarnessFailure()));
    }

    private function outcomeOf(BenchReview $run): string
    {
        return json_encode([$run->decision->value, array_map($this->reviewerOutcomeOf(...), $run->reviewers)], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{string, string, list<string>, bool}
     */
    private function reviewerOutcomeOf(BenchReviewerResult $reviewer): array
    {
        $caught = $reviewer->verdict->caught;
        sort($caught);

        return [$reviewer->role, $reviewer->tier->value, $caught, [] !== $reviewer->verdict->falseAlarms];
    }
}
