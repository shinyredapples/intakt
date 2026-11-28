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

        // Every closer is looked up once, ahead of the cursor, because the text is model output of any length and a rescan per opener would take quadratic time.
        $closers = $this->backtickRunsByLength($text);
        $next = [];
        $words = '';
        $cursor = 0;
        while (1 === preg_match(self::BACKSLASH_ESCAPE_OR_BACKTICK_RUN_OR_WHITE_SPACE, $text, $match, PREG_OFFSET_CAPTURE, $cursor)) {
            [$token, $offset] = $match[0];
            $length = strlen($token);
            $words .= substr($text, $cursor, $offset - $cursor);
            $cursor = $offset + $length;
            if ('`' !== $token[0]) {
                $words .= '\\' === $token[0] ? $token : ' ';

                continue;
            }

            // Spans are kept byte for byte because "A B" and "A  B" name different literals.
            $next[$length] ??= 0;
            while (isset($closers[$length][$next[$length]]) && $closers[$length][$next[$length]] < $cursor) {
                ++$next[$length];
            }
            $closer = $closers[$length][$next[$length]] ?? null;
            if (null !== $closer) {
                $cursor = $closer + $length;
                $token = substr($text, $offset, $cursor - $offset);
            }
            $words .= $token;
        }

        return trim($words.substr($text, $cursor));
    }

    /**
     * @return array<int, list<int>>
     */
    private function backtickRunsByLength(string $text): array
    {
        preg_match_all('/`+/', $text, $runs, PREG_OFFSET_CAPTURE);
        $byLength = [];
        foreach ($runs[0] as [$run, $offset]) {
            $byLength[strlen($run)][] = $offset;
        }

        return $byLength;
    }
}
