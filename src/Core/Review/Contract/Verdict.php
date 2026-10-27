<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Verdict
{
    public const string INVALID_OUTPUT = 'invalid-output';

    /** @var list<string> */
    public array $resolved;

    /** @var list<string> */
    public array $followUps;

    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public Decision $decision,
        public array $findings,
    ) {
        $this->resolved = [];
        $this->followUps = [];
    }

    public static function invalidOutput(string $reason): self
    {
        $finding = new Finding(
            self::INVALID_OUTPUT,
            'The reviewer output is not a valid verdict, so it counts as changes: '.$reason,
            new Triage(Severity::P1, FixableBy::Builder, repeat: false),
        )->citing('The verdict is JSON and is validated against a schema; anything else counts as changes.');

        return new self(Decision::Changes, [$finding]);
    }

    /**
     * @param list<string> $ids
     */
    public function resolving(array $ids): self
    {
        return clone ($this, ['resolved' => $ids]);
    }

    /**
     * @param list<string> $followUps
     */
    public function followingUp(array $followUps): self
    {
        return clone ($this, ['followUps' => $followUps]);
    }
}
