<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code decides whether the Code reviewers' verdict step needs a model call, because agreeing reviewers leave the model nothing to sort (docs/design/05-architecture/07-reviews.md); a rule, not a service.
#[Exclude]
final readonly class ReviewersAgree
{
    // Consumed whole, so white space inside a code span is never collapsed: findings about "A B" and "A  B" name different literals.
    private const string CODE_SPAN_OR_WHITE_SPACE = '/(`+)(?!`).*?(?<!`)\1(?!`)|\s+/s';

    /**
     * @param list<Verdict> $verdicts
     */
    public function agree(array $verdicts, string $change): bool
    {
        if ([] === $verdicts || array_any($verdicts, $this->needsTheModel(...))) {
            return false;
        }

        return $this->everyFindingIsShared($verdicts) && $this->everyFindingPassesTheQuoteCheck($verdicts, $change);
    }

    // A P3 or a follow-up may be deferred, and only the model marks defer or reject; a failed review gave no verdict to agree with.
    private function needsTheModel(Verdict $verdict): bool
    {
        return [] !== $verdict->follow_ups
            || $verdict->isFailedReview()
            || $verdict->withholdsApprovalWithoutFinding()
            || array_any($verdict->findings, static fn (Finding $finding): bool => Severity::P3 === $finding->severity);
    }

    /**
     * @param non-empty-list<Verdict> $verdicts
     */
    private function everyFindingIsShared(array $verdicts): bool
    {
        $keys = array_map($this->keysOf(...), $verdicts);

        return array_all($keys, static fn (array $theirs): bool => $theirs === $keys[0]);
    }

    /**
     * @param non-empty-list<Verdict> $verdicts
     */
    private function everyFindingPassesTheQuoteCheck(array $verdicts, string $change): bool
    {
        $quotes = new QuotesInChange();

        return array_all($verdicts, static fn (Verdict $verdict): bool => array_all(
            $verdict->findings,
            static fn (Finding $finding): bool => null === $quotes->reasonToDrop($finding, $change),
        ));
    }

    /**
     * @return list<string>
     */
    private function keysOf(Verdict $verdict): array
    {
        // Encoded as a list, so the boundary between severity and words cannot be forged by a finding's text.
        $keys = array_map(fn (Finding $finding): string => (string) json_encode([$finding->severity->value, $this->words($finding->text)]), $verdict->findings);
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    // Never lower-cased, because case counts in code and a reviewer's wording is compared as given.
    private function words(string $text): string
    {
        return trim((string) preg_replace_callback(
            self::CODE_SPAN_OR_WHITE_SPACE,
            static fn (array $match): string => str_starts_with($match[0], '`') ? $match[0] : ' ',
            $text,
        ));
    }
}
