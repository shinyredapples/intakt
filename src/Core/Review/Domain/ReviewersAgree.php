<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Whether the Code reviewers' verdict step can mark a round in plain code (D4 of #862): every reviewer gave a readable verdict, no finding may be deferred, and every blocking finding is shared; a rule, not a service.
#[Exclude]
final readonly class ReviewersAgree
{
    // The kept findings are the ones left after the quote check, because a dropped finding is never marked. A finding is shared when every reviewer reported a blocking finding at its file and line, the place OnePerPlace merges act entries by, because the ledger does not record which reviewer reported which id; one without a place can be matched with none.
    /**
     * @param list<?Verdict> $verdicts
     * @param list<Finding>  $kept
     */
    public function over(array $verdicts, array $kept): bool
    {
        return [] !== $verdicts
            && array_all($verdicts, $this->isReadable(...))
            && array_all($kept, static fn (Finding $finding): bool => Severity::P3 !== $finding->severity)
            && array_all($kept, fn (Finding $finding): bool => $this->isShared($finding, $verdicts));
    }

    // A failed review, a blocked verdict or a reviewer without one is no agreement, because its vote counts as blocked whatever plain code marks.
    private function isReadable(?Verdict $verdict): bool
    {
        return $verdict instanceof Verdict && in_array($verdict->verdict, [Decision::Approve, Decision::Changes], true) && !$verdict->isFailedReview();
    }

    /**
     * @param list<?Verdict> $verdicts
     */
    private function isShared(Finding $finding, array $verdicts): bool
    {
        if (null === $finding->file || null === $finding->line) {
            return false;
        }

        return array_all($verdicts, static fn (?Verdict $verdict): bool => $verdict instanceof Verdict && array_any($verdict->findings, static fn (Finding $reported): bool => Severity::P3 !== $reported->severity
            && $reported->file === $finding->file
            && $reported->line === $finding->line));
    }
}
