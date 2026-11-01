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
    // A backtick fence's info string may not hold a backtick (CommonMark), so an inline span is no fence.
    private const string FENCE_OPENING = '/^\s*(`{3,}(?!.*`)|~{3,})\s*(\S*)/';
    private const string FINDING_LINE = '/^\s*(?:(?:[-*+]|\d+[.)])\s+)?\[(P[123])\]\s+`?([^\s`:]+):(\d+)/';
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
        $lines = $this->readableLines($comment->body);
        $findings = $this->findingsOf($lines);

        return new LoopReview($this->reviewerOf($lines), $this->verdictOf($lines, $findings), $findings);
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
                $lines[] = ['text' => null === $fence && !str_starts_with(ltrim($line), '>') ? $line : '', 'json' => false];
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
     *
     * @return list<LoopFinding>
     */
    private function findingsOf(array $lines): array
    {
        $findings = [];
        foreach ($lines as $line) {
            if (!$line['json'] && 1 === preg_match(self::FINDING_LINE, $line['text'], $match)) {
                $findings[] = new LoopFinding($match[2], (int) $match[3], Severity::from($match[1]));
            }
        }

        return $findings;
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     * @param list<LoopFinding>                     $findings
     */
    private function verdictOf(array $lines, array $findings): LoopVerdict
    {
        $declared = $this->declaredVerdict($lines);
        $blocking = array_any($findings, static fn (LoopFinding $finding): bool => Severity::P3 !== $finding->severity);

        // The loop's rules: an open P1 or P2 finding means changes whatever the JSON says.
        return LoopVerdict::Approve === $declared && $blocking ? LoopVerdict::Changes : $declared;
    }

    /**
     * @param list<array{text: string, json: bool}> $lines
     */
    private function declaredVerdict(array $lines): LoopVerdict
    {
        $candidates = array_filter($lines, static fn (array $line): bool => $line['json'] || str_starts_with(ltrim($line['text']), '{'));
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
}
