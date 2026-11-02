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
    case CatchUpOwed = 'catch_up_owed';
    case JudgeDone = 'judge_done';
    case UsageRecorded = 'usage_recorded';
    case AccountCooledDown = 'account_cooled_down';
    case AccountErrorReported = 'account_error_reported';
    case HeadPending = 'head_pending';
    case HeadRed = 'head_red';
    case RoundQueued = 'round_queued';
    case VerdictsStale = 'verdicts_stale';
}
