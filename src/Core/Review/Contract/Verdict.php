<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Validator\Constraints\Valid;

// Property names are the JSON field names because Symfony AI's structured output ignores serializer name mapping.
final readonly class Verdict
{
    public const string INVALID_OUTPUT = 'invalid-output';
    public const string INCOMPLETE_STREAM = 'incomplete-stream';
    public const string UNREAD_APPROVAL = 'unread-approval';
    public const string NO_TOOLS = 'no-tools';
    public const string CUT_APPROVAL = 'cut-approval';
    private const array FAILED_REVIEWS = [self::INVALID_OUTPUT, self::INCOMPLETE_STREAM, self::UNREAD_APPROVAL, self::NO_TOOLS, self::CUT_APPROVAL];

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

    /**
     * @param list<Finding> $findings
     */
    public function withFindings(array $findings): self
    {
        return clone ($this, ['findings' => $findings]);
    }

    // By the finding ids the failure verdicts carry, because a reviewer that uses one of them itself gains nothing: its verdict then adds no finding and closes none.
    public function isFailedReview(): bool
    {
        return array_any($this->findings, static fn (Finding $finding): bool => in_array($finding->id, self::FAILED_REVIEWS, true));
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

    public static function unreadApproval(): self
    {
        $finding = new Finding(
            id: self::UNREAD_APPROVAL,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer approved a change it could not read: its provider gives it no access to the checkout, so the approval is refused and the review is blocked.',
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'An approval counts only from a reviewer that could read the change.',
            evidence: null,
        );

        return new self(Decision::Blocked, [$finding], [], []);
    }

    public static function cutApproval(): self
    {
        $finding = new Finding(
            id: self::CUT_APPROVAL,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer approved a change whose task, diff or a tool answer Intakt cut to its size limit, and nothing shows that it read the part left out, so the approval is refused and the review is blocked.',
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'An approval counts only from a reviewer that was shown the whole change.',
            evidence: null,
        );

        return new self(Decision::Blocked, [$finding], [], []);
    }

    public static function noTools(): self
    {
        $finding = new Finding(
            id: self::NO_TOOLS,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer had no tools to read the change (the review server could not be reached, listed none or was lost during the review), so it did not review and the review is blocked.',
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'A reviewer judges only a change it can read.',
            evidence: null,
        );

        return new self(Decision::Blocked, [$finding], [], []);
    }
}
