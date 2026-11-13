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
    case StreamingFellBack = 'streaming_fell_back';
    case JudgeDone = 'judge_done';
    case UsageRecorded = 'usage_recorded';
    case AccountCooledDown = 'account_cooled_down';
    case HeadPending = 'head_pending';
    case HeadRed = 'head_red';
    case RoundQueued = 'round_queued';
    case VerdictsStale = 'verdicts_stale';
    case ReviewerStopRequested = 'reviewer_stop_requested';
    case HeadFailed = 'head_failed';
    case HeadHandedOver = 'head_handed_over';
    case ReviewerStopped = 'reviewer_stopped';
    case HeadForgotten = 'head_forgotten';
    case JobEnded = 'job_ended';
    case JobContainerRemoved = 'job_container_removed';
    case JobEgressRefused = 'job_egress_refused';
    case JobNetworkRemoved = 'job_network_removed';
    case JobStartCheckFailed = 'job_start_check_failed';
    case BootResultComputed = 'boot_result_computed';
    case HandBackAccepted = 'hand_back_accepted';
    case HandBackSentBack = 'hand_back_sent_back';
    case HandBackHandedOver = 'hand_back_handed_over';
    case ModelChosen = 'model_chosen';
}
