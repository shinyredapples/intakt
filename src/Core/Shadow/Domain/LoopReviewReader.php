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

/**
 * Reads the existing loop's prose and JSON for the shadow report only; Intakt's own reviews never parse prose for a decision.
 */
final readonly class LoopReviewReader
{
    private const string REVIEWER_LINE = '/^Reviewer:\s*(\S.*?)\s*$/';
    private const string FENCE_LINE = '/^\s*(```|~~~)\s*(\w*)\s*$/';
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
        $lines = preg_split('/\R/', $comment->body) ?: [];
        $verdictCandidates = [];
        $findings = [];
        $reviewer = self::UNKNOWN_REVIEWER;
        $fence = null;
        $fenceBody = [];

        foreach ($lines as $line) {
            if (1 === preg_match(self::FENCE_LINE, $line, $match)) {
                if (null === $fence) {
                    $fence = strtolower($match[2]);
                    $fenceBody = [];
                } else {
                    if ('json' === $fence) {
                        $verdictCandidates[] = implode("\n", $fenceBody);
                    }
                    $fence = null;
                }

                continue;
            }

            if (null !== $fence) {
                $fenceBody[] = $line;

                continue;
            }

            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            if (1 === preg_match(self::REVIEWER_LINE, $line, $match)) {
                $reviewer = $match[1];
            } elseif (str_starts_with(ltrim($line), '{')) {
                $verdictCandidates[] = $line;
            } elseif (1 === preg_match(self::FINDING_LINE, $line, $match)) {
                $findings[] = new LoopFinding($match[2], (int) $match[3], Severity::from($match[1]));
            }
        }

        return new LoopReview($reviewer, $this->verdictOf($verdictCandidates), $findings);
    }

    /**
     * @param list<string> $candidates
     */
    private function verdictOf(array $candidates): LoopVerdict
    {
        if ([] === $candidates) {
            return LoopVerdict::Unreadable;
        }

        try {
            $decoded = json_decode(array_last($candidates), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return LoopVerdict::Unreadable;
        }

        $value = is_array($decoded) ? ($decoded['verdict'] ?? null) : null;
        $verdict = is_string($value) ? LoopVerdict::tryFrom($value) : null;

        return $verdict ?? LoopVerdict::Unreadable;
    }
}
