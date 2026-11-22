<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

// On the application's Doctrine transports, which the test environment otherwise replaces with in-memory ones, because the failure transport shares the queue table with the async one.
#[Group('persistence')]
#[CoversClass(FailedJudgings::class)]
#[UsesClass(JudgeRound::class)]
#[UsesClass(PublishVerdict::class)]
final class FailedJudgingsTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';
    private const string ROUND = '0192a000-0000-7000-8000-000000000001';

    private ?string $configuredDsn;
    private DoctrineTransport $failed;
    private DoctrineTransport $async;

    public function testAParkedJudgingOfTheRoundIsAFailedJudging(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));

        self::assertTrue($this->judgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testAParkedJudgingOfAnotherRoundIsNoFailedJudgingOfThisOne(): void
    {
        $this->failed->send(new Envelope(new JudgeRound('0192a000-0000-7000-8000-000000000002')));

        self::assertFalse($this->judgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testAnotherParkedMessageOfTheRoundIsNoFailedJudging(): void
    {
        $this->failed->send(new Envelope(new PublishVerdict(self::ROUND)));

        self::assertFalse($this->judgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testAJudgingStillQueuedForAWorkerIsNoFailedJudging(): void
    {
        $this->async->send(new Envelope(new JudgeRound(self::ROUND)));

        self::assertFalse($this->judgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testReadingTheFailedJudgingsLeavesTheParkedMessageInPlace(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $judgings = $this->judgings($this->failed);

        $judgings->hasFailed(self::ROUND);

        self::assertTrue($judgings->hasFailed(self::ROUND));
        self::assertSame(1, $this->failed->getMessageCount());
    }

    public function testAFailureTransportThatCannotBeListedShowsNoFailedJudging(): void
    {
        $unlisted = $this->createMock(ReceiverInterface::class);
        $unlisted->expects($this->never())->method('get');

        self::assertFalse($this->judgings($unlisted)->hasFailed(self::ROUND));
    }

    public function testAParkedMessageThatCannotBeDecodedNeitherBreaksTheReadNorIsDeleted(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('garbage', '{\"type\":\"Gone\\\\Klass\"}', 'failed', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        self::assertTrue($this->judgings($this->failed)->hasFailed(self::ROUND));
        self::assertSame(2, $this->failed->getMessageCount());
    }

    public function testTheFailedRoundsAreTheRoundsRecordedAsFailedAndTheRoundsWhoseJudgingIsParked(): void
    {
        $recorded = '0192a000-0000-7000-8000-000000000002';
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willReturn(new RecordedEvents([new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::JudgingFailed, '{"round":"'.$recorded.'"}'), new DateTimeImmutable('2026-11-20T12:00:00+00:00'))]));
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $this->failed->send(new Envelope(new PublishVerdict('0192a000-0000-7000-8000-000000000003')));

        $failed = new FailedJudgings($this->failed, $ledger, self::getContainer()->get(LedgerPayloads::class))->failedRounds();

        self::assertEqualsCanonicalizing([self::ROUND, $recorded], array_keys($failed));
    }

    public function testAFailureTransportThatCannotBeListedAddsNoFailedRound(): void
    {
        $unlisted = $this->createMock(ReceiverInterface::class);
        $unlisted->expects($this->never())->method('get');

        self::assertSame([], $this->judgings($unlisted)->failedRounds());
    }

    public function testThePollOfOneRoundReadsTheFailureTransportUpToTheBoundAtMost(): void
    {
        $failed = $this->createMock(ListableReceiverInterface::class);
        $failed->expects($this->once())->method('all')->with(FailedJudgings::PARKED_MESSAGES_READ)->willReturn([]);

        $this->judgings($failed)->hasFailed(self::ROUND);
    }

    public function testThePollOfOneRoundOnABacklogPastTheBoundReadsAndLeavesEveryMessageParked(): void
    {
        $this->parkPublications(FailedJudgings::PARKED_MESSAGES_READ);
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $listed = 0;
        $counting = self::createStub(ListableReceiverInterface::class);
        $counting->method('all')->willReturnCallback(function (?int $limit) use (&$listed): iterable {
            foreach ($this->failed->all($limit) as $envelope) {
                ++$listed;

                yield $envelope;
            }
        });

        $this->judgings($counting)->hasFailed(self::ROUND);

        self::assertSame(FailedJudgings::PARKED_MESSAGES_READ, $listed);
        self::assertSame(FailedJudgings::PARKED_MESSAGES_READ + 1, $this->failed->getMessageCount());
    }

    public function testABacklogPastTheBoundDoesNotHideAParkedJudgingFromTheFailedRounds(): void
    {
        $this->parkPublications(FailedJudgings::PARKED_MESSAGES_READ);
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));

        self::assertArrayHasKey(self::ROUND, $this->judgings($this->failed)->failedRounds());
        self::assertSame(FailedJudgings::PARKED_MESSAGES_READ + 1, $this->failed->getMessageCount());
    }

    public function testAParkedJudgingWithoutARoundIdIsLeftOutOfTheFailedRoundsAndStaysParked(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $this->failed->send(new Envelope(new ReflectionClass(JudgeRound::class)->newInstanceWithoutConstructor()));

        self::assertSame([self::ROUND => true], $this->judgings($this->failed)->failedRounds());
        self::assertSame(2, $this->failed->getMessageCount());
    }

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? null;
        $this->configuredDsn = is_string($configured) ? $configured : null;
        $_ENV[self::TRANSPORT_DSN] = 'doctrine://default';

        $container = self::getContainer();
        EmptyDatabase::in($container);
        $failed = $container->get('messenger.transport.failed');
        $async = $container->get('messenger.transport.async');
        assert($failed instanceof DoctrineTransport && $async instanceof DoctrineTransport);
        $this->failed = $failed;
        $this->async = $async;
        $this->failed->setup();
    }

    protected function tearDown(): void
    {
        if (null === $this->configuredDsn) {
            unset($_ENV[self::TRANSPORT_DSN]);
        } else {
            $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;
        }

        parent::tearDown();
    }

    private function parkPublications(int $count): void
    {
        for ($parked = 0; $parked < $count; ++$parked) {
            $this->failed->send(new Envelope(new PublishVerdict('0192a000-0000-7000-8000-000000000003')));
        }
    }

    private function judgings(ReceiverInterface $failed): FailedJudgings
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willReturn(new RecordedEvents([]));

        return new FailedJudgings($failed, $ledger, new LedgerPayloads(self::createStub(SerializerInterface::class)));
    }
}
