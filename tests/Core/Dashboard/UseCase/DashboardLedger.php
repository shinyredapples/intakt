<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Symfony\Component\Uid\Uuid;

final class DashboardLedger implements Ledger
{
    /** @var list<RecordedEvent> */
    private array $recorded = [];

    private bool $failNextAppend = false;

    public function failNextAppend(): void
    {
        $this->failNextAppend = true;
    }

    public function append(LedgerEvent $event): AppendResult
    {
        if ($this->failNextAppend) {
            $this->failNextAppend = false;

            return AppendResult::failed('disk full');
        }

        $this->recorded[] = new RecordedEvent(Uuid::v7(), $event, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        return AppendResult::appended(Uuid::v7());
    }

    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents($this->recorded);
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind)));
    }

    public function readOfKindAbout(LedgerEventKind $kind, string $subject): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind && $subject === $recorded->event->subject)));
    }

    public function readOfKindWithoutSubject(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind && null === $recorded->event->subject)));
    }

    public function readMentioning(Mentions $texts): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => array_any([...$texts], static fn (string $text): bool => str_contains($recorded->event->payload, $text)))));
    }

    public function readOfKindsMentioning(Mentions $texts, LedgerEventKind ...$kinds): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => in_array($recorded->event->kind, $kinds, true) && array_any([...$texts], static fn (string $text): bool => str_contains($recorded->event->payload, $text)))));
    }

    public function startRound(string $round, string $project): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => $project, 'reviewers' => 2]);
    }

    public function startRoundAt(string $round, DateTimeImmutable $at): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2], $at);
    }

    public function judgeRound(string $round): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => [], 'follow_ups' => []]);
    }

    public function judgeRoundAt(string $round, DateTimeImmutable $at): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => [], 'follow_ups' => []], $at);
    }

    public function startRoundOf(string $change, string $round, int $number): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => $number]);
    }

    public function startRecordedRoundOf(string $change, string $round, int $number): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => $number, 'repository' => '/repos/alpha', 'base' => 'main', 'task' => 'Add the thing.']);
    }

    public function startReplayedRoundOf(string $change, string $round, float|string $factor): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => 1, 'replay_factor' => $factor]);
    }

    public function startRoundOfQuorum(string $change, string $round, string $quorum): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => 1, 'quorum' => $quorum]);
    }

    public function startRoundOfQuorumAll(string $change, string $round, int $number): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => $number, 'quorum' => 'all']);
    }

    public function startReviewer(string $round, string $role): void
    {
        $this->recordReviewerStart($round, $this->reviewer($round, $role));
    }

    public function startReviewerOn(string $round, string $role, string $model): void
    {
        $this->recordReviewerStart($round, $this->reviewer($round, $role) + ['model' => $model]);
    }

    public function answer(string $round, string $role, int $findings): void
    {
        $this->add(LedgerEventKind::VerdictReceived, $this->reviewer($round, $role) + ['decision' => 0 === $findings ? 'approve' : 'changes', 'findings' => $findings]);
    }

    // Without severities, because rows written before reporters' severities were recorded hold none.
    /**
     * @param list<string> $findings
     */
    public function reportFindings(string $round, string $role, array $findings): void
    {
        $this->add(LedgerEventKind::ReviewerFindingsRecorded, ['round' => $round, 'reviewer' => $round.'-'.$role, 'findings' => $findings]);
    }

    /**
     * @param array<string, string> $severities
     */
    public function reportRatedFindings(string $round, string $role, array $severities): void
    {
        $this->add(LedgerEventKind::ReviewerFindingsRecorded, ['round' => $round, 'reviewer' => $round.'-'.$role, 'findings' => array_keys($severities), 'severities' => $severities]);
    }

    public function stopReviewer(string $round, string $role): void
    {
        $this->add(LedgerEventKind::ReviewerStopped, $this->reviewer($round, $role));
    }

    public function answerInShadow(string $round, string $role): void
    {
        $this->add(LedgerEventKind::VerdictReceived, $this->reviewer($round, $role) + ['decision' => 'approve', 'findings' => 0, 'mode' => 'shadow']);
    }

    public function failReviewer(string $round, string $role, string $failure): void
    {
        $this->add(LedgerEventKind::ReviewerFailed, $this->reviewer($round, $role) + ['failure' => $failure]);
    }

    public function blockReviewer(string $round, string $role, string $reason): void
    {
        $this->add(LedgerEventKind::ReviewerBlocked, $this->reviewer($round, $role) + ['reason' => $reason]);
    }

    public function owesCatchUp(string $round, string $role): void
    {
        $this->add(LedgerEventKind::CatchUpOwed, $this->reviewer($round, $role) + ['substitute' => 'openai']);
    }

    /**
     * @param array<string, 'act'|'defer'|'reject'> $marks
     */
    public function judgeFindings(string $round, array $marks): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => array_keys(array_filter($marks, static fn (string $mark): bool => 'act' === $mark)),
            'follow_ups' => array_keys(array_filter($marks, static fn (string $mark): bool => 'defer' === $mark)),
            'findings' => array_map(static fn (string $id): array => ['id' => $id, 'severity' => 'P2', 'text' => 'Text of '.$id.'.'], array_keys($marks)),
            'fallback' => false,
        ]);
    }

    // Every finding marked act, each at its own line so none folds into another; a null severity leaves the key out, as a row written without one.
    /**
     * @param array<string, ?string> $severities
     */
    public function judgeRatedFindings(string $round, array $severities): void
    {
        $line = 0;
        $findings = [];

        foreach ($severities as $id => $severity) {
            $findings[] = ['id' => $id, 'text' => 'Text of '.$id.'.', 'merged' => [], 'file' => 'src/A.php', 'line' => ++$line, ...(null === $severity ? [] : ['severity' => $severity])];
        }

        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => array_keys($severities), 'follow_ups' => [], 'findings' => $findings, 'fallback' => false]);
    }

    // Written as RoundLedger records two act findings at one place, because the page must fold the second into the first.
    public function judgeOneEntryOf(string $round, string $entry, string $merged): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => [$entry, $merged],
            'follow_ups' => [],
            'findings' => [['id' => $entry, 'severity' => 'P1', 'text' => 'Text of '.$entry.'.', 'merged' => [$merged]], ['id' => $merged, 'severity' => 'P2', 'text' => 'Text of '.$merged.'.', 'merged' => []]],
        ]);
    }

    // Written as RoundLedger records a round whose judge linked F4 to F1 and whose F3 sits at F2's place, because the page must fold both merged findings into their entries.
    public function judgeOneEntryPerCause(string $round): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => ['F1', 'F2', 'F3', 'F4'],
            'follow_ups' => [],
            'findings' => [
                ['merged' => ['F4']] + $this->inDiscount('F1', 'P1', 12),
                ['merged' => ['F3']] + $this->inDiscount('F2', 'P2', 30),
                ['merged' => []] + $this->inDiscount('F3', 'P2', 30),
                ['merged' => []] + $this->inDiscount('F4', 'P2', 13),
            ],
            'same_cause' => ['F4' => 'F1'],
        ]);
    }

    public function judgeToFixAtLine7OfA(string $round, string $text): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => ['F1'],
            'follow_ups' => [],
            'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => $text, 'merged' => [], 'file' => 'src/A.php', 'line' => 7]],
        ]);
    }

    public function judgeToFixIn(string $round, string $file, ?int $line): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => ['F1'],
            'follow_ups' => [],
            'findings' => [['id' => 'F1', 'severity' => 'P2', 'text' => 'Review this file.', 'merged' => [], 'file' => $file, ...(null === $line ? [] : ['line' => $line])]],
        ]);
    }

    public function judgeWithOnlyADroppedFinding(string $round, string $text, string $reason): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => [],
            'follow_ups' => [],
            'findings' => [],
            'dropped' => [['id' => 'F1', 'severity' => 'P2', 'text' => $text, 'file' => 'src/A.php', 'line' => 3, 'reason' => $reason]],
        ]);
    }

    /**
     * @param list<array{quote: mixed, lines: mixed}>|string $nearest
     */
    public function judgeWithADroppedFindingAndItsNearestLines(string $round, string $text, array|string $nearest): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => [],
            'follow_ups' => [],
            'findings' => [],
            'dropped' => [['id' => 'F1', 'severity' => 'P2', 'text' => $text, 'file' => 'src/A.php', 'line' => 3, 'reason' => 'none of its quoted code fragments is in the change', 'nearest' => $nearest]],
        ]);
    }

    /**
     * @param list<string> $act
     * @param list<string> $followUps
     */
    public function judgeMarksOnly(string $round, array $act, array $followUps): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => $act, 'follow_ups' => $followUps]);
    }

    public function useTokens(string $round, int $prompt, int $completion): void
    {
        $this->useRecordedTokens($round, ['prompt' => $prompt, 'completion' => $completion, 'total' => $prompt + $completion]);
    }

    /**
     * @param array<string, int|string|null> $usage
     */
    public function useRecordedTokens(string $round, array $usage, string $model = 'model-a'): void
    {
        $call = Uuid::v7()->toRfc4122();
        $this->add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => $round, 'model' => $model]);
        $this->add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'settled', 'usages' => [['model' => $model] + $usage]]);
    }

    // The settled row's text names the round too, as a finding's text may, so a read by the round's id and one by the call's id both find it.
    public function useTokensSettledNamingTheRound(string $round, int $prompt, int $completion): void
    {
        $call = Uuid::v7()->toRfc4122();
        $this->add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => $round, 'model' => 'model-a']);
        $this->add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'settled', 'note' => 'for '.$round, 'usages' => [['model' => 'model-a', 'prompt' => $prompt, 'completion' => $completion, 'total' => $prompt + $completion]]]);
    }

    public function failHead(string $project, string $change, int $failures): void
    {
        $this->add(LedgerEventKind::HeadFailed, $this->headFailure($project, $change, 'a') + ['failures' => $failures]);
    }

    public function failHeadBecause(string $project, string $change, string $cause): void
    {
        $this->add(LedgerEventKind::HeadFailed, ['cause' => $cause, 'failures' => 2] + $this->headFailure($project, $change, 'a'));
    }

    public function failMovedHead(string $project, string $change): void
    {
        $this->add(LedgerEventKind::HeadFailed, $this->headFailure($project, $change, 'b') + ['failures' => 1]);
    }

    public function handOverHead(string $project, string $change, string $head): void
    {
        $this->add(LedgerEventKind::HeadHandedOver, $this->headFailure($project, $change, $head) + ['failures' => 4]);
    }

    public function keepHeadPending(string $project, string $change, string $head): void
    {
        $this->add(LedgerEventKind::HeadPending, ['project' => $project, 'change' => $change, 'head' => $head, 'read_again_at' => '2026-10-28T12:01:00+00:00']);
    }

    public function keepHeadPendingUntil(string $project, string $change, string $readAgainAt): void
    {
        $this->add(LedgerEventKind::HeadPending, ['project' => $project, 'change' => $change, 'head' => 'a', 'read_again_at' => $readAgainAt]);
    }

    public function recordRedHead(string $project, string $change, string $head): void
    {
        $this->add(LedgerEventKind::HeadRed, ['project' => $project, 'change' => $change, 'head' => $head, 'read_again_at' => null]);
    }

    public function queueRoundOf(string $project, string $change, string $head): string
    {
        $round = Uuid::v7()->toRfc4122();
        $this->add(LedgerEventKind::RoundQueued, ['project' => $project, 'change' => $change, 'head' => $head, 'round' => $round]);

        return $round;
    }

    public function forgetHead(string $project, string $change, string $head): void
    {
        $this->add(LedgerEventKind::HeadForgotten, ['project' => $project, 'change' => $change, 'head' => $head]);
    }

    public function failMainBoot(string $project, string $reason, string $main = 'a'): void
    {
        $this->add(LedgerEventKind::MainBootFailed, ['project' => $project, 'main' => $main, 'tool_fingerprint' => 'tools-1', 'reason' => $reason]);
    }

    public function bootMain(string $project, string $main = 'b', string $toolFingerprint = 'tools-1'): void
    {
        $this->add(LedgerEventKind::BootResultComputed, ['project' => $project, 'main' => $main, 'tool_fingerprint' => $toolFingerprint, 'state' => 'booted', 'reason' => '']);
    }

    public function readMainFromStore(string $project, string $main, string $state): void
    {
        $this->add(LedgerEventKind::BootResultComputed, ['project' => $project, 'main' => $main, 'tool_fingerprint' => 'tools-1', 'state' => $state, 'reason' => '', 'read_from' => 'store']);
    }

    public function readFailedMainFromStore(string $project, string $main, string $reason): void
    {
        $this->add(LedgerEventKind::BootResultComputed, ['project' => $project, 'main' => $main, 'tool_fingerprint' => 'tools-1', 'state' => 'failed', 'reason' => $reason, 'read_from' => 'store']);
    }

    public function computeFailedMain(string $project, string $main, string $reason): void
    {
        $this->add(LedgerEventKind::BootResultComputed, ['project' => $project, 'main' => $main, 'tool_fingerprint' => 'tools-1', 'state' => 'failed', 'reason' => $reason]);
    }

    public function computeMain(string $project, string $main, string $state): void
    {
        $this->add(LedgerEventKind::BootResultComputed, ['project' => $project, 'main' => $main, 'tool_fingerprint' => 'tools-1', 'state' => $state, 'reason' => '']);
    }

    public function failHeadRetryingAt(string $project, string $change, string $retryAt): void
    {
        $this->add(LedgerEventKind::HeadFailed, ['failures' => 1, 'retry_at' => $retryAt] + $this->headFailure($project, $change, 'a'));
    }

    public function failHeadWithoutCause(string $project, string $change): void
    {
        $this->add(LedgerEventKind::HeadFailed, ['project' => $project, 'change' => $change, 'head' => 'a', 'failures' => 1, 'retry_at' => '2026-10-28T12:01:00+00:00']);
    }

    public function failHeadWithoutRetryAt(string $project, string $change): void
    {
        $failure = $this->headFailure($project, $change, 'a');
        unset($failure['retry_at']);
        $this->add(LedgerEventKind::HeadFailed, ['failures' => 1] + $failure);
    }

    public function passBuildStep(string $project, string $task, string $step): void
    {
        $this->recordBuildStep(['project' => $project, 'task' => $task, 'step' => $step, 'outcome' => 'passed', 'failure' => null]);
    }

    public function failBuildStep(string $task, string $step, string $failure): void
    {
        $this->recordBuildStep(['project' => 'alpha', 'task' => $task, 'step' => $step, 'outcome' => 'failed', 'failure' => $failure]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordBuildStep(array $payload): void
    {
        $this->add(LedgerEventKind::BuildStepEnded, $payload);
    }

    /**
     * @return array<string, int|string>
     */
    private function inDiscount(string $id, string $severity, int $line): array
    {
        return ['id' => $id, 'severity' => $severity, 'text' => 'Text of '.$id.'.', 'file' => 'src/discount.js', 'line' => $line];
    }

    /**
     * @return array<string, string>
     */
    private function headFailure(string $project, string $change, string $head): array
    {
        return ['project' => $project, 'change' => $change, 'head' => $head, 'cause' => 'RuntimeException: commit '.$head.' not fetched', 'retry_at' => '2026-10-28T12:08:00+00:00'];
    }

    /**
     * @return array<string, string>
     */
    private function reviewer(string $round, string $role): array
    {
        return ['round' => $round, 'reviewer' => $round.'-'.$role, 'role' => $role, 'tier' => 'standard'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function add(LedgerEventKind $kind, array $payload, ?DateTimeImmutable $at = null): void
    {
        $this->recorded[] = new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)), $at ?? new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }

    // Under the round as its subject, as RoundLedger appends it, so readOfKindAbout() finds it.
    /**
     * @param array<string, mixed> $payload
     */
    private function recordReviewerStart(string $round, array $payload): void
    {
        $this->recorded[] = new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewerStarted, json_encode($payload, JSON_THROW_ON_ERROR), $round), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
