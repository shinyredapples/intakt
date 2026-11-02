<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

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

// Read here, not in the domain, because the file format belongs to the evidence doors (compare-head and report) alone.
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
            $comments = array_map(self::comment(...), self::list($data['comments']));
            $rounds = array_map(self::round(...), self::list($data['intakt']));
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

    // The shapes below are not checked here, because the constructors raise the TypeError or ValueError for a wrong one.
    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : throw new TypeError('not a list');
    }

    private static function comment(mixed $raw): PullRequestComment
    {
        /** @var array{author: string, head: string, body: string} $comment */
        $comment = $raw;

        return new PullRequestComment($comment['author'], $comment['head'], $comment['body']);
    }

    private static function round(mixed $round): ChangeReview
    {
        /** @var array{round: int, tokens: array{calls: int, prompt: int, completion: int}, verdicts: list<array<mixed>>} $round */
        $verdicts = array_map(self::verdict(...), self::list($round['verdicts']));
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

    private static function verdict(mixed $verdict): PanelVerdict
    {
        /** @var array{tier: string, verdict: string, findings: list<array<mixed>>} $verdict */
        $findings = array_map(self::finding(...), self::list($verdict['findings']));

        return new PanelVerdict(Tier::from($verdict['tier']), new Verdict(Decision::from($verdict['verdict']), $findings, [], []));
    }

    private static function finding(mixed $raw): Finding
    {
        /** @var array{id: string, severity: string, file?: string|null, line?: int|null} $finding */
        $finding = $raw;

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
