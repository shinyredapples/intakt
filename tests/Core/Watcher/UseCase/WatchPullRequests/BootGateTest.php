<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Review\Contract\BootAt;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootHarnessAtMain;
use Intakt\Core\Review\Contract\BootOutcome;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Core\Watcher\Domain\BootKey;
use Intakt\Core\Watcher\Domain\BootRecord;
use Intakt\Core\Watcher\Domain\FailedBoot;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootGate;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\BootResults;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\HeadGate;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatchedProject;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[Group('persistence')]
#[CoversClass(BootGate::class)]
#[CoversClass(BootResults::class)]
#[UsesClass(HeadDecision::class)]
#[UsesClass(FailedBoot::class)]
#[UsesClass(BootRecord::class)]
#[UsesClass(BootKey::class)]
#[UsesClass(BootAt::class)]
#[UsesClass(BootHarness::class)]
#[UsesClass(BootHarnessAtMain::class)]
#[UsesClass(BootOutcome::class)]
#[UsesClass(MainCommit::class)]
#[UsesClass(ChangeSource::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(OpenChange::class)]
#[UsesClass(WatchedProject::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(HeldCredentialRedactor::class)]
final class BootGateTest extends KernelTestCase
{
    use AssertsLoggedErrors;

    private EntityManagerInterface $entities;
    /** @var list<object> */
    private array $asked = [];
    private BootOutcome $main;
    private BootOutcome $head;
    private CommitId|string $mainCommit;
    private bool $busDown = false;
    private bool $authorTrusted = true;
    private bool $trustCheckDown = false;
    private Ledger $ledger;

    public function testAHeadThatFailsToBootWhileMainBootsIsSentBackAsAFailedBoot(): void
    {
        $this->head = BootOutcome::failed('The server did not start.');

        $decision = $this->gate()->admit($this->project(), $this->green());

        self::assertSame(HeadAdmission::FailedBoot, $decision->admission);
        self::assertSame('The server did not start.', $decision->failedBoot?->reason);
    }

    public function testAFailedBootStartsNoRoundSoItBeatsTheApprovalsAGreenHeadWouldCollect(): void
    {
        $this->head = BootOutcome::failed('The server did not start.');

        self::assertFalse($this->gate()->admit($this->project(), $this->green())->startsRound());
    }

    public function testAHeadThatBootsKeepsItsGreenDecision(): void
    {
        $green = $this->green();

        self::assertSame($green, $this->gate()->admit($this->project(), $green));
    }

    public function testTheHeadIsBootedBeforeAnyReviewWithMainsHarness(): void
    {
        $this->gate()->admit($this->project(), $this->green());

        $boot = array_first(array_filter($this->asked, static fn (object $query): bool => $query instanceof BootHarnessAtMain));
        self::assertInstanceOf(BootHarnessAtMain::class, $boot);
        self::assertSame(str_repeat('b', 40), $boot->head->commit->hash);
        self::assertSame(str_repeat('f', 40), $boot->main->hash);
    }

    public function testAHeadWhoseBootCrashesStillGoesToReview(): void
    {
        $this->head = BootOutcome::crashed('composer could not run');

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
    }

    public function testAHeadIsNotBootedAndGoesToReviewWhenMainFailsToBoot(): void
    {
        $this->main = BootOutcome::failed('main is broken');
        $this->head = BootOutcome::failed('The server did not start.');

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
        self::assertNotContains(BootHarnessAtMain::class, array_map(get_class(...), $this->asked));
    }

    public function testAHeadIsNotBootedAndGoesToReviewWhenMainsBootCrashes(): void
    {
        $this->main = BootOutcome::crashed('bin/instance is missing');
        $this->head = BootOutcome::failed('The server did not start.');

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
        self::assertNotContains(BootHarnessAtMain::class, array_map(get_class(...), $this->asked));
    }

    public function testACredentialInTheReasonOfAFailedHeadBootIsNotCarried(): void
    {
        $this->head = BootOutcome::failed('clone failed: https://user:synthetic-private-token@localhost/alpha.git');

        $decision = $this->gate()->admit($this->project(), $this->green());

        self::assertInstanceOf(FailedBoot::class, $decision->failedBoot);
        self::assertStringNotContainsString('synthetic-private-token', $decision->failedBoot->reason);
    }

    public function testAnUntrustedAuthorsHeadIsNotBootedAndKeepsItsGreenDecision(): void
    {
        $this->authorTrusted = false;
        $green = $this->green();

        self::assertSame($green, $this->gate()->admit($this->project(), $green));
        self::assertSame([], $this->asked);
    }

    public function testAnUntrustedAuthorsHeadThatWouldFailToBootStillGoesToReview(): void
    {
        $this->authorTrusted = false;
        $this->head = BootOutcome::failed('The server did not start.');

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
    }

    public function testAnUntrustedAuthorsSkippedBootIsRecordedWithItsCause(): void
    {
        $this->authorTrusted = false;

        $this->gate()->admit($this->project(), $this->green());

        $skipped = iterator_to_array($this->ledger->readOfKind(LedgerEventKind::HeadBootSkipped));
        self::assertCount(1, $skipped);
        self::assertSame(['project' => 'alpha', 'change' => '7', 'head' => str_repeat('b', 40), 'cause' => 'boot_skipped_untrusted_author'], json_decode($skipped[0]->event->payload, true));
    }

    public function testATrustedAuthorsHeadIsBootedAndRecordsNoSkippedBoot(): void
    {
        $this->gate()->admit($this->project(), $this->green());

        self::assertContains(BootHarnessAtMain::class, array_map(get_class(...), $this->asked));
        self::assertSame([], iterator_to_array($this->ledger->readOfKind(LedgerEventKind::HeadBootSkipped)));
    }

    public function testATrustCheckThatFailsBootsNothingAndTheReviewStillStarts(): void
    {
        $this->trustCheckDown = true;

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
        self::assertSame([], $this->asked);
        self::assertCount(1, iterator_to_array($this->ledger->readOfKind(LedgerEventKind::HeadBootSkipped)));
        self::assertLoggedError('warning', 'The pull request author counts as untrusted, because the trust check failed.');
    }

    public function testASkippedBootTheLedgerRefusesBootsNothingAndTheReviewStillStarts(): void
    {
        $this->authorTrusted = false;
        $refusing = self::createStub(Ledger::class);
        $refusing->method('append')->willReturn(AppendResult::failed('The ledger is full.'));
        $this->ledger = $refusing;

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
        self::assertSame([], $this->asked);
        self::assertLoggedError('error', 'The boot gate failed, so the review starts without it.');
    }

    public function testTheWatchersGateInTheContainerIsTheBootGate(): void
    {
        self::assertInstanceOf(BootGate::class, self::getContainer()->get(HeadGate::class));
    }

    public function testAGateThatThrowsLogsAnErrorAndTheReviewStillStarts(): void
    {
        $this->busDown = true;

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
        self::assertLoggedError('error', 'The boot gate failed, so the review starts without it.');
    }

    public function testAMainCommitQueryThatAnswersNoCommitLogsAnErrorAndTheReviewStillStarts(): void
    {
        $this->mainCommit = 'main';

        self::assertTrue($this->gate()->admit($this->project(), $this->green())->startsRound());
        self::assertLoggedError('error', 'The boot gate failed, so the review starts without it.');
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->entities = $entities;
        $this->main = BootOutcome::booted();
        $this->head = BootOutcome::booted();
        $this->mainCommit = new CommitId(str_repeat('f', 40));
    }

    private function gate(): BootGate
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn (object $query): Envelope => new Envelope($query, [new HandledStamp($this->answer($query), 'handler')]));
        $redactor = self::getContainer()->get(Redactor::class);
        self::assertInstanceOf(Redactor::class, $redactor);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $logger = new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.'));
        $results = new BootResults($bus, $this->entities, new MockClock('2026-11-13T10:00:00+00:00'), new WatcherLedger(new DashboardLedger()), $redactor, $logger, $doctrine, new LockFactory(new InMemoryStore()));

        return new BootGate($bus, $this->authors(), $results, new WatcherLedger($this->ledger), $logger);
    }

    private function answer(object $query): CommitId|BootOutcome|string
    {
        $this->asked[] = $query;

        if ($this->busDown) {
            throw new RuntimeException('The query bus is down.');
        }

        return match (true) {
            $query instanceof MainCommit => $this->mainCommit,
            $query instanceof BootHarness => $this->main,
            default => $this->head,
        };
    }

    private function authors(): AuthorTrust
    {
        $authors = self::createStub(AuthorTrust::class);
        $authors->method('isAuthorTrusted')->willReturnCallback(fn (): bool => $this->trustCheckDown ? throw new RuntimeException('The permission API is down.') : $this->authorTrusted);

        return $authors;
    }

    private function project(): WatchedProject
    {
        return new WatchedProject('alpha', new ChangeSource('/repos/alpha', 'main'), 'Review it.');
    }

    private function green(): HeadDecision
    {
        return new HeadDecision(new OpenChange(new ChangeId('7'), new CommitId(str_repeat('b', 40)), new MockClock('2026-11-13T10:00:00+00:00')->now()), HeadAdmission::Green);
    }
}
