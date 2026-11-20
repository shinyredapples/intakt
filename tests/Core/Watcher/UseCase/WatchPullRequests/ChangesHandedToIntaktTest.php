<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangesHandedToIntakt;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherEventNotRecorded;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests\RecordedForge;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(ChangesHandedToIntakt::class)]
#[CoversClass(WatcherLedger::class)]
#[UsesClass(WatcherEventNotRecorded::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
final class ChangesHandedToIntaktTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string LOOP_PULL = 'https://github.com/acme/widgets/pull/8';

    private DoctrineLedger $ledger;
    private EntityManagerInterface $entities;
    private ChangeAuthorship $authorship;

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $entities = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->ledger = new DoctrineLedger($registry, new MockClock('2026-11-20T10:00:00+00:00'));
        $this->authorship = self::createStub(ChangeAuthorship::class);
    }

    public function testAPullRequestIntaktOpenedIsRecordedAsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertSame([['project' => 'alpha', 'change' => self::PULL, 'head' => str_repeat('a', 40)]], $this->handedToIntakt());
    }

    public function testAPullRequestWithoutAnOpeningRecordOfIntaktIsNotRecordedAsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::LOOP_PULL));

        self::assertSame([], $this->handedToIntakt());
    }

    public function testAPullRequestIntaktOnlyUpdatedIsNotRecordedAsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/8', 'head' => str_repeat('a', 40), 'change' => self::LOOP_PULL, 'opening' => 'updated']);

        $this->recognize('alpha', $this->change(self::LOOP_PULL));

        self::assertSame([], $this->handedToIntakt());
    }

    public function testAnOpeningRecordThatDoesNotSayHowTheForgeGotThereHandsNoPullRequestAnotherAuthorCreatedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL]);

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertSame([], $this->handedToIntakt());
    }

    public function testAnOpenPullRequestTheAppCreatedBeforeOpeningsWereRecordedIsRecordedAsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL]);
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willReturnCallback(static fn (ChangeId $change): bool => self::PULL === $change->value);
        $this->authorship = $authorship;

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertSame([self::PULL], array_column($this->handedToIntakt(), 'change'));
    }

    public function testThePullRequestsAuthorIsNotAskedForAPullRequestWhoseRecordSaysHowTheForgeGotThere(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/8', 'head' => str_repeat('a', 40), 'change' => self::LOOP_PULL]);
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/8', 'head' => str_repeat('b', 40), 'change' => self::LOOP_PULL, 'opening' => 'updated']);
        $authorship = self::createMock(ChangeAuthorship::class);
        $authorship->expects(self::never())->method('isCreatedByIntakt');
        $this->authorship = $authorship;

        $this->recognize('alpha', $this->change(self::PULL), $this->change(self::LOOP_PULL, 'b'), $this->change('https://github.com/acme/widgets/pull/9'));

        self::assertSame([self::PULL], array_column($this->handedToIntakt(), 'change'));
    }

    public function testAnAuthorTheForgeCannotReadFailsTheRecognitionWithNothingRecorded(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL]);
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willThrowException(new ForgeReadFailed('unreadable'));
        $this->authorship = $authorship;

        try {
            $this->recognize('alpha', $this->change(self::PULL));
            self::fail('An author the forge cannot read must fail the recognition.');
        } catch (ForgeReadFailed) {
            self::assertSame([], $this->handedToIntakt());
        }
    }

    public function testTheLedgerStillTakesTheTicksNextLineAfterAnAuthorReadIsRateLimitedBehindARecognition(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL]);
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/8', 'head' => str_repeat('a', 40), 'change' => self::LOOP_PULL]);
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willReturnCallback(
            static fn (ChangeId $change): bool => self::PULL === $change->value ? true : throw new ForgeRateLimited(new DateTimeImmutable('2026-11-20T10:10:00+00:00')),
        );
        $this->authorship = $authorship;
        $connection = $this->entities->getConnection();

        // As the tick does: the recognition runs inside its transaction, the rate limit rolls it back, and the same process goes on to append its due heads' lines.
        $connection->beginTransaction();
        try {
            $this->recognize('alpha', $this->change(self::PULL), $this->change(self::LOOP_PULL));
            self::fail('A rate-limited author read must fail the recognition.');
        } catch (ForgeRateLimited) {
            $connection->rollBack();
        }

        self::assertSame([], $this->handedToIntakt());
        $this->record(LedgerEventKind::HeadPending, ['project' => 'alpha', 'change' => '1']);
    }

    public function testNoLineIsAppendedBeforeEveryAuthorIsRead(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/8', 'head' => str_repeat('a', 40), 'change' => self::LOOP_PULL]);
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willThrowException(new ForgeReadFailed('unreadable'));
        $this->authorship = $authorship;

        try {
            $this->recognize('alpha', $this->change(self::PULL), $this->change(self::LOOP_PULL));
            self::fail('An author the forge cannot read must fail the recognition.');
        } catch (ForgeReadFailed) {
            self::assertSame([], $this->handedToIntakt());
        }
    }

    public function testEveryPullRequestIntaktCreatedIsRecordedOnceAllAuthorsAreRead(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/8', 'head' => str_repeat('a', 40), 'change' => self::LOOP_PULL]);
        $authorship = self::createStub(ChangeAuthorship::class);
        $authorship->method('isCreatedByIntakt')->willReturn(true);
        $this->authorship = $authorship;

        $this->recognize('alpha', $this->change(self::PULL), $this->change(self::LOOP_PULL), $this->change(self::PULL));

        self::assertSame([self::PULL, self::LOOP_PULL], array_column($this->handedToIntakt(), 'change'));
    }

    public function testAPullRequestOpenedBeforeOpeningsWereRecordedIsRecordedAsHandedToIntaktAfterALaterPushRecordsItAsCreated(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL]);
        $this->recognize('alpha', $this->change(self::PULL));
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('b', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::PULL, 'b'));

        self::assertCount(1, $this->handedToIntakt());
    }

    public function testAPullRequestIntaktCreatedIsRecordedAsHandedToIntaktEvenAfterIntaktUpdatedIt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('b', 40), 'change' => self::PULL, 'opening' => 'updated']);

        $this->recognize('alpha', $this->change(self::PULL, 'b'));

        self::assertCount(1, $this->handedToIntakt());
    }

    public function testAnOpeningRecordInTheConfiguredCaseIsRecognizedFromTheAddressTheForgeLists(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change('https://github.com/Acme/Widgets/pull/7'));

        self::assertSame(['https://github.com/Acme/Widgets/pull/7'], array_column($this->handedToIntakt(), 'change'));
    }

    public function testAPullRequestRecordedAsHandedToIntaktInAnotherCaseGetsNoSecondRecord(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $this->recognize('alpha', $this->change('https://github.com/Acme/Widgets/pull/7'));

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertCount(1, $this->handedToIntakt());
    }

    public function testAnEmptyListingReadsNoLedger(): void
    {
        $ledger = self::createMock(Ledger::class);
        $ledger->expects(self::never())->method('readOfKind');

        new ChangesHandedToIntakt($ledger, new WatcherLedger(new InMemoryLedger()), $this->authorship)->recognizeAmong('alpha', []);
    }

    public function testAnOpeningRecordOfAnotherProjectHandsNoPullRequestOfTheSameAddressToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'beta', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertSame([], $this->handedToIntakt());
    }

    public function testAPullRequestIntaktOnlyIntendedToOpenIsNotRecordedAsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeIntended, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertSame([], $this->handedToIntakt());
    }

    public function testAPullRequestAlreadyRecordedAsHandedToIntaktGetsNoSecondRecord(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $this->recognize('alpha', $this->change(self::PULL));

        $this->recognize('alpha', $this->change(self::PULL, 'b'));

        self::assertCount(1, $this->handedToIntakt());
    }

    public function testOnlyTheIntaktOpenedPullRequestOfAListingIsRecordedAsHandedToIntakt(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::LOOP_PULL), $this->change(self::PULL));

        self::assertSame([self::PULL], array_column($this->handedToIntakt(), 'change'));
    }

    public function testAnUnreadableLedgerLineMentioningThePullRequestDoesNotStopTheRecognition(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ChangeOpened, '{"project":"alpha","change":"https:\/\/github.com\/acme\/widgets\/pull\/7"'))->succeeded());
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);

        $this->recognize('alpha', $this->change(self::PULL));

        self::assertCount(1, $this->handedToIntakt());
    }

    public function testARecordTheLedgerRefusesFailsTheRecognition(): void
    {
        $this->record(LedgerEventKind::ChangeOpened, ['project' => 'alpha', 'branch' => 'feat/7', 'head' => str_repeat('a', 40), 'change' => self::PULL, 'opening' => 'created']);
        $recognizer = new ChangesHandedToIntakt($this->ledger, new WatcherLedger(new InMemoryLedger(0)), $this->authorship);

        $this->expectException(WatcherEventNotRecorded::class);

        $recognizer->recognizeAmong('alpha', [$this->change(self::PULL)]);
    }

    /**
     * @param array<string, string> $payload
     */
    private function record(LedgerEventKind $kind, array $payload): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)))->succeeded());
    }

    private function recognize(string $project, OpenChange ...$changed): void
    {
        new ChangesHandedToIntakt($this->ledger, new WatcherLedger($this->ledger), $this->authorship)->recognizeAmong($project, array_values($changed));
    }

    private function change(string $id, string $head = 'a'): OpenChange
    {
        return RecordedForge::change($id, $head, '2026-11-20T09:00:00Z');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function handedToIntakt(): array
    {
        $payloads = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::ChangeHandedToIntakt) as $recorded) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);
            $payloads[] = $payload;
        }

        return $payloads;
    }
}
