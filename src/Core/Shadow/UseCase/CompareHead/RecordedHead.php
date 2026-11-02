<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\UseCase\CompareHead;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use JsonException;
use TypeError;
use ValueError;

// Read here, not in the domain, because the file format belongs to the evidence door alone.
final readonly class RecordedHead
{
    /**
     * @param list<PullRequestComment> $comments
     * @param list<ChangeReview>       $shadowRounds
     */
    private function __construct(
        public string $identity,
        public string $head,
        public array $comments,
        public array $shadowRounds,
    ) {
    }

    public static function fromFile(string $file): ?self
    {
        $data = self::decode($file);
        if (!is_string($data['identity'] ?? null) || !is_string($data['head'] ?? null) || !is_array($data['comments'] ?? null) || !is_array($data['intakt'] ?? null)) {
            return null;
        }

        try {
            $comments = array_map(self::comment(...), array_values($data['comments']));
            $rounds = array_map(self::round(...), array_values($data['intakt']));
        } catch (TypeError|ValueError) {
            return null;
        }

        return new self($data['identity'], $data['head'], $comments, $rounds);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $file): array
    {
        $text = is_file($file) ? file_get_contents($file) : false;
        if (false === $text) {
            return [];
        }

        try {
            $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array{author: string, head: string, body: string} $comment
     */
    private static function comment(array $comment): PullRequestComment
    {
        return new PullRequestComment($comment['author'], $comment['head'], $comment['body']);
    }

    /**
     * @param array{round: int, tokens: array{calls: int, prompt: int, completion: int}, verdicts: list<array<mixed>>} $round
     */
    private static function round(array $round): ChangeReview
    {
        $verdicts = array_map(self::verdict(...), array_values($round['verdicts']));
        $tokens = $round['tokens'];

        return new ChangeReview(
            roundId: 'recorded-'.$round['round'],
            round: $round['round'],
            decision: Decision::Changes,
            verdicts: $verdicts,
            actList: [],
            tokens: new RoundTokens($tokens['calls'], $tokens['prompt'], $tokens['completion'], $tokens['prompt'] + $tokens['completion']),
        );
    }

    /**
     * @param array{tier: string, verdict: string, findings: list<array<mixed>>} $verdict
     */
    private static function verdict(array $verdict): PanelVerdict
    {
        $findings = array_map(self::finding(...), array_values($verdict['findings']));

        return new PanelVerdict(Tier::from($verdict['tier']), new Verdict(Decision::from($verdict['verdict']), $findings, [], []));
    }

    /**
     * @param array{id: string, severity: string, file?: string|null, line?: int|null} $finding
     */
    private static function finding(array $finding): Finding
    {
        return new Finding(
            id: $finding['id'],
            severity: Severity::from($finding['severity']),
            file: $finding['file'] ?? null,
            line: $finding['line'] ?? null,
            symbol: null,
            text: 'recorded',
            fixable_by: FixableBy::Builder,
            repeat: false,
            requirement: null,
            evidence: null,
        );
    }
}
