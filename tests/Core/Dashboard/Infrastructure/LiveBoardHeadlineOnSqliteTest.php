<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\Domain\EventRecord;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Core\Ledger\Infrastructure\UtcDateTimeImmutableType;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\UnpricedRoundCosts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[Group('persistence')]
#[CoversClass(LiveBoardHeadline::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(Lane::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(DoctrineLedger::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(EventRecord::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(UtcDateTimeImmutableType::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class LiveBoardHeadlineOnSqliteTest extends KernelTestCase
{
    private DoctrineLedger $ledger;

    private MockHttpClient $hub;

    private BufferingLogger $log;

    public function testTwoTriggersOverAnUnchangedSqliteLedgerPostTheBoardToTheHubOnce(): void
    {
        $headline = $this->headline();

        $headline->publishLatest(Uuid::v7());
        $headline->publishLatest(Uuid::v7());

        self::assertSame(1, $this->hub->getRequestsCount());
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testAReviewerStartedInTheSqliteLedgerBetweenTwoTriggersPostsTheBoardAgain(): void
    {
        $headline = $this->headline();

        $headline->publishLatest(Uuid::v7());
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"r1-security","role":"security","tier":"standard"}'))->succeeded());
        $headline->publishLatest(Uuid::v7());

        self::assertSame(2, $this->hub->getRequestsCount());
        self::assertSame([], $this->log->cleanLogs());
    }

    protected function setUp(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $tool = new SchemaTool($manager);
        $tool->dropDatabase();
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $this->ledger = new DoctrineLedger($registry, new MockClock('2026-10-28 12:00:00', 'UTC'));
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2,"change":"42","number":1}'))->succeeded());
        $this->hub = new MockHttpClient(static fn (): MockResponse => new MockResponse('urn:uuid:event'));
        $this->log = new BufferingLogger();
    }

    private function headline(): LiveBoardHeadline
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $lastPushed = self::getContainer()->get(DashboardServices::BOARD_HEADLINES);
        self::assertInstanceOf(CacheItemPoolInterface::class, $lastPushed);
        $lastPushed->clear();
        $pages = new LedgerReviewPages(new MockClock('2026-10-28 12:05:00', 'UTC'), $this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), new UnpricedRoundCosts(), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));
        // Shaped like a JWT only, because the hub refuses any other shape before it posts.
        $updates = new MercureLiveUpdates(new Hub('http://localhost/.well-known/mercure', new StaticTokenProvider('publisher.token.unsigned'), httpClient: $this->hub), $this->log);

        return new LiveBoardHeadline($pages, $twig, $updates, new LockFactory(new InMemoryStore()), $lastPushed, $this->log);
    }
}
