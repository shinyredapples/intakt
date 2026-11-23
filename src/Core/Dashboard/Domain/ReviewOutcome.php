<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEnds;

// One vocabulary for every page, so a reader sees the same words for the same end; the process state (judged, in progress) is never an outcome.
enum ReviewOutcome: string
{
    case Approved = 'Approved';
    case ChangesRequested = 'Changes requested';
    case Blocked = 'Blocked';
    case Failed = 'Failed';

    public static function of(ReviewedRound $round): ?self
    {
        return ($round->ending instanceof RoundEnding ? self::ofEnding($round->ending) : null)
            ?? self::ofReviewers($round)
            ?? self::ofQuorum($round);
    }

    public static function ofQuorum(ReviewedRound $round): ?self
    {
        return $round->outcome instanceof Decision ? self::ofDecision($round->outcome) : null;
    }

    // A reviewer still running or stopped has given nothing yet, so it has no outcome; its progress is shown apart from it.
    public static function ofReviewer(ReviewerOutcome $reviewer): ?self
    {
        return match ($reviewer->progress) {
            ReviewerProgress::Answered => $reviewer->decision instanceof Decision ? self::ofDecision($reviewer->decision) : null,
            ReviewerProgress::Failed => self::Failed,
            ReviewerProgress::Blocked => self::Blocked,
            ReviewerProgress::Running, ReviewerProgress::Interrupted, ReviewerProgress::Stopped => null,
        };
    }

    // A round's quorum and a reviewer's verdict share one mapping, so the summary and the round rows never word the same decision apart.
    public static function ofDecision(Decision $decision): self
    {
        return match ($decision) {
            Decision::Approve => self::Approved,
            Decision::Changes => self::ChangesRequested,
            Decision::Blocked => self::Blocked,
        };
    }

    // The check mark is Approved's alone, so a judged round that was not approved never reads as a pass.
    public function icon(): string
    {
        return match ($this) {
            self::Approved => 'check',
            self::ChangesRequested => 'rounds',
            self::Blocked => 'stop',
            self::Failed => 'broken',
        };
    }

    // The review page speaks plain words while the CLI, MCP and the docs keep the value, so the page shows the value only in a tooltip (#3183).
    public function plainWords(): string
    {
        return self::ChangesRequested === $this ? 'Changes needed' : $this->value;
    }

    // A judged ending carries no outcome of its own, so the round falls back to its reviewers and quorum.
    private static function ofEnding(RoundEnding $ending): ?self
    {
        return match ($ending->end) {
            RoundEnds::JudgingFailed => self::Failed,
            RoundEnds::NoOutcomeInTime => self::Blocked,
            RoundEnds::Judged => null,
        };
    }

    private static function ofReviewers(ReviewedRound $round): ?self
    {
        return $round->isJudged() && $round->reviewers > 0 && $round->failedReviewers() === $round->reviewers ? self::Failed : null;
    }
}
