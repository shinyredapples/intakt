<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundOpening;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

// On the application's Doctrine transport, which the test environment otherwise replaces with the in-memory one; the queue table is made as messenger:setup-transports makes it.
#[Group('persistence')]
#[CoversClass(RunReviewRoundHandler::class)]
#[CoversClass(RoundOpening::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(RoundLedger::class)]
#[UsesClass(RoundOpened::class)]
final class DoctrineQueueTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';

    private string $configuredDsn;
    private RoundFixture $fixture;
    private MessageBusInterface $commands;
    private DoctrineTransport $queue;

    public function testAFirstSendNeverCreatesTheQueueTableInsideARoundsTransaction(): void
    {
        try {
            $this->fixture->startOn($this->commands, 1);
            self::fail('The round was queued although its table does not exist.');
        } catch (TransportException $refused) {
            self::assertInstanceOf(TableNotFoundException::class, $refused->getPrevious());
        }

        self::assertSame([], $this->fixture->storedRounds());
    }

    public function testTheMessagesOfARoundAndItsAnnouncementAreQueuedInTheRoundsTransaction(): void
    {
        $this->queue->setup();

        $round = $this->fixture->startOn($this->commands, 3);

        self::assertSame(4, $this->queue->getMessageCount());
        self::assertEquals([new RoundOpened($round, 'alpha')], $this->queuedAnnouncements());
    }

    public function testARoundOpenedInsideATransactionThatRollsBackIsNeverAnnounced(): void
    {
        $this->queue->setup();
        $connection = $this->fixture->entities->getConnection();
        $connection->beginTransaction();

        try {
            $this->fixture->startOn($this->commands, 1);
        } finally {
            $connection->rollBack();
        }

        self::assertSame([], $this->queuedAnnouncements());
        self::assertSame([], $this->fixture->storedRounds());
    }

    public function testARoundWhoseReviewerMessageCannotBeQueuedTakesItsQueuedAnnouncementBackWithIt(): void
    {
        $this->queue->setup();

        try {
            $this->fixture->startOn(new RefusingQueue($this->commands), 3);
            self::fail('The refused dispatch was swallowed.');
        } catch (RuntimeException $refused) {
            self::assertSame('queue unavailable', $refused->getMessage());
        }

        self::assertSame([], $this->queuedAnnouncements());
        self::assertSame(0, $this->queue->getMessageCount());
        self::assertSame([], $this->fixture->storedRounds());
    }

    public function testTheAnnouncementOfARoundIsQueuedAheadOfItsReviewerMessages(): void
    {
        $this->queue->setup();

        $this->fixture->startOn($this->commands, 2);

        self::assertSame(
            [RoundOpened::class, ReviewWithReviewer::class, ReviewWithReviewer::class],
            array_map(static fn (Envelope $queued): string => $queued->getMessage()::class, iterator_to_array($this->queue->all(), false)),
        );
    }

    public function testTheQueueStillTakesTheNextRoundAfterARolledBackOne(): void
    {
        $this->queue->setup();

        try {
            $this->fixture->startOn(new RefusingQueue($this->commands), 3);
        } catch (RuntimeException) {
        }

        $this->fixture->startOn($this->commands, 1);

        self::assertSame(2, $this->queue->getMessageCount());
    }

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? '';
        $this->configuredDsn = is_string($configured) ? $configured : '';
        $_ENV[self::TRANSPORT_DSN] = 'doctrine://default';

        $container = self::getContainer();
        $this->fixture = new RoundFixture($container);
        $commands = $container->get('command.bus');
        $this->commands = $commands;
        $queue = $container->get('messenger.transport.async');
        assert($queue instanceof DoctrineTransport);
        $this->queue = $queue;
    }

    protected function tearDown(): void
    {
        $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;

        parent::tearDown();
    }

    /**
     * @return list<RoundOpened>
     */
    private function queuedAnnouncements(): array
    {
        $messages = array_map(static fn (Envelope $queued): object => $queued->getMessage(), iterator_to_array($this->queue->all(), false));

        return array_values(array_filter($messages, static fn (object $message): bool => $message instanceof RoundOpened));
    }
}
