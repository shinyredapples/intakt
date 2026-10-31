<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

enum LedgerEventKind: string
{
    case ReviewStarted = 'review_started';
    case ReviewerStarted = 'reviewer_started';
    case VerdictReceived = 'verdict_received';
    case ReviewerFailed = 'reviewer_failed';
    case ReviewerBlocked = 'reviewer_blocked';
    case JudgeDone = 'judge_done';
    case UsageRecorded = 'usage_recorded';
    case AccountCooledDown = 'account_cooled_down';
}
