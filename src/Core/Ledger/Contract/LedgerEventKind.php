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
    case AccountErrorReported = 'account_error_reported';
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
    case MainBootFailed = 'main_boot_failed';
    case HeadBootSkipped = 'head_boot_skipped';
    case HandBackAccepted = 'hand_back_accepted';
    case HandBackSentBack = 'hand_back_sent_back';
    case HandBackHandedOver = 'hand_back_handed_over';
    case PullRequestTextPassed = 'pull_request_text_passed';
    case PullRequestTextSentBack = 'pull_request_text_sent_back';
    case PullRequestTextToReviewers = 'pull_request_text_to_reviewers';
    case ModelChosen = 'model_chosen';
    case PushIntended = 'push_intended';
    case BranchPushed = 'branch_pushed';
    case PushRefused = 'push_refused';
    case ChangeIntended = 'change_intended';
    case ChangeOpened = 'change_opened';
    case BuildStepEnded = 'build_step_ended';
    case JudgingFailed = 'judging_failed';
    case ReviewerFindingsRecorded = 'reviewer_findings_recorded';
    case RecurringP1Found = 'recurring_p1_found';
    case EscalationVerdictReused = 'escalation_verdict_reused';
    case EscalationReviewed = 'escalation_reviewed';
    case EscalationFailed = 'escalation_failed';
    case ChangeHandedToIntakt = 'change_handed_to_intakt';
    case ChangeNotHandedToIntakt = 'change_not_handed_to_intakt';
    case OwnerAsked = 'owner_asked';
    case HandOverPublicationQueued = 'hand_over_publication_queued';
    case MainHalted = 'main_halted';
    case HaltCleared = 'halt_cleared';
    case OwnerPushNoticed = 'owner_push_noticed';
    case TaskParked = 'task_parked';
    case TaskSized = 'task_sized';
}
