<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Verdict
{
    public const string INVALID_OUTPUT = 'invalid-output';

    /**
     * @param list<Finding> $findings
     * @param list<string>  $resolved
     * @param list<string>  $followUps
     */
    public function __construct(
        public Decision $decision,
        public array $findings,
        public array $resolved,
        public array $followUps,
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
            fixableBy: FixableBy::Builder,
            repeat: false,
            requirement: 'The verdict is JSON and is validated against a schema; anything else counts as changes.',
            evidence: null,
        );

        return new self(Decision::Changes, [$finding], [], []);
    }
}
