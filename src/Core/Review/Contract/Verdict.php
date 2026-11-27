<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints\Valid;

// Stored through Infrastructure\ReviewerAnswer\Verdict, which owns the reviewer's answer format and the stored round JSON, so the typed resolved and follow_ups lists are written as lists of strings.
final readonly class Verdict
{
    // Kept although no verdict is given it any more, because rounds recorded before still hold it and must stay failed reviews.
    public const string INVALID_OUTPUT = 'invalid-output';
    public const string INCOMPLETE_STREAM = 'incomplete-stream';
    public const string UNREAD_APPROVAL = 'unread-approval';
    public const string NO_TOOLS = 'no-tools';
    public const string CUT_APPROVAL = 'cut-approval';
    public const string REVIEWER_STOPPED = 'reviewer-stopped';
    private const array FAILED_REVIEWS = [self::INVALID_OUTPUT, self::INCOMPLETE_STREAM, self::UNREAD_APPROVAL, self::NO_TOOLS, self::CUT_APPROVAL, self::REVIEWER_STOPPED];

    /**
     * @param list<Finding>         $findings
     * @param list<ResolvedFinding> $resolved
     * @param list<FollowUpNote>    $follow_ups
     */
    public function __construct(
        public Decision $verdict,
        #[Valid]
        public array $findings,
        public array $resolved,
        public array $follow_ups,
        // Ignored by the serializer and the schema, so neither a model's answer nor its finding ids can claim Intakt's origin; only the failure constructors below set it.
        #[Ignore]
        private VerdictOrigin $origin = VerdictOrigin::Reviewer,
    ) {
    }

    /**
     * @param list<Finding> $findings
     */
    public function withFindings(array $findings): self
    {
        return clone ($this, ['findings' => $findings]);
    }

    // By the finding ids the failure verdicts carry, because stored verdicts hold no origin; a reviewer's answer naming one never gets here, since the reviewer refuses it (claimedFailureIds()).
    #[Ignore]
    public function isFailedReview(): bool
    {
        return array_any($this->findings, static fn (Finding $finding): bool => in_array($finding->id, self::FAILED_REVIEWS, true));
    }

    // Only a reviewer's answer can claim one, because Intakt's failure verdicts carry these ids by right and a claimed one would make a counted vote whose findings the ledger drops.
    #[Ignore]
    public function claimedFailureIds(): FindingIds
    {
        if (VerdictOrigin::Intakt === $this->origin) {
            return new FindingIds();
        }

        $ids = array_map(static fn (Finding $finding): string => $finding->id, $this->findings);

        return new FindingIds(...array_unique(array_intersect($ids, self::FAILED_REVIEWS)));
    }

    // A reviewer's own blocked that names findings asks for changes, because every view and the quorum read the stored decision; Intakt's failure verdicts stay blocked. Apply it only to a fresh verdict, never to a stored one, because a stored verdict reads back with origin Reviewer and a stored failure verdict would turn into changes.
    public function asRecorded(): self
    {
        if (Decision::Blocked !== $this->verdict || [] === $this->findings || VerdictOrigin::Intakt === $this->origin) {
            return $this;
        }

        return clone ($this, ['verdict' => Decision::Changes]);
    }

    // Only on a fresh verdict, because a stored one reads back with origin Reviewer.
    #[Ignore]
    public function isIntakts(): bool
    {
        return VerdictOrigin::Intakt === $this->origin;
    }

    // The first finding of a blocked verdict says why, because a recorded blocked verdict is one Intakt gave, and each of those holds its reason in its one finding.
    #[Ignore]
    public function blockedReason(): ?string
    {
        return Decision::Blocked === $this->verdict ? array_first($this->findings)?->text : null;
    }

    // The id alone proves no stop, so a caller that answers a stop request also checks the ledger for one.
    #[Ignore]
    public function isStopped(): bool
    {
        return Decision::Blocked === $this->verdict && array_any($this->findings, static fn (Finding $finding): bool => self::REVIEWER_STOPPED === $finding->id);
    }

    // An approval, or a changes verdict of P3 findings only, which the quorum counts as approval when the judge defers or rejects them all; the guards that refuse an approval refuse both, because the judge's marks come only after them. It reads the verdict as recorded, because a reviewer's blocked with findings is recorded as changes after the guards ran; like asRecorded(), only on a fresh verdict, because the guards check a reviewer's answer before it is stored.
    #[Ignore]
    public function mayCountAsApproval(): bool
    {
        $recorded = $this->asRecorded();

        return Decision::Approve === $recorded->verdict
            || (Decision::Changes === $recorded->verdict && [] !== $recorded->findings && !$recorded->isFailedReview() && array_all($recorded->findings, static fn (Finding $finding): bool => Severity::P3 === $finding->severity));
    }

    // A verdict that withholds approval names at least one finding, so a changes or blocked verdict that names none is not a valid verdict.
    #[Ignore]
    public function withholdsApprovalWithoutFinding(): bool
    {
        return Decision::Approve !== $this->verdict && [] === $this->findings;
    }

    public static function incompleteStream(string $reason, Evidence $evidence): self
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
            evidence: $evidence,
            title: 'The reviewer stream ended early',
            kind: FindingKind::Other,
        );

        return self::raisedByIntakt($finding);
    }

    public static function unreadApproval(): self
    {
        $finding = new Finding(
            id: self::UNREAD_APPROVAL,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer approved a change it could not read, or asked only for P3 changes, which can count as approval: its provider gives it no access to the checkout, so the approval is refused and the review is blocked.',
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'An approval counts only from a reviewer that could read the change.',
            evidence: null,
            title: 'Approval without reading the change',
            kind: FindingKind::Other,
        );

        return self::raisedByIntakt($finding);
    }

    public static function cutApproval(): self
    {
        $finding = new Finding(
            id: self::CUT_APPROVAL,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer approved, or asked only for P3 changes, which can count as approval, on a change whose task, diff or a tool answer Intakt cut to its size limit, and nothing shows that it read the part left out, so the approval is refused and the review is blocked.',
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'An approval counts only from a reviewer that was shown the whole change.',
            evidence: null,
            title: 'Approval of a cut change',
            kind: FindingKind::Other,
        );

        return self::raisedByIntakt($finding);
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
            title: 'The reviewer had no tools',
            kind: FindingKind::Other,
        );

        return self::raisedByIntakt($finding);
    }

    public static function reviewerStopped(): self
    {
        $finding = new Finding(
            id: self::REVIEWER_STOPPED,
            severity: Severity::P1,
            file: null,
            line: null,
            symbol: null,
            text: 'The reviewer was stopped from the dashboard before it gave its verdict, so it did not finish its review and the review is blocked.',
            fixable_by: FixableBy::Owner,
            repeat: false,
            requirement: 'A stopped reviewer gives no verdict; its review counts as blocked, never as approval or changes.',
            evidence: null,
            title: 'The reviewer was stopped',
            kind: FindingKind::Other,
        );

        return self::raisedByIntakt($finding);
    }

    private static function raisedByIntakt(Finding $finding): self
    {
        return new self(Decision::Blocked, [$finding], [], [], VerdictOrigin::Intakt);
    }
}
