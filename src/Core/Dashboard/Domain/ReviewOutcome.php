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
        if ($round->ending instanceof RoundEnding) {
            return RoundEnds::JudgingFailed === $round->ending->end ? self::Failed : self::Blocked;
        }

        if ($round->isJudged() && $round->reviewers > 0 && $round->failedReviewers() === $round->reviewers) {
            return self::Failed;
        }

        return null === $round->outcome ? null : self::ofDecision($round->outcome);
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
}
