<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

// Main boots once per commit and tool fingerprint, because every head of the project is judged against the same main result; nothing here throws, because a boot only gates the review and must never stop it.
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
    ) {
    }

    public function ofMain(BootKey $key, ChangeSource $source): BootOutcome
    {
        $stored = $this->storedFor($key);

        if ($stored instanceof BootRecord) {
            return $stored->outcome();
        }

        $outcome = $this->safe($this->boot(new BootAt($source, $key->main)));
        $this->remember($key, $outcome);
        $this->record($key, $outcome);

        return $outcome;
    }

    private function storedFor(BootKey $key): ?BootRecord
    {
        try {
            return $this->entities->find(BootRecord::class, ['project' => $key->project, 'main' => $key->main->hash, 'toolFingerprint' => $key->toolFingerprint]);
        } catch (Throwable $unread) {
            $this->logger->error("Main's stored boot result could not be read, so main boots again.", ['exception' => $unread]);

            return null;
        }
    }

    // A crash is the boot step's, not main's, so it is never kept: the next ask boots again instead of trusting a broken tool for good.
    private function remember(BootKey $key, BootOutcome $outcome): void
    {
        if (BootState::Crashed === $outcome->state) {
            return;
        }

        try {
            // In a savepoint, because PostgreSQL refuses every further statement of a caller's transaction in which one failed.
            $this->entities->getConnection()->transactional(fn (Connection $inner): int|string => $inner->insert(BootRecord::TABLE, new BootRecord($key, $outcome, $this->clock->now())->row(), BootRecord::rowTypes()));
        } catch (UniqueConstraintViolationException) {
            // Ignored, because then another worker booted the same main meanwhile, and its row answers the next ask as well as this one would.
        } catch (Throwable $unstored) {
            $this->logger->error("Main's boot result could not be stored, so main boots again next time.", ['exception' => $unstored]);
        }
    }

    private function record(BootKey $key, BootOutcome $outcome): void
    {
        try {
            $this->ledger->bootResultComputed($key, $outcome);
        } catch (Throwable $unrecorded) {
            $this->logger->error("Main's computed boot result could not be recorded in the ledger.", ['exception' => $unrecorded]);
        }
    }

    // Turned into an outcome because a boot that throws would stop the review it only gates.
    private function boot(BootAt $at): BootOutcome
    {
        try {
            $result = $this->queries->dispatch(new BootHarness($at))->last(HandledStamp::class)?->getResult();
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
}
