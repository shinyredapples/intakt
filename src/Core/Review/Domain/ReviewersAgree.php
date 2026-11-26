<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code marks a shared blocking finding act, because a P1 or P2 every reviewer reported reaches the act list whatever a model would mark it (D4 of #862); a rule, not a service.
#[Exclude]
final readonly class ReviewersAgree
{
    // The kept findings are the ones left after the quote check, because a dropped finding is never marked; a P3 is never shared, because only a model may defer or reject it.
    /**
     * @param list<?Verdict> $verdicts
     * @param list<Finding>  $kept
     *
     * @return list<Finding>
     */
    public function sharedIn(array $verdicts, array $kept): array
    {
        if (count($verdicts) < 2 || !array_all($verdicts, $this->requestsChanges(...))) {
            return [];
        }

        return array_values(array_filter($kept, fn (Finding $finding): bool => $this->isShared($finding, $verdicts)));
    }

    // Every reviewer must request changes, because one that approves, failed, was blocked or gave no verdict did not report the finding as a reason to send the change back, so the model still weighs the disagreement; a lone reviewer shares a finding with nobody.
    private function requestsChanges(?Verdict $verdict): bool
    {
        return $verdict instanceof Verdict && Decision::Changes === $verdict->verdict && !$verdict->isFailedReview();
    }

    /**
     * @param list<?Verdict> $verdicts
     */
    private function isShared(Finding $finding, array $verdicts): bool
    {
        if (Severity::P3 === $finding->severity || null === $finding->file || null === $finding->line) {
            return false;
        }

        return array_all($verdicts, fn (?Verdict $verdict): bool => $verdict instanceof Verdict && array_any($verdict->findings, fn (Finding $reported): bool => $this->isTheSame($reported, $finding)));
    }

    // The same place, severity and words, because the ledger does not record which reviewer reported which id, and two reviewers may report unrelated defects at one line; a finding worded apart is shared only through the planned cross-check.
    private function isTheSame(Finding $reported, Finding $finding): bool
    {
        return $reported->severity === $finding->severity
            && $reported->file === $finding->file
            && $reported->line === $finding->line
            && $this->words($reported->text) === $this->words($finding->text);
    }

    // Case stays, because code names differ by case (`$total` and `$Total` are two variables); only white space is not part of the words.
    private function words(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
