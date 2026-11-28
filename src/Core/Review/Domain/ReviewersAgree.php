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
    // A code span is consumed whole, so white space inside it is never collapsed: findings about "A B" and "A  B" name different literals.
    // An escaped backtick is consumed first, because in prose it opens no code span (CommonMark).
    // Unicode mode makes a nonbreaking space prose white space too.
    private const string ESCAPED_BACKTICK_OR_CODE_SPAN_OR_WHITE_SPACE = '/\\\\`|(`+)(?!`).*?(?<!`)\1(?!`)|\s+/su';

    // One verdict agrees with itself: a panel where one reviewer answered leaves the model nothing to reconcile, and every kept P1 or P2 is acted on either way.
    /**
     * @param list<Verdict> $verdicts
     */
    public function agree(array $verdicts, string $change): bool
    {
        if ([] === $verdicts || array_any($verdicts, $this->needsTheModel(...))) {
            return false;
        }

        return $this->everyDecisionIsTheSame($verdicts)
            && $this->everyFindingIsShared($verdicts)
            && $this->everyFindingPassesTheQuoteCheck($verdicts, $change);
    }

    // Sent to the model because a P3 or a follow-up may be deferred and only the model marks defer or reject, and because a failed review gave no verdict to agree with.
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
    private function everyDecisionIsTheSame(array $verdicts): bool
    {
        return array_all($verdicts, static fn (Verdict $verdict): bool => $verdict->verdict === $verdicts[0]->verdict);
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
        // Serialized as a length-prefixed list, so the boundaries between severity, file and words cannot be forged by a finding's text, valid UTF-8 or not.
        // The file counts, because the same wording at another file is another finding; the line does not, because reviewers point at neighboring lines of one defect.
        $keys = array_map(
            fn (Finding $finding): string => serialize([$finding->severity->value, $finding->file, $this->words($finding->text)]),
            $verdict->findings,
        );
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    // Never lower-cased, because case counts in code and a reviewer's wording is compared as given.
    // Text that is not valid UTF-8 is compared byte for byte, so two different broken texts never collapse into one.
    private function words(string $text): string
    {
        $words = preg_replace_callback(
            self::ESCAPED_BACKTICK_OR_CODE_SPAN_OR_WHITE_SPACE,
            static fn (array $match): string => str_contains('\\`', $match[0][0]) ? $match[0] : ' ',
            $text,
        );

        return null === $words ? $text : trim($words);
    }
}
