<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
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
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(BootResults::class)]
#[CoversClass(BootRecord::class)]
#[UsesClass(BootKey::class)]
#[UsesClass(BootAt::class)]
#[UsesClass(BootHarness::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(HeldCredentialRedactor::class)]
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
