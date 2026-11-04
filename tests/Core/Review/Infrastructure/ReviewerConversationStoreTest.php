<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Doctrine\DBAL\Connection;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Uid\UuidV7;

// Covers nothing because the store is the Agent component's and the migration loads by path; this proves the two agree.
#[Group('persistence')]
#[CoversNothing]
final class ReviewerConversationStoreTest extends KernelTestCase
{
    public function testTheTableTheMigrationsBuildHoldsAConversationPerRound(): void
    {
        $this->migrateAnEmptyDatabase();
        $store = self::getContainer()->get(MessageStoreInterface::class);
        self::assertInstanceOf(MessageStoreInterface::class, $store);
        $round = new UuidV7();

        $store->save(MessageBag::forId($round, Message::forSystem('You review.'), Message::ofUser('Review it.')));

        self::assertCount(2, $store->load($round));
        self::assertCount(0, $store->load(new UuidV7()));
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM reviewer_conversation'));
    }

    public function testTheStoreRefusesTheFirstMessagesOfAnotherReviewUnderAConversationAlreadyStored(): void
    {
        $this->migrateAnEmptyDatabase();
        $store = self::getContainer()->get(MessageStoreInterface::class);
        self::assertInstanceOf(MessageStoreInterface::class, $store);
        $conversation = new UuidV7();
        $store->save(MessageBag::forId($conversation, Message::forSystem('Role A.'), Message::ofUser('Review it.')));

        try {
            $store->save(MessageBag::forId($conversation, Message::forSystem('Role B.'), Message::ofUser('Review it.')));
            self::fail('The store must not let a second review start a stored conversation.');
        } catch (ConflictException) {
            // Expected, because the reviewer agent claims a conversation by this refusal.
        }

        self::assertSame('Role A.', $store->load($conversation)->getSystemMessage()?->getContent());
    }

    private function migrateAnEmptyDatabase(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        EmptyDatabase::in(self::getContainer());

        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);

        $console->assertCommandIsSuccessful($console->getDisplay());
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
