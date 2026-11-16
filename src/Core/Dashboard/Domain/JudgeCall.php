<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum JudgeCall: string
{
    case Act = 'act';
    case FollowUp = 'follow-up';
    case NotActedOn = 'rejected or unmarked';

    // The ledger records the act and follow-up lists only, so a finding outside both is "not marked" even when the judge rejected it.
    public function inWords(): string
    {
        return match ($this) {
            self::Act => 'Fix now',
            self::FollowUp => 'Dropped: deferred to a follow-up issue',
            self::NotActedOn => 'Dropped: rejected or not marked by the judge',
        };
    }
}
