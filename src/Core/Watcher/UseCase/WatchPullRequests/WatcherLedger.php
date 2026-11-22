<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\Mentions;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\FailedBoot;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\StartedRound;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class WatcherLedger
{
    private const string STORE = 'store';

    public function __construct(
        private Ledger $ledger,
        private FailingMains $failingMains = new FailingMains(),
    ) {
    }

    // Skipped for a green head because the round it starts is recorded instead.
    public function headDecided(string $project, HeadDecision $decision): void
    {
        $kind = match ($decision->admission) {
            HeadAdmission::Pending => LedgerEventKind::HeadPending,
            HeadAdmission::Red, HeadAdmission::FailedBoot => LedgerEventKind::HeadRed,
            HeadAdmission::Green => null,
        };

        if (null !== $kind) {
            $this->append($kind, $this->headOf($project, $decision) + ['read_again_at' => $decision->readAgainAt?->format(DATE_ATOM)] + $this->bootFailureOf($decision));
        }
    }

    public function roundQueued(StartedRound $round): void
    {
        $this->append(LedgerEventKind::RoundQueued, ['project' => $round->project(), 'change' => $round->change(), 'head' => $round->head(), 'round' => $round->roundId()]);
    }

    public function verdictsStale(StartedRound $round): void
    {
        $this->append(LedgerEventKind::VerdictsStale, ['project' => $round->project(), 'change' => $round->change(), 'round' => $round->roundId(), 'head' => $round->head()]);
    }

    public function headForgotten(string $project, OpenChange $closed): void
    {
        $this->append(LedgerEventKind::HeadForgotten, ['project' => $project, 'change' => $closed->id->value, 'head' => $closed->head->hash]);
    }

    public function changeHandedToIntakt(string $project, OpenChange $change): void
    {
        $this->append(LedgerEventKind::ChangeHandedToIntakt, ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);
    }

    public function handOverPublicationQueued(string $project, OpenChange $change): void
    {
        $this->append(LedgerEventKind::HandOverPublicationQueued, ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);
    }

    public function changeNotHandedToIntakt(string $project, OpenChange $change): void
    {
        $this->append(LedgerEventKind::ChangeNotHandedToIntakt, ['project' => $project, 'change' => $change->id->value, 'head' => $change->head->hash]);
    }

    public function headHandedOver(HeadFailure $failure, int $failures, DateTimeImmutable $retryAt): void
    {
        $this->append(LedgerEventKind::HeadHandedOver, $this->failureOf($failure, $failures, $retryAt));
    }

    public function headFailed(HeadFailure $failure, int $failures, DateTimeImmutable $retryAt): void
    {
        $this->append(LedgerEventKind::HeadFailed, $this->failureOf($failure, $failures, $retryAt));
    }

    public function bootResultComputed(BootKey $key, BootOutcome $outcome): void
    {
        $this->append(LedgerEventKind::BootResultComputed, $this->bootResultOf($key, $outcome));
    }

    // Marked, because the board trusts a result read from the store even for a pair it has seen, while a computed line of a seen pair may be a worker that finished late.
    public function bootResultReadFromStore(BootKey $key, BootOutcome $outcome): void
    {
        $this->append(LedgerEventKind::BootResultComputed, $this->bootResultOf($key, $outcome) + ['read_from' => self::STORE]);
    }

    // Read with the board's own rule over the project's lines, because a stored result must be compared with what the board shows, not with the last line, which the board may have passed over.
    public function failingMainOf(string $project): ?FailingMain
    {
        $lines = $this->ledger->readMentioning(new Mentions('"project":'.json_encode($project, JSON_THROW_ON_ERROR)));

        return array_find($this->failingMains->in($lines), static fn (FailingMain $failing): bool => $project === $failing->project);
    }

    // The infrastructure alert of a main that does not boot, so the gate is off for every head of the project until main boots again.
    public function mainBootFailed(BootKey $key, BootOutcome $outcome): void
    {
        $this->append(LedgerEventKind::MainBootFailed, ['project' => $key->project, 'main' => $key->main->hash, 'tool_fingerprint' => $key->toolFingerprint, 'reason' => $outcome->reason]);
    }

    public function headBootSkipped(string $project, HeadDecision $green): void
    {
        $this->append(LedgerEventKind::HeadBootSkipped, $this->headOf($project, $green) + ['cause' => BootGate::UNTRUSTED_AUTHOR]);
    }

    /**
     * @return array{project: string, main: string, tool_fingerprint: string, state: string, reason: string}
     */
    private function bootResultOf(BootKey $key, BootOutcome $outcome): array
    {
        return ['project' => $key->project, 'main' => $key->main->hash, 'tool_fingerprint' => $key->toolFingerprint, 'state' => $outcome->state->value, 'reason' => $outcome->reason];
    }

    /**
     * @return array{project: string, change: string, head: string, cause: string, failures: int, retry_at: string}
     */
    private function failureOf(HeadFailure $failure, int $failures, DateTimeImmutable $retryAt): array
    {
        return ['project' => $failure->project, 'change' => $failure->head->id->value, 'head' => $failure->head->head->hash, 'cause' => $failure->cause, 'failures' => $failures, 'retry_at' => $retryAt->format(DATE_ATOM)];
    }

    /**
     * @return array{project: string, change: string, head: string}
     */
    private function headOf(string $project, HeadDecision $decision): array
    {
        return ['project' => $project, 'change' => $decision->change->id->value, 'head' => $decision->change->head->hash];
    }

    /**
     * @return array{cause?: string, reason?: string}
     */
    private function bootFailureOf(HeadDecision $decision): array
    {
        return $decision->failedBoot instanceof FailedBoot ? ['cause' => FailedBoot::CAUSE, 'reason' => $decision->failedBoot->reason] : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw WatcherEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
