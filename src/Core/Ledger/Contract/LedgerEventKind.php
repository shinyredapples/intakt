<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

enum LedgerEventKind: string
{
    case ReviewStarted = 'review_started';
    case ShadowRoundHeld = 'shadow_round_held';
    case ReviewerStarted = 'reviewer_started';
    case VerdictReceived = 'verdict_received';
    case ReviewerFailed = 'reviewer_failed';
    case ReviewerBlocked = 'reviewer_blocked';
    case CatchUpOwed = 'catch_up_owed';
    case JudgeDone = 'judge_done';
    case UsageRecorded = 'usage_recorded';
    case AccountCooledDown = 'account_cooled_down';
}
