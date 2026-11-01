<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use JsonException;
use NoDiscard;

// Parses the loop's prose because the shadow report must compare with it; Intakt's own reviews never decide from prose.
final readonly class LoopReviewReader
{
    private const string REVIEWER_LINE = '/^Reviewer:\s*(\S.*?)\s*$/';
    private const string FENCE_BLOCK = '/^[ \t]*(```|~~~)[ \t]*(\w*)[ \t]*\R(.*?)(?:\R[ \t]*\1[ \t]*$|\z)/msu';
    private const string FINDING_LINE = '/^\s*(?:[-*]\s+)?\[(P[123])\]\s+`?([^\s`:]+):(\d+)/';
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

    private function readComment(PullRequestComment $comment): LoopReview
    {
        $lines = $this->unquotedLines($this->withoutFences($comment->body));

        return new LoopReview($this->reviewerOf($lines), $this->verdictOf($lines), $this->findingsOf($lines));
    }

    // A fenced json block becomes one line so it competes with bare JSON lines by position; any other fence is dropped.
    private function withoutFences(string $body): string
    {
        return preg_replace_callback(
            self::FENCE_BLOCK,
            static fn (array $match): string => 'json' === strtolower($match[2]) ? preg_replace('/\s*\R\s*/', ' ', trim($match[3])) ?? '' : '',
            $body,
        ) ?? '';
    }

    /**
     * @return list<string>
     */
    private function unquotedLines(string $text): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        return array_values(array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '>')));
    }

    /**
     * @param list<string> $lines
     */
    private function reviewerOf(array $lines): string
    {
        foreach ($lines as $line) {
            if (1 === preg_match(self::REVIEWER_LINE, $line, $match)) {
                return $match[1];
            }
        }

        return self::UNKNOWN_REVIEWER;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<LoopFinding>
     */
    private function findingsOf(array $lines): array
    {
        $findings = [];
        foreach ($lines as $line) {
            if (1 === preg_match(self::FINDING_LINE, $line, $match)) {
                $findings[] = new LoopFinding($match[2], (int) $match[3], Severity::from($match[1]));
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $lines
     */
    private function verdictOf(array $lines): LoopVerdict
    {
        $candidates = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with(ltrim($line), '{')));
        if ([] === $candidates) {
            return LoopVerdict::Unreadable;
        }

        try {
            $decoded = json_decode(array_last($candidates), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return LoopVerdict::Unreadable;
        }

        $value = is_array($decoded) ? ($decoded['verdict'] ?? null) : null;

        return (is_string($value) ? LoopVerdict::tryFrom($value) : null) ?? LoopVerdict::Unreadable;
    }
}
