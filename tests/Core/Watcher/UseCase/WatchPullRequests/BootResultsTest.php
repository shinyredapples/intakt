<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261114120000;
use Intakt\Core\Dashboard\Domain\FailingMain;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingMains;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\BootState;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootResults;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(BootResults::class)]
#[CoversClass(BootRecord::class)]
#[CoversClass(Version20261114120000::class)]
#[UsesClass(BootKey::class)]
#[UsesClass(BootAt::class)]
#[UsesClass(BootHarness::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(HeldCredentialRedactor::class)]
#[UsesClass(LedgerFailingMains::class)]
#[UsesClass(FailingMain::class)]
#[UsesClass(LedgerPayload::class)]
final class BootResultsTest extends KernelTestCase
{
    private EntityManagerInterface $entities;
    private MockClock $clock;
    private DashboardLedger $ledger;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->entities = $entities;
        $this->clock = new MockClock('2026-11-13T10:00:00+00:00');
        $this->ledger = new DashboardLedger();
    }

    public function testMainsBootResultIsReusedForTheSameCommitAndToolFingerprint(): void
    {
        $bus = new BootingBus(BootOutcome::booted());
        $results = $this->results($bus);

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $second = $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(1, $bus->booted);
        self::assertSame(BootState::Booted, $second->state);
    }

    public function testMainsBootResultIsReusedAfterTheEntityManagerForgotIt(): void
    {
        $bus = new BootingBus(BootOutcome::failed('The server did not start.'));
        $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());
        $this->entities->clear();

        $again = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(1, $bus->booted);
        self::assertSame(BootState::Failed, $again->state);
        self::assertSame('The server did not start.', $again->reason);
    }

    public function testMainsBootResultIsRecomputedWhenMainsCommitChanges(): void
    {
        $bus = new BootingBus(BootOutcome::booted());
        $results = $this->results($bus);

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('b', 'tools-1'), $this->source());

        self::assertCount(2, $bus->booted);
        self::assertSame(str_repeat('b', 40), $bus->booted[1]->at->commit->hash);
    }

    public function testMainsBootResultIsRecomputedWhenTheToolFingerprintChanges(): void
    {
        $bus = new BootingBus(BootOutcome::booted());
        $results = $this->results($bus);

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-2'), $this->source());

        self::assertCount(2, $bus->booted);
    }

    public function testMainsBootResultOfAnotherProjectIsNotReused(): void
    {
        $bus = new BootingBus(BootOutcome::booted());
        $results = $this->results($bus);

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain(new BootKey('beta', new CommitId(str_repeat('a', 40)), 'tools-1'), $this->source());

        self::assertCount(2, $bus->booted);
    }

    public function testACrashingBootIsReturnedAsACrashedOutcomeAndBootsAgainNextTime(): void
    {
        $bus = new BootingBus(BootOutcome::crashed('composer could not run'));
        $results = $this->results($bus);

        $first = $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Crashed, $first->state);
        self::assertSame('composer could not run', $first->reason);
        self::assertCount(2, $bus->booted);
    }

    public function testABootThatThrowsNeverReachesTheCallerAndIsACrashedOutcome(): void
    {
        $bus = new BootingBus(BootOutcome::booted());
        $bus->throws = true;

        $outcome = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertSame('RuntimeException: The query bus is down.', $outcome->reason);
        self::assertNull($this->entities->find(BootRecord::class, ['project' => 'alpha', 'main' => str_repeat('a', 40), 'toolFingerprint' => 'tools-1']));
    }

    public function testComputingABootResultAppendsOneBootResultEventAndACacheHitAppendsNone(): void
    {
        $results = $this->results(new BootingBus(BootOutcome::failed('No status.')));

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        $events = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::BootResultComputed));
        self::assertCount(1, $events);
        self::assertSame(
            ['project' => 'alpha', 'main' => str_repeat('a', 40), 'tool_fingerprint' => 'tools-1', 'state' => 'failed', 'reason' => 'No status.'],
            json_decode($events[0]->event->payload, true),
        );
    }

    public function testTheRecordHoldsTheMomentItWasComputed(): void
    {
        $this->results(new BootingBus(BootOutcome::booted()))->ofMain($this->key('a', 'tools-1'), $this->source());
        $this->entities->clear();

        $record = $this->entities->find(BootRecord::class, ['project' => 'alpha', 'main' => str_repeat('a', 40), 'toolFingerprint' => 'tools-1']);

        self::assertInstanceOf(BootRecord::class, $record);
        self::assertSame('2026-11-13T10:00:00+00:00', $record->recordedAt()->format(DATE_ATOM));
        self::assertTrue($this->key('a', 'tools-1')->equals($record->key()));
    }

    public function testACacheMissThatAnotherWorkerStoredMeanwhileStillReturnsItsOutcome(): void
    {
        $other = new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration());
        $otherWorker = new BootResults(new BootingBus(BootOutcome::booted()), $other, $this->clock, new WatcherLedger($this->ledger), $this->redactor(), $this->logger());
        $bus = new BootingBus(BootOutcome::booted());
        $bus->meanwhile = fn (): BootOutcome => $otherWorker->ofMain($this->key('a', 'tools-1'), $this->source());

        $outcome = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertTrue($this->entities->isOpen());
    }

    public function testABootResultThatCannotBeStoredIsStillReturnedAndTheFailureIsLogged(): void
    {
        $bus = new BootingBus(BootOutcome::failed('The server did not start.'));
        $bus->meanwhile = fn (): int|string => $this->entities->getConnection()->executeStatement('DROP TABLE watcher_boot_result');

        $outcome = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertTrue($this->entities->isOpen());
        self::assertLoggedError('error', "Main's boot result could not be stored, so main boots again next time.");
    }

    public function testAStoredResultThatCannotBeReadIsBootedAgainAndTheFailureIsLogged(): void
    {
        $this->entities->getConnection()->executeStatement('DROP TABLE watcher_boot_result');
        $bus = new BootingBus(BootOutcome::booted());

        $outcome = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertCount(1, $bus->booted);
        self::assertLoggedError('error', "Main's stored boot result could not be read, so main boots again.");
        self::assertLoggedError('error', "Main's boot result could not be stored, so main boots again next time.");
    }

    public function testACredentialInTheMessageOfAThrowingBootReachesNeitherTheCallerNorTheLedger(): void
    {
        $bus = new BootingBus(BootOutcome::booted());
        $bus->meanwhile = static fn (): never => throw new RuntimeException('Authorization: Bearer synthetic-private-token');

        $outcome = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertStringStartsWith('RuntimeException: Authorization: ', $outcome->reason);
        self::assertStringNotContainsString('synthetic-private-token', $outcome->reason);
        self::assertStringNotContainsString('synthetic-private-token', $this->recordedPayload());
    }

    public function testACredentialInTheReasonOfAFailedBootIsNotStored(): void
    {
        $this->results(new BootingBus(BootOutcome::failed('clone failed: https://user:synthetic-private-token@localhost/alpha.git')))->ofMain($this->key('a', 'tools-1'), $this->source());
        $this->entities->clear();

        $stored = $this->results(new BootingBus(BootOutcome::booted()))->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Failed, $stored->state);
        self::assertStringNotContainsString('synthetic-private-token', $stored->reason);
    }

    public function testACrashReasonThatIsNotUtf8IsStillReturnedAndRecordedInTheLedger(): void
    {
        $outcome = $this->results(new BootingBus(BootOutcome::crashed("tool error: \xFF")))->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Crashed, $outcome->state);
        self::assertSame('tool error: ?', $outcome->reason);
        self::assertStringContainsString('tool error: ?', $this->recordedPayload());
    }

    public function testAFailedReasonThatIsNotUtf8IsStoredScrubbed(): void
    {
        $this->results(new BootingBus(BootOutcome::failed("server said \xFF")))->ofMain($this->key('a', 'tools-1'), $this->source());
        $this->entities->clear();

        $stored = $this->results(new BootingBus(BootOutcome::booted()))->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame('server said ?', $stored->reason);
    }

    public function testALedgerLineThatCannotBeWrittenStillReturnsTheOutcomeAndIsLogged(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturn(AppendResult::failed('The ledger is down.'));
        $results = new BootResults(new BootingBus(BootOutcome::booted()), $this->entities, $this->clock, new WatcherLedger($ledger), $this->redactor(), $this->logger());

        $outcome = $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertLoggedError('error', "Main's computed boot result could not be recorded in the ledger.");
    }

    public function testAMainThatFailsToBootRecordsOneAlertAndAskingAgainRecordsNoSecondOne(): void
    {
        $results = $this->results(new BootingBus(BootOutcome::failed('No status.')));

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        $alerts = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed));
        self::assertCount(1, $alerts);
        self::assertSame(
            ['project' => 'alpha', 'main' => str_repeat('a', 40), 'tool_fingerprint' => 'tools-1', 'reason' => 'No status.'],
            json_decode($alerts[0]->event->payload, true),
        );
    }

    public function testAMainThatBootsRecordsNoAlert(): void
    {
        $this->results(new BootingBus(BootOutcome::booted()))->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testACrashedMainBootRecordsNoAlert(): void
    {
        $this->results(new BootingBus(BootOutcome::crashed('composer could not run')))->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testAFailedMainThatAnotherWorkerStoredFirstRecordsNoAlertForThisWorker(): void
    {
        $other = new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration());
        $otherLedger = new DashboardLedger();
        $otherWorker = new BootResults(new BootingBus(BootOutcome::failed('No status.')), $other, $this->clock, new WatcherLedger($otherLedger), $this->redactor(), $this->logger());
        $bus = new BootingBus(BootOutcome::failed('No status.'));
        $bus->meanwhile = fn (): BootOutcome => $otherWorker->ofMain($this->key('a', 'tools-1'), $this->source());

        $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
        self::assertCount(1, iterator_to_array($otherLedger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testACredentialInTheReasonOfAFailedMainIsNotInTheAlert(): void
    {
        $this->results(new BootingBus(BootOutcome::failed('clone failed: https://user:synthetic-private-token@localhost/alpha.git')))->ofMain($this->key('a', 'tools-1'), $this->source());

        $alerts = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed));
        self::assertCount(1, $alerts);
        self::assertStringNotContainsString('synthetic-private-token', $alerts[0]->event->payload);
    }

    public function testAnAlertTheLedgerRefusesStillReturnsTheOutcomeAndIsLogged(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(static fn (LedgerEvent $event): AppendResult => LedgerEventKind::MainBootFailed === $event->kind ? AppendResult::failed('The ledger is down.') : AppendResult::appended(Uuid::v7()));
        $results = new BootResults(new BootingBus(BootOutcome::failed('No status.')), $this->entities, $this->clock, new WatcherLedger($ledger), $this->redactor(), $this->logger());

        $outcome = $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertLoggedError('error', "Main's boot failure could not be recorded in the ledger as an alert.");
    }

    public function testAnAlertTheLedgerRefusedIsRecordedOnceWhenTheLedgerRecovers(): void
    {
        $refusals = 1;
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(function (LedgerEvent $event) use (&$refusals): AppendResult {
            return LedgerEventKind::MainBootFailed === $event->kind && $refusals-- > 0 ? AppendResult::failed('The ledger is down.') : $this->ledger->append($event);
        });
        $bus = new BootingBus(BootOutcome::failed('No status.'));
        $results = new BootResults($bus, $this->entities, $this->clock, new WatcherLedger($ledger), $this->redactor(), $this->logger());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(1, $bus->booted);
        self::assertCount(1, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
        self::assertLoggedError('error', "Main's boot failure could not be recorded in the ledger as an alert.");
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testAnAlertWhoseWorkerIsKilledBeforeItIsAppendedIsRecordedOnceByARestartedWorker(): void
    {
        $ended = $this->signalThatEndedAWorkerKilledAtTheAlert();
        $bus = new BootingBus(BootOutcome::failed('No status.'));
        $restarted = $this->results($bus);

        $restarted->ofMain($this->key('a', 'tools-1'), $this->source());
        $restarted->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(SIGKILL, $ended, 'The worker reached the append of the alert and was killed there.');
        self::assertCount(0, $bus->booted, 'The killed worker stored the failed result, so the restarted one reads it.');
        self::assertCount(1, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testAnAlertTheLedgerStoredInATransactionThatFailsIsRolledBackAndRecordedOnceByALaterAsk(): void
    {
        $stored = self::getContainer()->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $stored);
        $failures = 1;
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(static function (LedgerEvent $event) use ($stored, &$failures): AppendResult {
            $appended = $stored->append($event);

            return LedgerEventKind::MainBootFailed === $event->kind && $failures-- > 0 ? AppendResult::failed('The answer was lost.') : $appended;
        });
        $results = new BootResults(new BootingBus(BootOutcome::failed('No status.')), $this->entities, $this->clock, new WatcherLedger($ledger), $this->redactor(), $this->logger());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        // Cleared as a worker clears it after each message, because the ledger's entity manager still holds the line that was rolled back.
        $this->entities->clear();

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(1, iterator_to_array($stored->readOfKind(LedgerEventKind::MainBootFailed)));
        self::assertLoggedError('error', "Main's boot failure could not be recorded in the ledger as an alert.");
    }

    public function testAPendingAlertThatCannotBeClaimedStillReturnsTheOutcomeAndIsLogged(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturn(AppendResult::failed('The ledger is down.'));
        $results = new BootResults(new BootingBus(BootOutcome::failed('No status.')), $this->entities, $this->clock, new WatcherLedger($ledger), $this->redactor(), $this->logger());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        // Asked a second time, because that ask reads the stored result into the entity manager, which then answers the ask after the table is gone.
        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $this->entities->getConnection()->executeStatement('DROP TABLE watcher_boot_result');

        $outcome = $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Failed, $outcome->state);
        self::assertTrue($this->entities->isOpen());
        self::assertLoggedError('error', "Main's computed boot result could not be recorded in the ledger.");
        self::assertLoggedErrors(3, "Main's boot failure could not be recorded in the ledger as an alert.");
    }

    public function testAFailedMainStoredBeforeTheAlertExistedGetsItsAlertOnceAfterTheMigration(): void
    {
        $this->storedBeforeTheAlertExisted(BootOutcome::failed('No status.'));
        $bus = new BootingBus(BootOutcome::booted());
        $results = $this->results($bus);

        $results->ofMain($this->key('a', 'tools-1'), $this->source());
        $results->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(0, $bus->booted);
        self::assertCount(1, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testABootedMainStoredBeforeTheAlertExistedGetsNoAlertAfterTheMigration(): void
    {
        $this->storedBeforeTheAlertExisted(BootOutcome::booted());
        $bus = new BootingBus(BootOutcome::failed('No status.'));

        $outcome = $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Booted, $outcome->state);
        self::assertCount(0, $bus->booted);
        self::assertCount(0, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testAnAlertAnotherWorkerAlreadyRecordedIsNotRecordedAgainByACacheHit(): void
    {
        $this->results(new BootingBus(BootOutcome::failed('No status.')))->ofMain($this->key('a', 'tools-1'), $this->source());
        $other = new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration());

        new BootResults(new BootingBus(BootOutcome::booted()), $other, $this->clock, new WatcherLedger($this->ledger), $this->redactor(), $this->logger())->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertCount(1, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::MainBootFailed)));
    }

    public function testABootedResultThatLostTheStoreToAFailedMainKeepsTheBannerOfTheFailure(): void
    {
        $other = new EntityManager($this->entities->getConnection(), $this->entities->getConfiguration());
        $winner = new BootResults(new BootingBus(BootOutcome::failed('No status.')), $other, $this->clock, new WatcherLedger($this->ledger), $this->redactor(), $this->logger());
        $bus = new BootingBus(BootOutcome::booted());
        $bus->meanwhile = fn (): BootOutcome => $winner->ofMain($this->key('a', 'tools-1'), $this->source());

        $this->results($bus)->ofMain($this->key('a', 'tools-1'), $this->source());

        self::assertSame(BootState::Failed, $winner->ofMain($this->key('a', 'tools-1'), $this->source())->state);
        self::assertSame(['alpha'], array_map(static fn (FailingMain $main): string => $main->project, new LedgerFailingMains()->in($this->ledger->readInOrder())));
    }

    // A forked process on a connection of its own, because only a process that dies runs no further line of the worker and loses its open transaction as a killed worker does.
    private function signalThatEndedAWorkerKilledAtTheAlert(): int
    {
        $dying = self::createStub(Ledger::class);
        $dying->method('append')->willReturnCallback(static function (LedgerEvent $event): AppendResult {
            if (LedgerEventKind::MainBootFailed === $event->kind) {
                posix_kill(posix_getpid(), SIGKILL);
            }

            return AppendResult::appended(Uuid::v7());
        });
        $redactor = $this->redactor();
        $worker = pcntl_fork();
        self::assertNotSame(-1, $worker);

        if (0 === $worker) {
            try {
                $own = new EntityManager(DriverManager::getConnection($this->entities->getConnection()->getParams()), $this->entities->getConfiguration());
                new BootResults(new BootingBus(BootOutcome::failed('No status.')), $own, $this->clock, new WatcherLedger($dying), $redactor, new NullLogger())->ofMain($this->key('a', 'tools-1'), $this->source());
            } finally {
                // Ended by another signal, because a worker that missed the append must neither pass as killed there nor run the rest of the tests.
                posix_kill(posix_getpid(), SIGTERM);
            }
        }

        $status = 0;
        pcntl_waitpid($worker, $status);
        self::assertIsInt($status);

        return pcntl_wifsignaled($status) ? (int) pcntl_wtermsig($status) : 0;
    }

    // The test schema comes from the mapping, so the column is dropped and the row stored as it was before the migration, which then runs.
    private function storedBeforeTheAlertExisted(BootOutcome $outcome): void
    {
        $connection = $this->entities->getConnection();
        $connection->executeStatement('ALTER TABLE watcher_boot_result DROP COLUMN alert_pending');
        $row = new BootRecord($this->key('a', 'tools-1'), $outcome, $this->clock->now())->row();
        unset($row['alert_pending']);
        $connection->insert(BootRecord::TABLE, $row, BootRecord::rowTypes());
        $migration = new Version20261114120000($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), array_values($query->getParameters()), array_values(array_filter($query->getTypes(), is_string(...))));
        }
    }

    private function recordedPayload(): string
    {
        $events = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::BootResultComputed));
        self::assertCount(1, $events);

        return $events[0]->event->payload;
    }

    private function results(BootingBus $bus): BootResults
    {
        return new BootResults($bus, $this->entities, $this->clock, new WatcherLedger($this->ledger), $this->redactor(), $this->logger());
    }

    private function redactor(): Redactor
    {
        $redactor = self::getContainer()->get(Redactor::class);
        self::assertInstanceOf(Redactor::class, $redactor);

        return $redactor;
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.'));
    }

    private function key(string $commit, string $fingerprint): BootKey
    {
        return new BootKey('alpha', new CommitId(str_repeat($commit, 40)), $fingerprint);
    }

    private function source(): ChangeSource
    {
        return new ChangeSource('/repos/alpha', 'main');
    }
}
