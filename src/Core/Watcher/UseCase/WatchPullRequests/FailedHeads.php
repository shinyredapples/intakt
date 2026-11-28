<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

final readonly class FailedHeads
{
    private const int FIRST_WAIT_SECONDS = 60;

    // Capped, because until the maintainer agent takes a handed-over head, a fault that clears (a commit not fetched yet, a CI endpoint down) must still let the head be reviewed within half an hour.
    private const int LONGEST_WAIT_SECONDS = 1800;

    // Four in a row, as for a failing bench, because a passing fault clears within those doubling waits and one that does not needs someone to look.
    private const int HAND_OVER_AFTER = 4;

    public function __construct(
        private PendingHeads $pending,
        private WatcherLedger $ledger,
        private ClockInterface $clock,
        private Redactor $redactor,
        private Connection $connection,
        private InstallWideFailures $installWide,
        private LoggerInterface $logger,
        private ManagerRegistry $doctrine,
    ) {
    }

    public function backOff(string $project, OpenChange $head, Throwable $failure): void
    {
        if (self::causedBy($failure, NewWorkHeld::class)) {
            $this->waitForRoomOnTheDisk($project, $head);

            return;
        }

        $failures = $this->pending->failuresOf($project, $head) + 1;
        $headFailure = new HeadFailure($project, $head, $this->causeOf($failure));
        $retryAt = $this->retryAt($failures);

        $this->reportToTheInstall($this->itemOf($project, $head), $failure);
        $this->recordOnce($headFailure, $failures, $retryAt);
    }

    // No failure and no install-wide report, because a hold clears by itself once space is back: counted, a long low-disk spell handed every head over, and the same words on two heads started the failure back-off.
    private function waitForRoomOnTheDisk(string $project, OpenChange $head): void
    {
        $this->logger->info('A head waits for room on the disk.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash]);
        $this->pending->waitUntil($project, $head, $this->clock->now()->modify(sprintf('+%d seconds', self::FIRST_WAIT_SECONDS)));
    }

    // Scrubbed first, because a message may carry bytes of a path or a tool's output that are not UTF-8, and the ledger line must still be written; redacted as a log line is, because the cause is stored and shown on a card.
    private function causeOf(Throwable $failure): string
    {
        return $failure::class.': '.$this->redactor->redact(mb_scrub($failure->getMessage(), 'UTF-8'));
    }

    private function retryAt(int $failures): DateTimeImmutable
    {
        $wait = min(self::FIRST_WAIT_SECONDS * 2 ** ($failures - 1), self::LONGEST_WAIT_SECONDS);

        return $this->clock->now()->modify(sprintf('+%d seconds', $wait));
    }

    // Encoded as a list, because a project name may hold any character and a joined key could name two heads alike; the change alone, because a new commit of the same pull request is the same item.
    private function itemOf(string $project, OpenChange $head): string
    {
        return json_encode(['watcher head', $project, $head->id->value], JSON_THROW_ON_ERROR);
    }

    // Contained, because the head's own back-off and the next heads must not depend on Budget's write, which fails on its own when the Budget table is locked or the database connection is lost.
    private function reportToTheInstall(string $item, Throwable $failure): void
    {
        try {
            $this->reportCauseOf($item, $failure);
            // Boundary: logs a failed install-wide record and reopens the entity manager, so the head still backs off alone and the next heads run.
        } catch (Throwable $notReported) {
            $this->logger->error('The install-wide failure record failed; the head still backs off alone.', ['exception' => $notReported]);
            $this->reopenEntityManager();
        }
    }

    private function reportCauseOf(string $item, Throwable $failure): void
    {
        if (self::causedBy($failure, ForgeCredentialsRefused::class)) {
            $this->installWide->forgeCredentialsRefused($failure);

            return;
        }

        $this->installWide->itemFailed($item, $failure);
    }

    // Budget's failed flush closes the entity manager for every holder and Doctrine refuses to reopen it, so without a reset the next head's step would fail with a cause it never had.
    private function reopenEntityManager(): void
    {
        $entities = $this->doctrine->getManager();

        if ($entities instanceof EntityManagerInterface && !$entities->isOpen()) {
            $this->doctrine->resetManager();
        }
    }

    // Along the whole chain, because the forge chains its credential refusal and the Git steps chain a disk hold as the cause of their own failure, which a step may wrap again.
    /**
     * @param class-string<Throwable> $kind
     */
    private static function causedBy(Throwable $failure, string $kind): bool
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof $kind) {
                return true;
            }
        }

        return false;
    }

    private function recordOnce(HeadFailure $headFailure, int $failures, DateTimeImmutable $retryAt): void
    {
        try {
            // One transaction, because a failure the ledger could not take must leave the head due, so the tick fails and the next one tries again.
            $this->connection->transactional(function () use ($headFailure, $failures, $retryAt): void {
                // Row first, because its write lock holds off a listing that closes or re-queues the head, and a head no longer queued as it failed must get no line, or its card would promise a try that never comes.
                if ($this->pending->backOff($headFailure, $failures, $retryAt)) {
                    $this->record($headFailure, $failures, $retryAt);
                }
            });
            // Boundary: rethrows after the rollback, and first reports a failed hand-over when the count reaches the hand-over threshold, because the head must stay due so the tick fails and the next one tries again.
        } catch (Throwable $notRecorded) {
            // After the rollback, because the failure back-off must outlast it.
            if (self::HAND_OVER_AFTER === $failures) {
                $this->reportHandOverFailed($notRecorded);
            }

            throw $notRecorded;
        }
    }

    // Handed over once, at the failure that reaches the limit, so the maintainer agent gets one line per head while later tries are recorded as failures.
    private function record(HeadFailure $failure, int $failures, DateTimeImmutable $retryAt): void
    {
        if (self::HAND_OVER_AFTER === $failures) {
            $this->ledger->headHandedOver($failure, $failures, $retryAt);

            return;
        }

        $this->ledger->headFailed($failure, $failures, $retryAt);
    }

    // Logged and not thrown, because the hand-over's own failure is what the tick must report, never hidden behind the back-off's.
    private function reportHandOverFailed(Throwable $handOver): void
    {
        try {
            $this->installWide->handOverFailed($handOver);
            // Boundary: logs a back-off that cannot start, because the hand-over's own failure is the one the caller rethrows.
        } catch (Throwable $notStarted) {
            $this->logger->error('The failed hand-over could not start the install-wide failure back-off.', ['exception' => $notStarted]);
        }
    }
}
