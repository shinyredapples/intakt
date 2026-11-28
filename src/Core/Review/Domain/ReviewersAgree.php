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
    // A backslash escape first because an escaped backtick opens no span while the backtick after an escaped backslash does (CommonMark), a whole backtick run because a span never opens inside one, Unicode mode because a nonbreaking space is prose white space too.
    private const string BACKSLASH_ESCAPE_OR_BACKTICK_RUN_OR_WHITE_SPACE = '/\\\\[\\\\`]|`+|\s+/u';

    // Any line ending counts, because a CRLF finding would otherwise let a span cross the paragraph again.
    private const string BLANK_LINE = '/\R[ \t]*\R/';

    /**
     * @param list<Verdict> $verdicts
     */
    public function agree(array $verdicts, string $change): bool
    {
        // One verdict agrees with itself, because a panel where one reviewer answered leaves the model nothing to reconcile.
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
        // Serialized, so a finding's text cannot forge the boundaries; the file counts because the same wording elsewhere is another finding, the line does not because reviewers point at neighboring lines of one defect.
        $keys = array_map(
            fn (Finding $finding): string => serialize([$finding->severity->value, $finding->file, $this->words($finding->text)]),
            $verdict->findings,
        );
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    // Never lower-cased, because case counts in code; text that is not valid UTF-8 stays byte for byte, so two different broken texts never collapse into one.
    private function words(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Split at blank lines because a code span never crosses one (CommonMark), so a lone backtick cannot pair with a span opener of a later paragraph.
        $paragraphs = array_map($this->paragraphWords(...), (array) preg_split(self::BLANK_LINE, $text));

        return implode(' ', array_filter($paragraphs, static fn (string $words): bool => '' !== $words));
    }

    private function paragraphWords(string $text): string
    {
        $runs = new BacktickRuns($text);
        $words = '';
        $cursor = 0;
        while (1 === preg_match(self::BACKSLASH_ESCAPE_OR_BACKTICK_RUN_OR_WHITE_SPACE, $text, $match, PREG_OFFSET_CAPTURE, $cursor)) {
            [$token, $offset] = $match[0];
            $end = $offset + strlen($token);
            // Spans are kept byte for byte because "A B" and "A  B" name different literals.
            $end = '`' === $token[0] ? $runs->spanEnd(strlen($token), $end) : $end;
            $words .= substr($text, $cursor, $offset - $cursor).(str_contains('\\`', $token[0]) ? substr($text, $offset, $end - $offset) : ' ');
            $cursor = $end;
        }

        return trim($words.substr($text, $cursor));
    }
}
