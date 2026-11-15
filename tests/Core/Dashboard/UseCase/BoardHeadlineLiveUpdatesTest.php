<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\BoardHeadlineRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LiveBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ShowBoardController::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(BoardHeadlineRoundUpdates::class)]
#[UsesClass(LiveBoardHeadline::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundProgress::class)]
final class BoardHeadlineLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ROUND = '0199a5b4-0000-7000-8000-000000000001';

    private const string FIRST_REVIEW = 'Nothing is running yet. Start the first review.';

    private const string REVIEWING = 'The Code reviewers are reviewing change feat/a of alpha. Nothing needs you.';

    private ?string $secretBefore = null;

    /**
     * @var list<Update>
     */
    private array $published = [];

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
        $this->givenTheHubRecordsWhatIsPublished();
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

    public function testTheBoardListensToTheShellTopicWhenLiveUpdatesAreOn(): void
    {

        $source = $this->browser->request('GET', '/')->filter('turbo-mercure-stream-source');

        self::assertCount(1, $source);
        self::assertStringContainsString('match='.rawurlencode(DashboardTopics::SHELL), (string) $source->attr('src'));
    }

    public function testTheBoardsHeadlineIsTheOneElementThePushedHeadlineTargets(): void
    {
        $page = $this->browser->request('GET', '/');

        $target = $this->targetOf($this->pushedHeadlineAfterAReviewerStarted());

        self::assertCount(1, $page->filter('#'.$target));
        self::assertSame(self::FIRST_REVIEW, $page->filter('#'.$target)->text());
        self::assertSame($page->filter('.page-head .lead')->getNode(0), $page->filter('#'.$target)->getNode(0));
    }

    public function testAReviewerStartedAfterThePageLoadedChangesTheBoardsHeadlineThroughThePushedUpdate(): void
    {
        $page = $this->browser->request('GET', '/');

        $updated = $this->updated($page, $this->pushedHeadlineAfterAReviewerStarted());

        self::assertSame(self::REVIEWING, $updated->filter('.page-head .lead')->text());
        self::assertSame($this->browser->request('GET', '/')->filter('.page-head .lead')->html(), $updated->filter('.page-head .lead')->html());
    }

    public function testAJudgedRoundPushedToTheBoardTakesItsReviewersOutOfTheHeadline(): void
    {
        $page = $this->browser->request('GET', '/');
        $started = $this->pushedHeadlineAfterAReviewerStarted();
        $this->ledger->judgeRound(self::ROUND);

        $this->roundUpdates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 1, true));

        $updated = $this->updated($page, $started.$this->lastShellUpdate());
        self::assertSame('Nothing is running. Nothing needs you.', $updated->filter('#board-headline')->text());
    }

    public function testTheBoardShowsTheCurrentHeadlineWithoutAStreamSourceWhenLiveUpdatesAreOff(): void
    {
        $this->givenLiveUpdatesAreOff();
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $page = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertSame(self::REVIEWING, $page->filter('#board-headline')->text());
    }

    // Without a hub key no listener can be authorized, as when the hub is not set up; read when the kernel boots, so it takes a new one.
    private function givenLiveUpdatesAreOff(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['MERCURE_JWT_SECRET'] = $_ENV['MERCURE_JWT_SECRET'] = '';
        parent::setUp();
    }

    private function givenTheHubRecordsWhatIsPublished(): void
    {
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        self::getContainer()->set(LiveUpdates::class, new MercureLiveUpdates($hub, new NullLogger()));
    }

    private function pushedHeadlineAfterAReviewerStarted(): string
    {
        $this->ledger->startRoundOf('feat/a', self::ROUND, 1);
        $this->ledger->startReviewer(self::ROUND, 'security');

        $this->roundUpdates()->reviewerStarted(Uuid::fromString(self::ROUND), 'security');

        return $this->lastShellUpdate();
    }

    private function roundUpdates(): RoundUpdates
    {
        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);

        return $updates;
    }

    private function lastShellUpdate(): string
    {
        $shell = array_filter($this->published, static fn (Update $update): bool => [DashboardTopics::SHELL] === $update->getTopics());
        $last = array_last($shell);
        self::assertNotNull($last, 'Nothing was published on the shell topic.');

        return $last->getData();
    }

    private function targetOf(string $stream): string
    {
        $update = new Crawler($stream)->filter('turbo-stream[action="update"]');
        self::assertCount(1, $update);

        return (string) $update->attr('target');
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests; like Turbo's update, it replaces the target's children and keeps the target.
    private function updated(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream[action="update"]')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $target = $page->filter('#'.$stream->attr('target'))->getNode(0);
            $template = $stream->filter('template')->getNode(0);
            self::assertNotNull($target);
            self::assertNotNull($template);
            $document = $target->ownerDocument;
            self::assertNotNull($document);
            while (null !== $target->firstChild) {
                $target->removeChild($target->firstChild);
            }

            foreach ($template->childNodes as $child) {
                $target->appendChild($document->importNode($child, true));
            }
        }

        return $page;
    }
}
