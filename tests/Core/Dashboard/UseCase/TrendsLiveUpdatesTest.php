<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DOMNode;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\TrendsReading;
use Intakt\Core\Dashboard\Domain\UpNext;
use Intakt\Core\Dashboard\Infrastructure\LedgerTrends;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\LiveTrends;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\TrendsRoundUpdates;
use Intakt\Core\Dashboard\UseCase\ShowTrends\ShowTrendsController;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TrendsRoundUpdates::class)]
#[CoversClass(LiveTrends::class)]
#[CoversClass(ShowTrendsController::class)]
#[UsesClass(LedgerTrends::class)]
#[UsesClass(TrendsReading::class)]
#[UsesClass(ReviewTrends::class)]
#[UsesClass(UpNext::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
final class TrendsLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private const string DEMO_ROUND = '0199a5b4-0000-7000-8000-000000000002';

    private ?string $secretBefore = null;

    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testAJudgedRoundPushedToAnOpenPageShowsTheFiguresOfAPageLoadedAfterIt(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->browser->request('GET', '/trends');
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        $pushed = $this->pushedTemplateOf('trends-with-demo');
        self::assertSame($this->browser->request('GET', '/trends')->filter('#trends-with-demo')->outerHtml(), trim($pushed->html()));
    }

    public function testTheFirstRoundPushedToAFreshPageReplacesItsEmptyStateWithTheTiles(): void
    {
        $page = $this->browser->request('GET', '/trends');
        self::assertCount(1, $page->filter('#trends-with-demo .empty'));
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $updated = $this->replaced($page, $this->lastShellUpdate());
        self::assertCount(0, $updated->filter('.empty'));
        self::assertSame('1', $updated->filter('#trends-with-demo .tile[data-tile="passes"] dd')->first()->text());
    }

    public function testAReviewerStartedMovesItsRoundToInProgressOnThePushedUpNext(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $page = $this->browser->request('GET', '/trends');
        self::assertSame(['Waiting to start'], $page->filter('#up-next h3')->each(static fn (Crawler $title): string => $title->text()));
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        $updated = $this->replaced($page, $this->lastShellUpdate());
        self::assertSame(['In progress'], $updated->filter('#up-next h3')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame($this->browser->request('GET', '/trends')->filter('#up-next')->outerHtml(), $updated->filter('#up-next')->outerHtml());
    }

    public function testATabLeavingOutTheDemoGetsTheFiguresOfAPageLoadedWithTheDemoLeftOut(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startRoundOf('demo-change', self::DEMO_ROUND, 1);
        $this->storeRoundAs(self::DEMO_ROUND, ReplayMark::Replay);
        $page = $this->browser->request('GET', '/trends?demo=0');

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $updated = $this->replaced($page, $this->lastShellUpdate());
        self::assertSame('1', $updated->filter('#trends-without-demo .tile[data-tile="passes"] dd')->first()->text());
        $loaded = $this->browser->request('GET', '/trends?demo=0');
        self::assertSame([$loaded->filter('#trends-without-demo')->outerHtml(), $loaded->filter('#up-next')->outerHtml()], [$updated->filter('#trends-without-demo')->outerHtml(), $updated->filter('#up-next')->outerHtml()]);
    }

    public function testEveryTargetOfThePushIsOnTheTrendsPageAndNoneOnTheBoard(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $trends = $this->browser->request('GET', '/trends');
        $board = $this->browser->request('GET', '/');

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        $targets = new Crawler($this->lastShellUpdate())->filter('turbo-stream')->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));
        self::assertSame(['trends-with-demo', 'trends-without-demo', 'up-next'], array_values(array_filter($targets, static fn (string $target): bool => str_starts_with($target, 'trends-') || 'up-next' === $target)));
        self::assertSame([1, 0], [$trends->filter('#trends-with-demo')->count() + $trends->filter('#trends-without-demo')->count(), $board->filter('#trends-with-demo, #trends-without-demo, #up-next')->count()]);
        self::assertCount(1, $trends->filter('#up-next'));
    }

    public function testAToolCallATextAndASummaryReachTheRoundButPushNoTrends(): void
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $inner = new RecordedRoundUpdates();
        $trends = self::getContainer()->get(LiveTrends::class);
        self::assertInstanceOf(LiveTrends::class, $trends);
        $round = Uuid::fromString(self::ROUND);
        $updates = new TrendsRoundUpdates($inner, $trends);

        $updates->toolCalled($round, 'correctness', 'read_file');
        $updates->textStreamed($round, 'correctness', 'Reading the diff.');
        $updates->summaryChanged($round);

        self::assertSame(['tool correctness read_file', 'text correctness Reading the diff.', 'summary '.self::ROUND], $inner->seen);
        self::assertSame([], $this->published);
    }

    public function testATrendsPageListensToTheShellTopicTheTrendsArePushedOn(): void
    {
        $source = $this->browser->request('GET', '/trends')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
    }

    #[Override]
    protected function setUp(): void
    {
        $before = $_SERVER['MERCURE_JWT_SECRET'] ?? null;
        $this->secretBefore = is_string($before) ? $before : null;
        // A fresh random key, because a committed one would be a secret; the page only signs a listener's token with it.
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = bin2hex(random_bytes(16));
        parent::setUp();
        // One kernel for the whole test, because the page, the pushed update and the page loaded again read the same ledger.
        $this->browser->disableReboot();
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, new NullLogger()));
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

    private function roundUpdates(): RoundUpdates
    {
        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);

        return $updates;
    }

    // The last shell update that carries Trends, because the board's push goes out on the same topic.
    private function lastShellUpdate(): string
    {
        $trends = array_filter($this->published, static fn (Update $update): bool => [DashboardTopics::SHELL] === $update->getTopics() && str_contains($update->getData(), 'target="up-next"'));
        $last = array_last($trends);
        self::assertNotNull($last, 'No Trends update was published on the shell topic.');

        return $last->getData();
    }

    private function pushedTemplateOf(string $target): Crawler
    {
        $template = new Crawler($this->lastShellUpdate())->filter('turbo-stream[target="'.$target.'"] template');
        self::assertCount(1, $template);

        return $template;
    }

    // Like Turbo's replace, a morph too, done by hand on the parsed page, because no browser runs Turbo in the tests.
    private function replaced(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream[action="replace"]')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $template = $stream->filter('template')->getNode(0);
            self::assertNotNull($template);

            foreach ($page->filter('[id="'.$stream->attr('target').'"]')->each(static fn (Crawler $old): ?DOMNode => $old->getNode(0)) as $target) {
                self::assertNotNull($target);
                $parent = $target->parentNode;
                $document = $target->ownerDocument;
                self::assertNotNull($parent);
                self::assertNotNull($document);

                foreach ($template->childNodes as $child) {
                    $parent->insertBefore($document->importNode($child, true), $target);
                }

                $parent->removeChild($target);
            }
        }

        return $page;
    }
}
