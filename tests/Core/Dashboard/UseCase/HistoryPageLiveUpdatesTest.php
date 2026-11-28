<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DOMNode;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\History;
use Intakt\Core\Dashboard\Domain\HistoryRow;
use Intakt\Core\Dashboard\Domain\HistorySource;
use Intakt\Core\Dashboard\Domain\HistoryTopic;
use Intakt\Core\Dashboard\Infrastructure\LiveHistoryRows;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\UseCase\ShowHistory\ShowHistoryController;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventAppended;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

#[CoversClass(LiveHistoryRows::class)]
#[CoversClass(ShowHistoryController::class)]
#[CoversClass(History::class)]
#[UsesClass(HistoryRow::class)]
#[UsesClass(HistorySource::class)]
#[UsesClass(HistoryTopic::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(LedgerEventAppended::class)]
#[UsesClass(PayloadFields::class)]
final class HistoryPageLiveUpdatesTest extends DashboardPageTestCase
{
    use AssertsLoggedErrors;
    use DownloadedScriptStandIns;

    private ?string $secretBefore = null;

    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testTheHistoryPageListensToTheHistoryTopicBesideTheShellTopic(): void
    {
        $source = $this->browser->request('GET', '/history')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::HISTORY), (string) $source->attr('src'));
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
    }

    public function testAnAppendedEventPutsItsRowOnTopOfAnOpenHistoryPageExactlyAsAReloadShowsIt(): void
    {
        $this->givenTheHubRecordsWhatIsPublished();
        $this->record(['actor' => 'owner', 'project' => 'alpha']);
        $page = $this->browser->request('GET', '/history');

        $this->recordAndAnnounce(['actor' => 'maintainer', 'project' => 'beta', 'before' => 'low', 'after' => 'high']);

        self::assertCount(1, $this->published);
        self::assertSame([DashboardTopics::HISTORY], $this->published[0]->getTopics());
        $pushed = $this->applied($page, $this->published[0]->getData());
        self::assertSame(['maintainer', 'owner'], $pushed->filter('#history-rows .who')->each(static fn (Crawler $who): string => $who->text()));
        $reloaded = $this->browser->request('GET', '/history');
        self::assertSame($reloaded->filter('#history-rows')->html(), $pushed->filter('#history-rows')->html());
    }

    public function testAPushedReviewerRowTakesTheProjectOfItsRound(): void
    {
        $this->givenTheHubRecordsWhatIsPublished();
        $this->ledger->startRound('round-1', 'alpha');
        $page = $this->browser->request('GET', '/history');

        $this->announce(new LedgerEvent(LedgerEventKind::ReviewerStopRequested, '{"round":"round-1","reviewer":"reviewer-1"}'));

        self::assertSame('alpha', $this->applied($page, $this->published[0]->getData())->filter('#history-rows tr')->first()->attr('data-project'));
    }

    public function testTheFirstRowPushedToAnEmptyHistoryPageTakesThePlaceOfTheEmptyState(): void
    {
        $this->givenTheHubRecordsWhatIsPublished();
        $page = $this->browser->request('GET', '/history');
        self::assertCount(1, $page->filter('#history-empty'));
        self::assertSame('', $page->filter('.history-ledger tbody#history-rows')->html());

        $this->recordAndAnnounce(['actor' => 'owner']);

        $pushed = $this->applied($page, $this->published[0]->getData());
        self::assertCount(0, $pushed->filter('#history-empty'));
        self::assertSame(['owner'], $pushed->filter('#history-rows .who')->each(static fn (Crawler $who): string => $who->text()));
    }

    public function testAHubThatIsDownLeavesTheEventStoredAndTheHistoryPageStanding(): void
    {
        $this->browser->disableReboot();
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), static fn (): string => throw new RuntimeException('the hub is down'));
        $logger = self::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(LoggerInterface::class, $logger);
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, $logger));
        $this->browser->request('GET', '/history');

        $this->recordAndAnnounce(['actor' => 'owner', 'project' => 'alpha']);

        self::assertLoggedError('warning', 'A live dashboard update was not published.');
        $page = $this->browser->request('GET', '/history');
        self::assertResponseIsSuccessful();
        self::assertSame(['owner'], $page->filter('#history-rows .who')->each(static fn (Crawler $who): string => $who->text()));
    }

    public function testARowThatCannotBeReadIsLoggedAndNothingIsPublished(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readMentioning')->willThrowException(new RuntimeException('the database is gone'));
        $updates = $this->createMock(LiveUpdates::class);
        $updates->expects(self::never())->method('publish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('A live History row was not published.');
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->ledger->startRound('round-1', 'alpha');
        $recorded = array_last(iterator_to_array($this->ledger->readInOrder()));
        self::assertInstanceOf(RecordedEvent::class, $recorded);

        new LiveHistoryRows($ledger, $updates, $twig, $logger)(new LedgerEventAppended($recorded));
    }

    #[Override]
    protected function setUp(): void
    {
        $before = $_SERVER['MERCURE_JWT_SECRET'] ?? null;
        $this->secretBefore = is_string($before) ? $before : null;
        // A fresh random key, because a committed one would be a secret; the page only signs a listener's token with it.
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(16));
        parent::setUp();
        $this->givenTheDownloadedScriptsExist();
    }

    #[Override]
    protected function tearDown(): void
    {
        $secret = $this->secretBefore ?? '';
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = $secret;
        $this->removeTheStandIns();
        parent::tearDown();
    }

    private function givenTheHubRecordsWhatIsPublished(): void
    {
        $this->browser->disableReboot();
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, new NullLogger()));
    }

    /**
     * @param array<string, string> $payload
     */
    private function record(array $payload): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::OwnerAsked, json_encode($payload, JSON_THROW_ON_ERROR)))->succeeded());
    }

    /**
     * @param array<string, string> $payload
     */
    private function recordAndAnnounce(array $payload): void
    {
        $this->announce(new LedgerEvent(LedgerEventKind::OwnerAsked, json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    // Announced by hand, because the page tests read a ledger in memory, which announces nothing, unlike the application's ledger after a commit.
    private function announce(LedgerEvent $event): void
    {
        self::assertTrue($this->ledger->append($event)->succeeded());
        $recorded = array_last(iterator_to_array($this->ledger->readInOrder()));
        self::assertInstanceOf(RecordedEvent::class, $recorded);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new LedgerEventAppended($recorded));
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests; like Turbo, a stream whose target is absent changes nothing.
    private function applied(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $target = $page->filter('#'.$stream->attr('target'))->getNode(0);
            if (!$target instanceof DOMNode) {
                continue;
            }

            if ('remove' === $stream->attr('action')) {
                $target->parentNode?->removeChild($target);

                continue;
            }

            self::assertSame('prepend', $stream->attr('action'));
            $document = $target->ownerDocument;
            self::assertNotNull($document);
            // Parsed inside a template, because only there a table row keeps its cells.
            $wrapper = new Crawler('<template>'.$stream->filter('template')->html().'</template>')->filter('template')->getNode(0);
            self::assertNotNull($wrapper);
            foreach (array_reverse(iterator_to_array($wrapper->childNodes)) as $child) {
                $target->insertBefore($document->importNode($child, true), $target->firstChild);
            }
        }

        return $page;
    }
}
