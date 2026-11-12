<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\ToolCallCount;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\Infrastructure\StoredSessionSummaries;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\McpServerClient;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ProjectMode::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(FinishedState::class)]
#[UsesClass(SessionSummary::class)]
#[UsesClass(ToolCallCount::class)]
#[UsesClass(Cost::class)]
#[UsesClass(MercureRoundUpdates::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(StoredSessionSummaries::class)]
#[UsesClass(RoundToolCalls::class)]
#[UsesClass(ReviewedRepository::class)]
final class ReviewPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

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

    public function testTheReviewPageListensToThePrivateTopicOfARunningRound(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $source = $crawler->filter('turbo-mercure-stream-source');
        self::assertCount(1, $source);
        self::assertNotNull($source->attr('private'));
        self::assertStringContainsString('match=review%2Frounds%2Fround-1', (string) $source->attr('src'));
        self::assertStringContainsString('<script type="importmap"', (string) $this->browser->getResponse()->getContent());
        self::assertNotEmpty($this->browser->getResponse()->headers->getCookies());
    }

    public function testAJudgedRoundDoesNotListenBecauseNothingMoreWillBePublished(): void
    {
        $this->givenARunningRound();
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringNotContainsString('review%2Frounds', (string) $crawler->filter('turbo-mercure-stream-source')->attr('src'));
        self::assertSame('Judged.', $crawler->filter('#round-round-1-state')->text());
    }

    public function testThePageHoldsTheElementsTheStreamsReplaceAndAppendTo(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $streams = [
            $this->stream('tool_called', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'tool' => 'read_file']),
            $this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking']),
            $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false)]),
        ];

        foreach ($streams as $stream) {
            $target = new Crawler($stream)->filter('turbo-stream[action="replace"], turbo-stream[action="append"][target]')->attr('target');
            self::assertNotNull($target);
            self::assertCount(1, $page->filter('#'.$target), $target);
        }
    }

    public function testEveryTargetOfAStopAndAJudgedRoundIsOnThePageOfARunningRound(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $streams = $this->stream('reviewer_stopped', ['round' => 'round-1', 'reviewer' => 'round-1-correctness'])
            .$this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true, ['round-1-correctness' => FinishedState::Stopped])]);

        foreach (new Crawler($streams)->filter('turbo-stream[action="replace"]')->each(static fn (Crawler $stream): string => (string) $stream->attr('target')) as $target) {
            self::assertCount(1, $page->filter('#'.$target), $target);
        }
    }

    public function testAStopPushedToThePageMarksTheReviewerStoppedInTheCurrentStateBlock(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('reviewer_stopped', ['round' => 'round-1', 'reviewer' => 'round-1-correctness']));

        self::assertSame('stopped', $updated->filter('#round-round-1-reviewer-round-1-correctness-state')->text());
        self::assertSame('correctness (standard): stopped', $updated->filter('.state-block .sb-list li')->text());
    }

    public function testAVerdictPushedToThePageCountsInTheCurrentStateBlock(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false)]));

        self::assertSame('1 of 2 reviewers finished.', $updated->filter('#round-round-1-now-progress')->text());
        self::assertSame('Now: round 1 in review', $updated->filter('#state-block-title')->text());
    }

    public function testAVerdictPushedToThePageStopsShowingTheReviewerRunning(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');
        $this->ledger->answer('round-1', 'correctness', 0);

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame('1 of 2 reviewers finished.', $updated->filter('#round-round-1-now-progress')->text());
        self::assertSame('verdict', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
        self::assertSame('verdict', $updated->filter('#round-round-1-reviewer-round-1-correctness-state')->text());
        self::assertCount(0, $updated->filter('#round-round-1-reviewer-round-1-correctness-stop form'));
    }

    public function testAStoppedReviewerStaysStoppedWhenTheRoundIsJudged(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('reviewer_stopped', ['round' => 'round-1', 'reviewer' => 'round-1-correctness'])
            .$this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true, ['round-1-correctness' => FinishedState::Stopped])]));

        self::assertSame('stopped', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
        self::assertSame('Now: round 1 judged', $updated->filter('#state-block-title')->text());
    }

    public function testAJudgedRoundPushedToThePageTurnsTheCurrentStateBlockToJudged(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true)]));

        self::assertSame('Now: round 1 judged', $updated->filter('#state-block-title')->text());
        self::assertStringStartsWith('The judge has judged this round.', $updated->filter('#round-round-1-now-judge')->text());
    }

    public function testAReviewerThatFinishesAfterThePageLoadedWithoutItGetsARowInTheCurrentStateBlock(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame('verdict', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
        self::assertCount(0, $updated->filter('#round-round-1-now-none'));
    }

    public function testAReviewerThatStartsAfterThePageLoadedGetsARunningRowInTheCurrentStateBlock(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking']));

        self::assertSame(['round-1-correctness (started after this page loaded): running'], $updated->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
    }

    public function testAReviewerThePageAlreadyListsKeepsItsOneRowWhenItFinishes(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking'])
            .$this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame(['correctness (standard): verdict'], $updated->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
    }

    public function testAStreamForARoundWhoseBlockIsNotOnThePageAddsNoRow(): void
    {
        $this->givenARunningRound();
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $page = $this->browser->request('GET', '/reviews/round-2');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 1, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame(['No reviewer has started yet.'], $updated->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
    }

    public function testOneListenerAuthorizesEveryUnfinishedRoundOfThePage(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame(['dashboard/shell', 'review/rounds/round-1', 'review/rounds/round-2'], $this->subscribedTopics());
    }

    public function testAReviewerThatStartsAfterThePageLoadedGetsItsPlaceFromItsFirstUpdate(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');
        $this->ledger->startReviewer('round-1', 'correctness');

        $placed = new Crawler($this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking']))->filter('turbo-stream[targets]');

        self::assertSame('#round-round-1-live:not(:has(#reviewer-round-1-correctness-text))', $placed->attr('targets'));
        self::assertCount(0, $page->filter('#reviewer-round-1-correctness-text'));
        $updated = $this->appended($page->filter('#round-round-1-live'), $placed->filter('template')->html());
        self::assertCount(1, $updated->filter('#round-round-1-live #reviewer-round-1-correctness-text'));
        self::assertCount(1, $updated->filter('#round-round-1-live #reviewer-round-1-correctness-activity'));
    }

    public function testAReviewerThePageAlreadyShowsKeepsItsPlaceSoItsTextStays(): void
    {
        $this->givenARunningRound();

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $page->filter('#round-round-1-live #reviewer-round-1-correctness-text'));
    }

    public function testAToolCallAndTextPushedToThePageAppearInTheLiveLogBesideTheTimeline(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('tool_called', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'tool' => 'read_file'])
            .$this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking']));

        self::assertSame(['read_file'], $updated->filter('.task-grid > .log-pane #reviewer-round-1-correctness-activity li')->each(static fn (Crawler $call): string => $call->text()));
        self::assertSame('Looking', $updated->filter('.task-grid > .log-pane #reviewer-round-1-correctness-text')->text());
    }

    public function testThePageHoldsEveryPartOfTheSessionSummaryTheStreamReplaces(): void
    {
        $this->givenARunningRoundWithTheSummary(new SessionSummary([new ToolCallCount('read_file', 1)], Cost::inUsd(0.1), ['src/A.php'], [], [], [], false));
        $page = $this->browser->request('GET', '/reviews/round-1');

        $streams = $this->stream('summary_changed', ['round' => 'round-1', 'summary' => new SessionSummary([], Cost::none(), [], [], [], [], false)]);

        $targets = new Crawler($streams)->filter('turbo-stream[action="replace"]')->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));
        self::assertCount(4, $targets);
        foreach ($targets as $target) {
            self::assertCount(1, $page->filter('#'.$target), $target);
        }
    }

    // From the review server's tool call to the page, because that is the whole path a reviewer's call takes; only the hub is a stand-in and Turbo is applied by hand.
    #[Group('persistence')]
    public function testAToolCallTheReviewServerRecordsChangesTheSessionSummaryOnThePageThroughTheUpdateItPublishes(): void
    {
        $this->browser->disableReboot();
        $this->givenTheHubRecordsWhatIsPublished();
        $round = new RoundFixture(self::getContainer())->start(1)->id()->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $round, 1);
        $this->ledger->startReviewer($round, 'correctness');
        $page = $this->browser->request('GET', '/reviews/'.$round);
        self::assertSame('No file read yet.', $page->filter('#round-'.$round.'-summary-files .muted')->text());

        $this->reviewServerFor($round)->call('tools/call', ['name' => 'read_file', 'arguments' => ['path' => 'RoundFixture.php']], 3);

        self::assertCount(1, $this->published);
        self::assertSame(['review/rounds/'.$round], $this->published[0]->getTopics());
        $updated = $this->replaced($page, $this->published[0]->getData());
        self::assertSame([['read_file', '1'], ['Total', '1']], $this->pairsOf($updated->filter('#round-'.$round.'-summary-tools .ls-tools')));
        self::assertSame(['RoundFixture.php'], $updated->filter('#round-'.$round.'-summary-files .mono')->each(static fn (Crawler $file): string => $file->text()));
    }

    public function testASummaryPushedToThePageLeavesTheReviewWideCostAlone(): void
    {
        $this->givenARunningRoundWithTheSummary(new SessionSummary([], Cost::inUsd(0.1), [], [], [], [], false));
        $page = $this->browser->request('GET', '/reviews/round-1');
        $before = $page->filter('.ls-cost dd')->eq(1)->text();

        $updated = $this->replaced($page, $this->stream('summary_changed', ['round' => 'round-1', 'summary' => new SessionSummary([], Cost::inUsd(0.9), [], [], [], [], false)]));

        self::assertSame('0.9000 USD', $updated->filter('.ls-cost dd')->first()->text());
        self::assertSame($before, $updated->filter('.ls-cost dd')->eq(1)->text());
    }

    // Nothing listens at MERCURE_URL in the test environment, so this is the page of an install whose hub is stopped.
    public function testThePageRendersFullyWhileTheHubIsStopped(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        self::assertSame('0 of 2 reviewers finished.', $crawler->filter('#round-round-1-state')->text());
        self::assertSame(['correctness'], $crawler->filter('table.reviewers tbody td:first-child')->each(static fn (Crawler $td): string => $td->text()));
    }

    public function testTheConnectionControllerHoldsBackTheStopButtonAndNoButtonOfTheShell(): void
    {
        $this->givenARunningRound();
        $actions = $this->actionsTheConnectionControllerHolds();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $held = $crawler->filter('.shell[data-controller="connection"]')->filter($actions)->each(static fn (Crawler $button): string => $button->text());
        self::assertSame(['Stop this reviewer'], $held);
    }

    private function actionsTheConnectionControllerHolds(): string
    {
        $script = (string) file_get_contents(dirname(__DIR__, 4).'/assets/controllers/connection_controller.js');
        self::assertSame(1, preg_match("/const ACTIONS = '(?<selector>[^']+)';/", $script, $found));

        return $found['selector'] ?? '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stream(string $name, array $context): string
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render(sprintf('dashboard/stream/%s.stream.html.twig', $name), $context);
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests.
    private function appended(Crawler $target, string $template): Crawler
    {
        $node = $target->getNode(0);
        self::assertNotNull($node);
        $document = $node->ownerDocument;
        self::assertNotNull($document);

        // Every child node, text included, because a streamed text is a bare text node.
        $wrapper = new Crawler('<div>'.$template.'</div>')->filter('body > div')->getNode(0);
        self::assertNotNull($wrapper);
        foreach ($wrapper->childNodes as $child) {
            $node->appendChild($document->importNode($child, true));
        }

        return new Crawler($document);
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests; like Turbo, a stream whose target is absent changes nothing.
    private function replaced(Crawler $page, string $streams): Crawler
    {
        foreach (new Crawler($streams)->filter('turbo-stream')->each(static fn (Crawler $stream): Crawler => $stream) as $stream) {
            $selector = $stream->attr('targets') ?? '#'.$stream->attr('target');
            foreach ($page->filter($selector)->each(static fn (Crawler $target): Crawler => $target) as $target) {
                $this->applied($stream, $target);
            }
        }

        return $page;
    }

    private function applied(Crawler $stream, Crawler $target): void
    {
        if ('append' === $stream->attr('action')) {
            $this->appended($target, $stream->filter('template')->html());

            return;
        }

        $node = $target->getNode(0);
        self::assertNotNull($node);
        if ('remove' === $stream->attr('action')) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $replacement = $stream->filter('template')->getNode(0)?->firstChild;
        $document = $node->ownerDocument;
        self::assertNotNull($replacement);
        self::assertNotNull($document);
        $node->parentNode?->replaceChild($document->importNode($replacement, true), $node);
    }

    /**
     * @return list<string>
     */
    private function subscribedTopics(): array
    {
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ('mercure_access_token' !== $cookie->getName()) {
                continue;
            }

            $payload = explode('.', (string) $cookie->getValue())[1] ?? '';
            $claims = (string) base64_decode(strtr($payload, '-_', '+/'), true);
            preg_match_all('#(?:review/rounds|dashboard)/[\w-]+#', stripslashes($claims), $topics);

            return array_values(array_unique($topics[0]));
        }

        self::fail('The page set no subscriber cookie.');
    }

    private function givenTheHubRecordsWhatIsPublished(): void
    {
        $container = self::getContainer();
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        $twig = $container->get(Environment::class);
        $summaries = $container->get(SessionSummaries::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(SessionSummaries::class, $summaries);
        $container->set(RoundUpdates::class, new MercureRoundUpdates(new MercureLiveUpdates($hub, new NullLogger()), $twig, $summaries, new LockFactory(new InMemoryStore()), new NullLogger()));
    }

    private function reviewServerFor(string $round): McpServerClient
    {
        $credential = self::getContainer()->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);
        self::assertInstanceOf(Kernel::class, self::$kernel);
        $server = new McpServerClient(self::$kernel, '/mcp/review', $credential->issueFor($round));
        $server->initialize('2025-11-25');

        return $server;
    }

    private function givenARunningRoundWithTheSummary(SessionSummary $summary): void
    {
        $this->givenARunningRound();
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn($summary);
        self::getContainer()->set(SessionSummaries::class, $summaries);
    }

    /**
     * @return list<list<string>>
     */
    private function pairsOf(Crawler $list): array
    {
        return $list->filter('dt')->each(static fn (Crawler $term): array => [$term->text(), $term->nextAll()->first()->text()]);
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }
}
