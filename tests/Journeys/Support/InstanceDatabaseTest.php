<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstanceDatabase::class)]
final class InstanceDatabaseTest extends TestCase
{
    private const string MESSAGE = ReviewWithReviewer::class;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY, body TEXT, headers TEXT, queue_name TEXT, delivered_at TEXT)');
        $this->pdo->exec('CREATE TABLE ledger_event (id INTEGER PRIMARY KEY, kind TEXT, payload TEXT)');
    }

    public function testQueuedMessagesAreCountedByTheirClassInTheDefaultQueueOnly(): void
    {
        $this->message('default', null);
        $this->message('default', '2026-11-01 00:00:00');
        $this->message('failed', null);

        $database = new InstanceDatabase($this->pdo);

        self::assertSame(2, $database->queued(self::MESSAGE));
        self::assertSame(1, $database->fetchedNotAcknowledged(self::MESSAGE));
    }

    public function testWaitingMessagesOfEveryClassAreCountedInTheDefaultQueueOnly(): void
    {
        $this->message('default', null);
        $this->message('default', '2026-11-01 00:00:00');
        $this->pdo->exec('INSERT INTO messenger_messages (body, headers, queue_name) VALUES (\'O:1:"y":0:{}\', \'{"type":"Other"}\', \'default\')');
        $this->message('failed', null);

        self::assertSame(3, new InstanceDatabase($this->pdo)->waiting());
    }

    public function testAFetchedMessageCanBeMadeAvailableAgain(): void
    {
        $this->message('default', '2026-11-01 00:00:00');
        $database = new InstanceDatabase($this->pdo);

        $database->makeFetchedMessagesAvailable();

        self::assertSame(0, $database->fetchedNotAcknowledged(self::MESSAGE));
        self::assertSame(1, $database->queued(self::MESSAGE));
    }

    public function testLedgerRowsAreCountedAndReadNewestFirst(): void
    {
        $this->pdo->exec('INSERT INTO ledger_event (kind, payload) VALUES (\'reviewer_started\', \'{"reviewer":"a"}\'), (\'reviewer_started\', \'{"reviewer":"b"}\'), (\'judge_done\', \'{}\')');
        $database = new InstanceDatabase($this->pdo);

        self::assertSame(2, $database->ledgerRows('reviewer_started'));
        self::assertSame([['reviewer' => 'b']], $database->payloads('reviewer_started', 1));
    }

    private function message(string $queue, ?string $deliveredAt): void
    {
        $statement = $this->pdo->prepare('INSERT INTO messenger_messages (body, headers, queue_name, delivered_at) VALUES (?, ?, ?, ?)');
        $statement->execute(['O:1:"x":0:{}', json_encode(['type' => self::MESSAGE]), $queue, $deliveredAt]);
    }
}
