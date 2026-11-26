<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootHarnessAtMain;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Intakt\Plugin\Forge\Contract\CommitId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// Main boots once per commit and tool fingerprint, because every head of the project is judged against the same main result (a crash only for its short back-off); nothing here throws, because a boot only gates the review and must never stop it.
final readonly class BootResults
{
    public function __construct(
        #[Autowire(service: 'query.bus')]
        private MessageBusInterface $queries,
        private EntityManagerInterface $entities,
        private ClockInterface $clock,
        private WatcherLedger $ledger,
        private Redactor $redactor,
        private LoggerInterface $logger,
        private ManagerRegistry $registry,
        private LockFactory $locks,
    ) {
    }

    public function ofMain(BootKey $key, ChangeSource $source): BootOutcome
    {
        $now = $this->clock->now();
        $stored = $this->storedFor($key);

        if ($stored instanceof BootRecord && !$stored->answersAt($now)) {
            // Read again, because the entity manager keeps the expired crash although another worker may have stored a fresh result over it meanwhile.
            $this->entities->detach($stored);
            $stored = $this->storedFor($key);
        }

        if ($stored instanceof BootRecord && $stored->answersAt($now)) {
            $outcome = $stored->outcome();

            if ($stored->alertPending()) {
                $this->alert($key, $outcome);
                // Forgotten, because the claim changed the row behind the entity manager, which would keep answering with the pending alert.
                $this->entities->detach($stored);
            }

            $this->recordChangeBackTo($key, $outcome);

            return $outcome;
        }

        $outcome = $this->safe($this->boot(new BootHarness(new BootAt($source, $key->main))));
        $kept = $this->remember($key, $outcome, $stored);
        $this->record($key, $outcome);

        if ($kept && BootState::Failed === $outcome->state) {
            $this->alert($key, $outcome);
        }

        return $outcome;
    }

    // Never cached, because a head is judged once before its round; booted with main's harness, so a head that changes the harness cannot certify itself.
    public function ofHead(BootAt $head, CommitId $main): BootOutcome
    {
        return $this->safe($this->boot(new BootHarnessAtMain($head, $main)));
    }

    private function storedFor(BootKey $key): ?BootRecord
    {
        try {
            return $this->entities->find(BootRecord::class, ['project' => $key->project, 'main' => $key->main->hash, 'toolFingerprint' => $key->toolFingerprint]);
            // Boundary: logs an unreadable stored result and boots main again, because a lost cache entry must not stop the review the boot only gates.
        } catch (Throwable $unread) {
            $this->logger->error("Main's stored boot result could not be read, so main boots again.", ['exception' => $unread]);

            return null;
        }
    }

    // Claimed and recorded in one transaction, because the ledger writes through the same connection: a refused alert, or a worker that stops before the commit, leaves the row pending, so a later ask records it. A crash is no alert, because it is the boot step's fault and not main's, and would repeat after every back-off.
    private function alert(BootKey $key, BootOutcome $outcome): void
    {
        try {
            $this->entities->getConnection()->transactional(function (Connection $claiming) use ($key, $outcome): void {
                if ($this->claimedAlert($claiming, $key)) {
                    $this->ledger->mainBootFailed($key, $outcome);
                }
            });
            // Boundary: logs an alert the ledger refused, because the row stays pending so a later ask records it, and the review must go on.
        } catch (Throwable $unrecorded) {
            // Reset as the ledger resets it after a failed flush, because a line the ledger flushed before the rollback stays managed though the database no longer holds it, and the next flush would close the manager.
            $this->registry->resetManager();
            $this->logger->error("Main's boot failure could not be recorded in the ledger as an alert.", ['exception' => $unrecorded]);
        }
    }

    // One conditional update, so of two workers that read the same pending alert only the one that changes the row records it.
    private function claimedAlert(Connection $claiming, BootKey $key): bool
    {
        return 1 === $claiming->update(BootRecord::TABLE, ['alert_pending' => false], BootRecord::keyRow($key) + ['alert_pending' => true], ['alert_pending' => 'boolean']);
    }

    // Recorded only when the board shows otherwise, because the board reads it from the ledger, which a stored result never reaches when main or the tools go back to a pair already seen; a repeated hit of the same state, or a crash, changes nothing the board shows.
    private function recordChangeBackTo(BootKey $key, BootOutcome $outcome): void
    {
        if (BootState::Crashed === $outcome->state) {
            return;
        }

        // Not released on destruction, because recordWhileHolding() gives it back itself.
        $lock = $this->locks->createLock('watcher-main-boot-'.hash('sha256', $key->project), autoRelease: false);

        try {
            $this->recordWhileHolding($lock, $key, $outcome);
            // Boundary: logs a stored result the ledger or the lock refused, because the next hit of the same stored result records it again and the review must go on.
        } catch (Throwable $unrecorded) {
            $this->logger->error("Main's stored boot result could not be recorded in the ledger.", ['exception' => $unrecorded]);
        }
    }

    // Turned into an outcome because a boot that throws would stop the review it only gates.
    private function boot(BootHarness|BootHarnessAtMain $query): BootOutcome
    {
        try {
            $result = $this->queries->dispatch($query)->last(HandledStamp::class)?->getResult();
            // Boundary: turns any failure of the boot into a crashed outcome, because the boot runs foreign code whose failures cannot be named in advance.
        } catch (Throwable $failure) {
            return BootOutcome::crashed($failure::class.': '.$failure->getMessage());
        }

        return $result instanceof BootOutcome ? $result : BootOutcome::crashed('The boot query did not answer an outcome.');
    }

    // Scrubbed first, because a reason may carry bytes of a tool's output that are not UTF-8, and the row and the ledger line must still be written; redacted as a log line is, because the reason is stored and shown.
    private function safe(BootOutcome $outcome): BootOutcome
    {
        $reason = $this->redactor->redact(mb_scrub($outcome->reason, 'UTF-8'));

        return match ($outcome->state) {
            BootState::Booted => $outcome,
            BootState::Failed => BootOutcome::failed($reason),
            BootState::Crashed => BootOutcome::crashed($reason),
        };
    }

    // A crash is kept only for its back-off, because it is the boot step's fault and not main's: an expired one is replaced by the new result, so a broken tool is never trusted for good.
    private function remember(BootKey $key, BootOutcome $outcome, ?BootRecord $expired): bool
    {
        $record = new BootRecord($key, $outcome, $this->clock->now());

        if ($expired instanceof BootRecord) {
            // Detached, because the entity manager would answer every later read with the expired crash.
            $this->entities->detach($expired);
        }

        try {
            // In a savepoint, because PostgreSQL refuses every further statement of a caller's transaction in which one failed.
            $this->entities->getConnection()->transactional(fn (Connection $inner): int|string => $this->replaceExpiredCrashAndInsert($inner, $record));
        } catch (UniqueConstraintViolationException) {
            // Ignored, because then another worker booted the same main meanwhile, and its row answers the next ask as well as this one would.
            return false;
            // Boundary: logs a result that cannot be stored, because main then only boots again next time and the review must go on.
        } catch (Throwable $unstored) {
            $this->logger->error("Main's boot result could not be stored, so main boots again next time.", ['exception' => $unstored]);

            return false;
        }

        return true;
    }

    // Only a crashed row is deleted, because a worker that already replaced it with a booted or failed result must keep that result for good.
    private function replaceExpiredCrashAndInsert(Connection $inner, BootRecord $record): int|string
    {
        $row = $record->row();
        $inner->delete(BootRecord::TABLE, ['project' => $row['project'], 'main_commit' => $row['main_commit'], 'tool_fingerprint' => $row['tool_fingerprint'], 'state' => BootState::Crashed->value]);

        return $inner->insert(BootRecord::TABLE, $row, BootRecord::rowTypes());
    }

    private function record(BootKey $key, BootOutcome $outcome): void
    {
        try {
            $this->ledger->bootResultComputed($key, $outcome);
            // Boundary: logs a ledger line that cannot be written, because the computed result still gates the review.
        } catch (Throwable $unrecorded) {
            $this->logger->error("Main's computed boot result could not be recorded in the ledger.", ['exception' => $unrecorded]);
        }
    }

    // Read and appended under one lock per project, because two hits that both read the board before either appends would record the same change twice. Tried without waiting, because the review must not wait on it, and a hit that finds it busy appends nothing: the board then ends as if the dropped hit had come just before the holder's, and the next hit of the current main reads the board again and corrects it.
    private function recordWhileHolding(LockInterface $lock, BootKey $key, BootOutcome $outcome): void
    {
        if (!$lock->acquire(false)) {
            return;
        }

        try {
            if ($this->boardShowsOtherThan($key, $outcome)) {
                $this->ledger->bootResultReadFromStore($key, $outcome);
            }
        } finally {
            $lock->release();
        }
    }

    private function boardShowsOtherThan(BootKey $key, BootOutcome $outcome): bool
    {
        $shown = $this->ledger->failingMainOf($key->project);

        return BootState::Booted === $outcome->state ? $shown instanceof FailingMain : true !== $shown?->isOf($key->main->hash, $key->toolFingerprint);
    }
}
