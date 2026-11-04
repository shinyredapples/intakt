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
    case StreamingFellBack = 'streaming_fell_back';
    case JudgeDone = 'judge_done';
    case UsageRecorded = 'usage_recorded';
    case AccountCooledDown = 'account_cooled_down';
    case HeadPending = 'head_pending';
    case HeadRed = 'head_red';
    case RoundQueued = 'round_queued';
    case VerdictsStale = 'verdicts_stale';
    case ReviewerStopRequested = 'reviewer_stop_requested';
    case ReviewerStopped = 'reviewer_stopped';
    case HeadFailed = 'head_failed';
    case HeadHandedOver = 'head_handed_over';
    case HeadForgotten = 'head_forgotten';
}
