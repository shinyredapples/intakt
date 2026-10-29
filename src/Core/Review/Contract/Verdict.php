<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping.
final readonly class Verdict
{
    public const string INVALID_OUTPUT = 'invalid-output';
    public const string INCOMPLETE_STREAM = 'incomplete-stream';

    /**
     * @param list<Finding> $findings
     * @param list<string>  $resolved
     * @param list<string>  $follow_ups
     */
    public function __construct(
        public Decision $verdict,
        #[Valid]
        public array $findings,
        public array $resolved,
        public array $follow_ups,
    ) {
    }

    public static function invalidOutput(string $reason): self
    {
        $finding = new Finding(
            id: self::INVALID_OUTPUT,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer output is not a valid verdict, so it counts as changes: '.$reason,
            fixable_by: FixableBy::Builder,
            repeat: false,
            requirement: 'The verdict is a typed object that structured output fills and the Validator checks; anything else counts as changes.',
            evidence: null,
        );

        return new self(Decision::Changes, [$finding], [], []);
    }

    public static function incompleteStream(string $reason): self
    {
        $finding = new Finding(
            id: self::INCOMPLETE_STREAM,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer stream ended before its verdict, so the review is blocked: '.$reason,
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'A verdict counts only when the reviewer finished it; a cut-off stream blocks and never counts as approval or changes.',
            evidence: null,
        );

        return new self(Decision::Blocked, [$finding], [], []);
    }
}
