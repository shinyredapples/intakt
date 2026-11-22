<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Domain;

use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\ReviewerForLoop\Contract\LoopFinding;
use Intakt\Core\ReviewerForLoop\Contract\LoopReview;
use Intakt\Core\ReviewerForLoop\Contract\LoopReviews;
use Intakt\Core\ReviewerForLoop\Contract\LoopVerdict;
use Intakt\Core\ReviewerForLoop\Contract\PullRequestComment;
use JsonException;
use NoDiscard;

// Parses the loop's prose because the weekly report must compare with it; Intakt's own reviews never decide from prose.
final readonly class LoopReviewReader
{
    private const string REVIEWER_LINE = '/^Reviewer:\s*(\S.*?)\s*$/';
    // A backtick fence's info string may not hold a backtick (CommonMark), so an inline span is no fence.
    private const string FENCE_OPENING = '/^\s*(`{3,}(?!.*`)|~{3,})\s*(\S*)/';
    private const string FINDING_LINE = '/^\s*(?:(?:[-*+]|\d+[.)])\s+)?\[(P[123])\]\s+`?([^\s`:]+):(\d+)/';
    private const string FOLLOW_UPS_LINE = '/^\s*Follow-ups:\s*$/i';
    private const string UNKNOWN_REVIEWER = 'unknown';

    /**
     * @param list<PullRequestComment> $comments
     */
    #[NoDiscard('an unread review must not pass as an approval')]
    public function read(string $loopIdentity, string $head, array $comments): LoopReviews
    {
        $own = array_filter($comments, static fn (PullRequestComment $comment): bool => $comment->author === $loopIdentity && $comment->head === $head);

        return new LoopReviews(array_values(array_map($this->readComment(...), $own)));
    }

    public function reviewerNamedIn(PullRequestComment $comment): string
    {
        return $this->reviewerOf($this->readableLines($comment->body));
    }

    // A broken verdict block still counts, so a review that failed to state its verdict reads as unreadable rather than as no review.
    public function holdsVerdict(PullRequestComment $comment): bool
    {
        return [] !== $this->verdictCandidates($this->readableLines($comment->body));
    }

    private function readComment(PullRequestComment $comment): LoopReview
    {
        $lines = $this->readableLines($comment->body);

        return new LoopReview($this->reviewerOf($lines), $this->verdictOf($lines), $this->findingsOf($lines));
    }

    // A fenced json block becomes one line so it competes with bare JSON lines by position; fenced and quoted lines are blanked.
    /**
     * @return list<array{text: string, json: bool}>
     */
    private function readableLines(string $body): array
    {
        $lines = [];
        $fence = null;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            if (null === $fence) {
                $fence = $this->fenceOpenedBy($line);
                $lines[] = $this->unfenced($line, $fence);
                continue;
            }
            if ($this->closes($fence['marker'], $line)) {
                $lines[] = $this->fenced($fence);
                $fence = null;
                continue;
            }
            $fence['body'][] = $line;
        }

        return null === $fence ? $lines : [...$lines, $this->fenced($fence)];
    }

    /**
     * @return array{marker: string, json: bool, body: list<string>}|null
     */
    private function fenceOpenedBy(string $line): ?array
    {
        if (1 !== preg_match(self::FENCE_OPENING, $line, $match)) {
            return null;
        }

        return ['marker' => $match[1], 'json' => 'json' === strtolower($match[2]), 'body' => []];
    }

    /**
     * @param array{marker: string, json: bool, body: list<string>}|null $openedFence
     *
     * @return array{text: string, json: bool}
     */
    private function unfenced(string $line, ?array $openedFence): array
    {
        $readable = null === $openedFence && !str_starts_with(ltrim($line), '>');

        return ['text' => $readable ? $line : '', 'json' => false];
    }

    private function closes(string $marker, string $line): bool
    {
        return 1 === preg_match('/^\s*'.preg_quote($marker[0], '/').'{'.strlen($marker).',}\s*$/', $line);
    }

    /**
     * @param array{marker: string, json: bool, body: list<string>} $fence
     *
     * @return array{text: string, json: bool}
     */
    private function fenced(array $fence): array
    {
        return ['text' => $fence['json'] ? implode(' ', $fence['body']) : '', 'json' => $fence['json']];
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     */
    private function reviewerOf(array $lines): string
    {
        foreach ($lines as $line) {
            if (!$line['json'] && 1 === preg_match(self::REVIEWER_LINE, $line['text'], $match)) {
                return $match[1];
            }
        }

        return self::UNKNOWN_REVIEWER;
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     */
    private function verdictOf(array $lines): LoopVerdict
    {
        $declared = $this->declaredVerdict($lines);

        // An open P1 or P2 finding means changes whatever the JSON says, because the loop's rules count it so.
        return LoopVerdict::Approve === $declared && $this->hasBlockingFinding($lines) ? LoopVerdict::Changes : $declared;
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     *
     * @return list<LoopFinding>
     */
    private function findingsOf(array $lines): array
    {
        return array_values(array_filter(array_map($this->findingIn(...), $lines)));
    }

    /**
     * @param array{text: string, json: bool} $line
     */
    private function findingIn(array $line): ?LoopFinding
    {
        if ($line['json'] || 1 !== preg_match(self::FINDING_LINE, $line['text'], $match)) {
            return null;
        }

        return new LoopFinding($match[2], (int) $match[3], Severity::from($match[1]));
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     */
    private function declaredVerdict(array $lines): LoopVerdict
    {
        $candidates = $this->verdictCandidates($lines);
        if ([] === $candidates) {
            return LoopVerdict::Unreadable;
        }

        try {
            $decoded = json_decode(array_last($candidates)['text'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return LoopVerdict::Unreadable;
        }

        $value = is_array($decoded) ? ($decoded['verdict'] ?? null) : null;

        return (is_string($value) ? LoopVerdict::tryFrom($value) : null) ?? LoopVerdict::Unreadable;
    }

    // A P2 after a "Follow-ups:" line does not block, because the loop files a re-review's P2 there and still approves.
    /**
     * @param list<array{text: string, json: bool}> $lines
     */
    private function hasBlockingFinding(array $lines): bool
    {
        $filed = false;
        foreach ($lines as $line) {
            $filed = $filed || (!$line['json'] && 1 === preg_match(self::FOLLOW_UPS_LINE, $line['text']));
            $severity = $this->findingIn($line)?->severity;
            if (Severity::P1 === $severity || (Severity::P2 === $severity && !$filed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     *
     * @return array<int, array{text: string, json: bool}>
     */
    private function verdictCandidates(array $lines): array
    {
        return array_filter($lines, static fn (array $line): bool => $line['json'] || str_starts_with(ltrim($line['text']), '{'));
    }
}
