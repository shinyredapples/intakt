<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping.
final readonly class Verdict
{
    public const string INVALID_OUTPUT = 'invalid-output';

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
}
