<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Budget\Contract\InstallWideFailures;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Budget\Domain\FullDisk;
use Intakt\Core\Budget\Domain\ItemFailureRecord;
use Intakt\Core\Budget\Domain\SharedHoldRecord;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineInstallWideFailures;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Core\Watcher\Domain\PendingHead;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\FailedHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\PendingHeads;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherEventNotRecorded;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordingLogger as WatcherRecordingLogger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[Group('persistence')]
#[CoversClass(FailedHeads::class)]
#[UsesClass(PendingHeads::class)]
#[UsesClass(PendingHead::class)]
#[UsesClass(HeadFailure::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(HeldCredentialRedactor::class)]
#[UsesClass(HeldCredentials::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
#[UsesClass(DoctrineInstallWideFailures::class)]
#[UsesClass(ItemFailureRecord::class)]
#[UsesClass(FullDisk::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(SharedHoldRecord::class)]
#[UsesClass(SharedHold::class)]
#[UsesClass(WatcherEventNotRecorded::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
final class FailedHeadsTest extends KernelTestCase
{
    use AssertsLoggedErrors;

    // This name because config/services.yaml lists it among the held credentials, and this value because no secret shape matches it, so only the held-credential redaction can catch it.
    private const string HELD_NAME = 'OPENAI_COMPATIBLE_API_KEY';
    private const string HELD_VALUE = 'plainheldkeyvalue0123456789';
    private MockClock $clock;
    private EntityManagerInterface $entities;
    private PendingHeads $pending;
    private DoctrineHolds $holds;

    public function testTheSameHeadListedAgainWhileItsFourthFailureIsRecordedStaysDueWithoutFailures(): void
    {
        $old = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $listedAgain = RecordedForge::change('7', 'a', '2026-11-04T09:30:00Z');
        $this->pending->enqueue('alpha', [$old]);
        $this->pending->backOff(new HeadFailure('alpha', $old, 'RuntimeException: down'), 3, $this->clock->now());
        $pending = $this->pending;
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturnCallback(static function () use ($pending, $listedAgain): AppendResult {
            $pending->enqueue('alpha', [$listedAgain]);

            return AppendResult::appended(Uuid::v7());
        });

        $this->failedHeads($ledger)->backOff('alpha', $old, new RuntimeException('down'));

        self::assertCount(1, $this->pending->dueFor('alpha'));
        self::assertSame(0, $this->pending->failuresOf('alpha', $listedAgain));
    }

    public function testAHeldCredentialInAFailureMessageReachesNeitherTheLedgerNorTheCard(): void
    {
        $_SERVER[self::HELD_NAME] = self::HELD_VALUE;
        $head = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $ledger = new DashboardLedger();

        $this->failedHeads($ledger)->backOff('alpha', $head, new RuntimeException('401 Unauthorized for key '.self::HELD_VALUE));

        $cards = new LedgerFailingHeads($ledger)->now();
        self::assertCount(1, $cards);
        self::assertSame('RuntimeException: 401 Unauthorized for key [redacted]', $cards[0]->fault->cause);
        foreach ($ledger->readInOrder() as $recorded) {
            self::assertStringNotContainsString(self::HELD_VALUE, $recorded->event->payload);
        }
    }

    public function testAHeadClosedWhileItsStepWasInFlightGetsNoRetryCard(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $ledger = new DashboardLedger();
        new WatcherLedger($ledger)->headForgotten('alpha', $this->pending->forgetClosed('alpha')[0]);

        $this->failedHeads($ledger)->backOff('alpha', $head, new RuntimeException('CI read failed'));

        self::assertSame([], $this->pending->dueFor('alpha'));
        self::assertSame([], new LedgerFailingHeads($ledger)->now());
    }

    public function testAHeadStillQueuedWhenItsStepFailsGetsARetryCard(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $ledger = new DashboardLedger();

        $this->failedHeads($ledger)->backOff('alpha', $head, new RuntimeException('CI read failed'));

        self::assertCount(1, new LedgerFailingHeads($ledger)->now());
        self::assertSame(1, $this->pending->failuresOf('alpha', $head));
    }

    public function testTheSameErrorOnASecondHeadWithinAnHourStartsTheFailureBackOff(): void
    {
        [$first, $second] = $this->twoQueuedHeads();
        $failedHeads = $this->failedHeads(new DashboardLedger());
        $failedHeads->backOff('alpha', $first, new RuntimeException('CI endpoint down'));
        $this->clock->sleep(3599);

        $failedHeads->backOff('alpha', $second, new RuntimeException('CI endpoint down'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because two items failed with the same error within an hour.');
    }

    public function testTheSameErrorOnTheSameHeadAgainStartsNoFailureBackOff(): void
    {
        [$first] = $this->twoQueuedHeads();
        $failedHeads = $this->failedHeads(new DashboardLedger());
        $failedHeads->backOff('alpha', $first, new RuntimeException('CI endpoint down'));
        $this->clock->sleep(60);

        $failedHeads->backOff('alpha', $first, new RuntimeException('CI endpoint down'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testTheSameChangeInAnotherProjectIsAnotherHead(): void
    {
        $head = RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z');
        $this->pending->enqueue('alpha', [$head]);
        $this->pending->enqueue('beta', [$head]);
        $failedHeads = $this->failedHeads(new DashboardLedger());
        $failedHeads->backOff('alpha', $head, new RuntimeException('CI endpoint down'));

        $failedHeads->backOff('beta', $head, new RuntimeException('CI endpoint down'));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertLoggedError('error', 'The install-wide failure back-off started because two items failed with the same error within an hour.');
    }

    public function testRefusedForgeCredentialsOnOneHeadStartTheFailureBackOffAndStillBackTheHeadOff(): void
    {
        [$head] = $this->twoQueuedHeads();
        $refused = new ForgeReadFailed('GitHub answered 401 to a read.', previous: new ForgeCredentialsRefused());

        $this->failedHeads(new DashboardLedger())->backOff('alpha', $head, new RuntimeException('CI read failed', 0, $refused));

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertSame(1, $this->pending->failuresOf('alpha', $head));
        self::assertLoggedError('error', "The install-wide failure back-off started because the forge refused Intakt's credentials.");
    }

    public function testAForgeReadFailureWithoutRefusedCredentialsOnOneHeadStartsNoFailureBackOff(): void
    {
        [$head] = $this->twoQueuedHeads();

        $this->failedHeads(new DashboardLedger())->backOff('alpha', $head, new ForgeReadFailed('GitHub answered 403 to a read.'));

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testAHandOverTheLedgerRefusesStartsTheFailureBackOffAndFailsTheTick(): void
    {
        [$head] = $this->twoQueuedHeads();
        $this->pending->backOff(new HeadFailure('alpha', $head, 'RuntimeException: down'), 3, $this->clock->now());

        try {
            $this->failedHeads($this->refusingLedger())->backOff('alpha', $head, new RuntimeException('down'));
            self::fail('The refused hand-over was swallowed.');
        } catch (WatcherEventNotRecorded) {
            // Expected: the tick fails, so the hand-over is tried again.
        }

        self::assertSame([SharedHold::FailureBackOff], $this->holds->sharedInEffect());
        self::assertSame(3, $this->pending->failuresOf('alpha', $head));
        self::assertLoggedError('error', 'The install-wide failure back-off started because a hand-over failed.');
    }

    public function testAFailureLineTheLedgerRefusesBeforeTheHandOverStartsNoFailureBackOff(): void
    {
        [$head] = $this->twoQueuedHeads();
        $this->pending->backOff(new HeadFailure('alpha', $head, 'RuntimeException: down'), 2, $this->clock->now());

        try {
            $this->failedHeads($this->refusingLedger())->backOff('alpha', $head, new RuntimeException('down'));
            self::fail('The refused failure line was swallowed.');
        } catch (WatcherEventNotRecorded) {
            // Expected: the tick fails, so the line is tried again.
        }

        self::assertSame([], $this->holds->sharedInEffect());
    }

    public function testAHandOverFailureIsRethrownAndTheFailedBackOffLoggedWhenTheBackOffCannotStartEither(): void
    {
        [$head] = $this->twoQueuedHeads();
        $this->pending->backOff(new HeadFailure('alpha', $head, 'RuntimeException: down'), 3, $this->clock->now());
        $installWide = self::createStub(InstallWideFailures::class);
        $installWide->method('handOverFailed')->willThrowException(new RuntimeException('budget_shared_hold is locked'));
        $logger = new WatcherRecordingLogger();

        try {
            $this->failedHeadsWith($this->refusingLedger(), $installWide, $logger)->backOff('alpha', $head, new RuntimeException('down'));
            self::fail('The refused hand-over was swallowed.');
        } catch (WatcherEventNotRecorded) {
            // Expected, because the hand-over's own failure, not the back-off's, must fail the tick.
        }

        $notStarted = array_find($logger->logged, static fn (array $line): bool => 'The failed hand-over could not start the install-wide failure back-off.' === $line[0]);
        self::assertIsArray($notStarted);
        $exception = $notStarted[1]['exception'] ?? null;
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('budget_shared_hold is locked', $exception->getMessage());
    }

    public function testAnInstallWideRecordWhoseFlushFailsLeavesTheEntityManagerOpenForTheNextHead(): void
    {
        [$first, $next] = $this->twoQueuedHeads();
        // A unique item and an earlier row of each head under another error, because that refuses only the real flush, on every engine, as a locked Budget table would, and closes the entity manager every holder shares.
        foreach ([$first, $next] as $head) {
            $this->entities->persist(new ItemFailureRecord(json_encode(['watcher head', 'alpha', $head->id->value], JSON_THROW_ON_ERROR), 'earlier error', $this->clock->now()));
        }
        $this->entities->flush();
        $this->entities->clear();
        $this->entities->getConnection()->executeStatement('CREATE UNIQUE INDEX refuse_item_failure ON budget_item_failure (item)');
        $failed = $this->failedHeads(new DashboardLedger());

        $failed->backOff('alpha', $first, new RuntimeException('CI endpoint down'));
        $failed->backOff('alpha', $next, new RuntimeException('CI endpoint down'));

        self::assertTrue($this->entities->isOpen());
        self::assertSame(1, $this->pending->failuresOf('alpha', $first));
        self::assertSame(1, $this->pending->failuresOf('alpha', $next));
        self::assertLoggedErrors(2, 'The install-wide failure record failed; the head still backs off alone.');
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->clock = new MockClock('2026-11-04T10:00:00+00:00');
        $this->entities = $entities;
        $this->pending = new PendingHeads($entities, $this->clock);
        $this->holds = new DoctrineHolds($entities, $this->clock);
    }

    protected function tearDown(): void
    {
        unset($_SERVER[self::HELD_NAME]);
        parent::tearDown();
    }

    /**
     * @return array{OpenChange, OpenChange}
     */
    private function twoQueuedHeads(): array
    {
        $heads = [RecordedForge::change('7', 'a', '2026-11-04T09:00:00Z'), RecordedForge::change('8', 'b', '2026-11-04T09:00:00Z')];
        $this->pending->enqueue('alpha', $heads);

        return $heads;
    }

    private function refusingLedger(): Ledger
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('append')->willReturn(AppendResult::failed('the ledger is read-only'));

        return $ledger;
    }

    private function failedHeads(Ledger $ledger): FailedHeads
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $logger = new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.'));
        $installWide = new DoctrineInstallWideFailures($this->entities, $doctrine, $this->holds, $this->clock, $logger);

        return $this->failedHeadsWith($ledger, $installWide, $logger);
    }

    // The container's redactor, so the test proves the wiring: the names config/services.yaml holds, behind the Platform contract.
    private function failedHeadsWith(Ledger $ledger, InstallWideFailures $installWide, LoggerInterface $logger): FailedHeads
    {
        $redactor = self::getContainer()->get(Redactor::class);
        self::assertInstanceOf(Redactor::class, $redactor);

        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return new FailedHeads($this->pending, new WatcherLedger($ledger), $this->clock, $redactor, $this->entities->getConnection(), $installWide, $logger, $doctrine);
    }
}
