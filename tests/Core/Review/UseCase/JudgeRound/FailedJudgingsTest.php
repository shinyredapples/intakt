<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

// On the application's Doctrine transports, which the test environment otherwise replaces with in-memory ones, because the failure transport shares the queue table with the async one.
#[Group('persistence')]
#[CoversClass(FailedJudgings::class)]
final class FailedJudgingsTest extends KernelTestCase
{
    private const string TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';
    private const string ROUND = '0192a000-0000-7000-8000-000000000001';

    private string $configuredDsn;
    private DoctrineTransport $failed;
    private DoctrineTransport $async;

    protected function setUp(): void
    {
        $configured = $_ENV[self::TRANSPORT_DSN] ?? '';
        $this->configuredDsn = is_string($configured) ? $configured : '';
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
        $_ENV[self::TRANSPORT_DSN] = $this->configuredDsn;

        parent::tearDown();
    }

    public function testAParkedJudgingOfTheRoundIsAFailedJudging(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));

        self::assertTrue(new FailedJudgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testAParkedJudgingOfAnotherRoundIsNoFailedJudgingOfThisOne(): void
    {
        $this->failed->send(new Envelope(new JudgeRound('0192a000-0000-7000-8000-000000000002')));

        self::assertFalse(new FailedJudgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testAnotherParkedMessageOfTheRoundIsNoFailedJudging(): void
    {
        $this->failed->send(new Envelope(new PublishVerdict(self::ROUND)));

        self::assertFalse(new FailedJudgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testAJudgingStillQueuedForAWorkerIsNoFailedJudging(): void
    {
        $this->async->send(new Envelope(new JudgeRound(self::ROUND)));

        self::assertFalse(new FailedJudgings($this->failed)->hasFailed(self::ROUND));
    }

    public function testReadingTheFailedJudgingsLeavesTheParkedMessageInPlace(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $judgings = new FailedJudgings($this->failed);

        $judgings->hasFailed(self::ROUND);

        self::assertTrue($judgings->hasFailed(self::ROUND));
        self::assertSame(1, $this->failed->getMessageCount());
    }

    public function testAFailureTransportThatCannotBeListedShowsNoFailedJudging(): void
    {
        $inMemory = new InMemoryTransport();
        $inMemory->send(new Envelope(new JudgeRound(self::ROUND)));

        self::assertFalse(new FailedJudgings($inMemory)->hasFailed(self::ROUND));
        self::assertCount(1, $inMemory->get());
    }

    public function testAParkedMessageThatCannotBeDecodedNeitherBreaksTheReadNorIsDeleted(): void
    {
        $this->failed->send(new Envelope(new JudgeRound(self::ROUND)));
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('garbage', '{\"type\":\"Gone\\\\Klass\"}', 'failed', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        self::assertTrue(new FailedJudgings($this->failed)->hasFailed(self::ROUND));
        self::assertSame(2, $this->failed->getMessageCount());
    }
}
