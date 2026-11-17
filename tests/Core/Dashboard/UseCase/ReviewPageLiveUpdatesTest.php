<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Character;
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
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\ReadFinding;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\ToolCallCount;
use Intakt\Core\Review\Contract\Verdict;
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
use Symfony\Component\Uid\Uuid;
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
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReadFinding::class)]
#[UsesClass(ReadAnswer::class)]
#[UsesClass(Character::class)]
final class ReviewPageLiveUpdatesTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    private const string ASKED_AGAIN = 'Asked again: any text above is from a try that gave no usable answer.';

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
            .$this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true, ['round-1-correctness' => FinishedState::Stopped])]);

        foreach (new Crawler($streams)->filter('turbo-stream[action="replace"]')->each(static fn (Crawler $stream): string => $stream->attr('targets') ?? '#'.$stream->attr('target')) as $selector) {
            self::assertCount(1, $page->filter($selector), $selector);
        }
    }

    public function testAStopPushedToThePageMarksTheReviewerStoppedInTheCurrentStateBlock(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('reviewer_stopped', ['round' => 'round-1', 'reviewer' => 'round-1-correctness']));

        self::assertSame('stopped', $updated->filter('#round-round-1-reviewer-round-1-correctness-state')->text());
        self::assertSame('correctness (standard tier, model not recorded): stopped', $updated->filter('.state-block .sb-list li')->text());
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
        self::assertSame('answered', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
        self::assertSame('answered', $updated->filter('#round-round-1-reviewer-round-1-correctness-state')->text());
        self::assertCount(0, $updated->filter('#round-round-1-reviewer-round-1-correctness-stop form'));
    }

    public function testAStoppedReviewerStaysStoppedWhenTheRoundIsJudged(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('reviewer_stopped', ['round' => 'round-1', 'reviewer' => 'round-1-correctness'])
            .$this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true, ['round-1-correctness' => FinishedState::Stopped])]));

        self::assertSame('stopped', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
        self::assertSame('Now: round 1 judged', $updated->filter('#state-block-title')->text());
    }

    public function testAJudgedRoundPushedToThePageTurnsTheCurrentStateBlockToJudged(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true)]));

        self::assertSame('Now: round 1 judged', $updated->filter('#state-block-title')->text());
        self::assertStringStartsWith('The judge has judged this round.', $updated->filter('#round-round-1-now-judge')->text());
    }

    public function testAJudgedRoundPushedToThePageShowsTheRoundHeadAsJudged(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');
        self::assertCount(1, $page->filter('#round-round-1-head-state .muted.state-running'));

        $updated = $this->replaced($page, $this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true)]));

        self::assertSame('Judged', $updated->filter('#round-round-1-head-state .muted.state-judged')->text());
        self::assertCount(0, $updated->filter('#round-round-1-head-state .chip'));
        self::assertCount(0, $updated->filter('.round-head .state-running'));
        self::assertSame('judged', $updated->filter('#round-round-1-now-phase')->text());
        self::assertSame('Judged.', $updated->filter('#round-round-1-state')->text());
    }

    public function testAJudgedRoundPushedToThePageTakesAwayTheStopOfTheRound(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');
        self::assertSame('Stop', $page->filter('#round-round-1-now-stop form.stop-round button')->text());

        $updated = $this->replaced($page, $this->stream('reviewer_stopped', ['round' => 'round-1', 'reviewer' => 'round-1-correctness'])
            .$this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 1, true, ['round-1-correctness' => FinishedState::Stopped])]));

        self::assertCount(1, $updated->filter('#round-round-1-now-stop'));
        self::assertCount(0, $updated->filter('form.stop-round'));
        self::assertSame('stopped', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
    }

    public function testTheStopOfTheRoundStaysWhileAReviewerIsStillWorking(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Stopped])]));

        self::assertCount(1, $updated->filter('form.stop-round'));
    }

    // The judge still works, so the round is in review and Stop stays the one action (decision D4 of #2369).
    public function testTheStopOfTheRoundStaysOnceEveryReviewerFinishedUntilTheRoundIsJudged(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, false, ['round-1-correctness' => FinishedState::Stopped])]));

        self::assertCount(1, $updated->filter('#round-round-1-now-stop > form.stop-round'));
    }

    // The judge has not called yet, so the round still runs and the next one cannot start.
    public function testTheStartOfTheNextRoundStaysHiddenOnceEveryReviewerFinishedButTheRoundIsNotJudged(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $page = $this->browser->request('GET', '/reviews/round-1');
        self::assertCount(1, $page->filter('.task-actions:has(.now-runs) > form.start-review'));

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 1, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertCount(1, $updated->filter('form.stop-round'));
        self::assertCount(1, $updated->filter('.task-actions:has(.now-runs) > form.start-review'));
    }

    public function testAJudgedRoundPushedToThePageOffersTheStartOfTheNextRound(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(1, 1, true)]));

        self::assertCount(0, $updated->filter('.now-runs'));
        self::assertCount(0, $updated->filter('form.stop-round'));
        self::assertSame('Start round 2', trim($updated->filter('.task-actions > form.start-review button')->text()));
    }

    public function testAPageOpenedBeforeTheRoundIsJudgedShowsTheJudgesCallAndTheFindingsOnceTheRoundIsJudged(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);
        $this->ledger->startReviewer($id, 'security');
        $page = $this->browser->request('GET', '/reviews/'.$id);
        self::assertSame('The judge has not judged this round yet.', $page->filter('#round-'.$id.'-judged')->text());

        $this->ledger->answer($id, 'security', 2);
        $this->ledger->judgeFindings($id, ['F1' => 'act', 'F2' => 'defer']);
        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 1, true));

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        self::assertSame(
            [['F1', 'P2', 'Text of F1.', 'Fix now'], ['F2', 'P2', 'Text of F2.', 'Dropped: deferred to a follow-up issue']],
            $updated->filter('#round-'.$id.'-judged li.finding-card')->each(static fn (Crawler $card): array => [$card->filter('.fc-id')->text(), $card->filter('.sev')->text(), $card->filter('.fc-text')->text(), $card->filter('.fc-call')->text()]),
        );
        self::assertSame('The judge has judged this round. Its call and the findings to fix are in the round below.', $updated->filter('#round-'.$id.'-now-judge')->text());
    }

    public function testAPageOpenedBeforeTheRoundIsJudgedShowsTheActListTheVerdictAndTheRoundStateOnceTheRoundIsJudged(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOfQuorum('feat/thing', $id, 'all');
        $this->ledger->startReviewer($id, 'security');
        $this->ledger->startReviewer($id, 'correctness');
        $page = $this->browser->request('GET', '/reviews/'.$id);
        self::assertSame(['No finding is open.', '', '0 of 2 reviewers finished.'], [$page->filter('#round-'.$id.'-now-findings')->text(), $page->filter('#round-'.$id.'-verdict')->text(), $page->filter('#round-'.$id.'-state')->text()]);

        $this->ledger->answer($id, 'security', 2);
        $this->ledger->answer($id, 'correctness', 0);
        $this->ledger->judgeFindings($id, ['F1' => 'act', 'F2' => 'defer']);
        $this->publishedUpdates()->roundChanged($round, new RoundProgress(2, 2, true));

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        self::assertSame(
            [['F1', 'Fix now'], ['F2', 'Dropped: deferred to a follow-up issue']],
            $updated->filter('#round-'.$id.'-judged li.finding-card')->each(static fn (Crawler $card): array => [$card->filter('.fc-id')->text(), $card->filter('.fc-call')->text()]),
        );
        self::assertSame(['#round-'.$id.'-finding-F1'], $updated->filter('#round-'.$id.'-now-findings a.sb-finding')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame('P2F1 Text of F1.', $updated->filter('#round-'.$id.'-now-findings a.sb-finding')->text());
        self::assertSame('Outcome: Changes requested, decided by the quorum policy all over all 2 reviewers.', $updated->filter('#round-'.$id.'-outcome')->text());
        self::assertSame('Judged.', $updated->filter('#round-'.$id.'-state')->text());
        self::assertSame('Changes requested', $updated->filter('#round-'.$id.'-head-state .chip')->text());
        $reloaded = $this->browser->request('GET', '/reviews/'.$id);
        self::assertSame($reloaded->filter('#round-'.$id.'-verdict')->text(), $updated->filter('#round-'.$id.'-verdict')->text());
    }

    public function testAPageOpenedBeforeTheRoundIsApprovedShowsTheBowingOctopusAsAReloadDoesOnceItIsApproved(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOfQuorum('feat/thing', $id, 'all');
        $page = $this->browser->request('GET', '/reviews/'.$id);
        self::assertCount(0, $page->filter('img[src*="octopus"]'));

        $this->ledger->answer($id, 'security', 0);
        $this->ledger->answer($id, 'correctness', 0);
        $this->ledger->judgeRound($id);
        $this->publishedUpdates()->roundChanged($round, new RoundProgress(2, 2, true));

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        $reloaded = $this->browser->request('GET', '/reviews/'.$id);
        self::assertCount(2, $updated->filter('#round-'.$id.'-verdict img[src*="octopus-bowing"]'));
        self::assertSame($reloaded->filter('#round-'.$id.'-verdict')->html(), $updated->filter('#round-'.$id.'-verdict')->html());
    }

    public function testAPageOpenedBeforeTheRoundIsJudgedShowsTheRoundsTokensAndCostTableAsAReloadDoesOnceTheRoundIsJudged(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeTheJudgeSpentTokens();

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 1, true));

        $updated = $this->replaced($page, $this->publishedStreams());
        $reloaded = $this->browser->request('GET', '/reviews/'.$id);
        self::assertStringContainsString('1200', $reloaded->filter('#round-'.$id.'-tokens')->text());
        self::assertSame($reloaded->filter('#round-'.$id.'-tokens')->outerHtml(), $updated->filter('#round-'.$id.'-tokens')->outerHtml());
    }

    public function testAPageOpenedBeforeTheRoundIsJudgedShowsTheHeadersTokensAndCostTotalsAsAReloadDoesOnceTheRoundIsJudged(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeTheJudgeSpentTokens();

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 1, true));

        $updated = $this->replaced($page, $this->publishedStreams());
        $reloaded = $this->browser->request('GET', '/reviews/'.$id);
        self::assertStringStartsWith('1,200 tokens', $reloaded->filter('#review-summary-tokens')->text());
        self::assertSame($reloaded->filter('#review-summary-tokens')->outerHtml(), $updated->filter('#review-summary-tokens')->outerHtml());
        self::assertSame($reloaded->filter('#review-summary-cost')->outerHtml(), $updated->filter('#review-summary-cost')->outerHtml());
    }

    public function testAPageOpenedBeforeTheRoundIsJudgedShowsTheReviewSoFarCostAsTheReloadedHeaderOnceTheRoundIsJudged(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeTheJudgeSpentTokens();
        $before = $page->filter('#round-'.$id.'-review-cost')->text();

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 1, true));

        $updated = $this->replaced($page, $this->publishedStreams());
        $reloaded = $this->browser->request('GET', '/reviews/'.$id);
        self::assertNotSame($before, $updated->filter('#round-'.$id.'-review-cost')->text());
        self::assertSame($reloaded->filter('#review-summary-cost')->text(), $updated->filter('#round-'.$id.'-review-cost')->text());
    }

    public function testEveryTargetOfAJudgedRoundsUpdateIsOnThePageOpenedBeforeIt(): void
    {
        [$round, , $page] = $this->givenAPageLoadedBeforeTheJudgeSpentTokens();

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 1, true));

        $targets = new Crawler($this->publishedStreams())->filter('turbo-stream[action="replace"][target]')->each(static fn (Crawler $stream): string => (string) $stream->attr('target'));
        self::assertContains('review-summary-tokens', $targets);
        foreach ($targets as $target) {
            self::assertCount(1, $page->filter('#'.$target), $target);
        }
    }

    public function testAJudgedRoundWithoutAFindingToFixEmptiesTheOpenFindingsOfTheCurrentStateBlock(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);
        $this->ledger->judgeFindings($id, ['F1' => 'act']);
        $next = Uuid::v7();
        $nextId = $next->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $nextId, 2);
        $page = $this->browser->request('GET', '/reviews/'.$nextId);
        self::assertSame(['#round-'.$id.'-finding-F1'], $page->filter('#round-'.$nextId.'-now-findings a.sb-finding')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        $this->ledger->judgeFindings($nextId, ['F9' => 'reject']);
        $this->publishedUpdates()->roundChanged($next, new RoundProgress(0, 2, true));

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        self::assertSame('No finding is open.', $updated->filter('#round-'.$nextId.'-now-findings')->text());
    }

    public function testAJudgedRoundWhoseFindingsWereNotRecordedSaysSoInTheLiveUpdateAsOnLoad(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);
        $page = $this->browser->request('GET', '/reviews/'.$id);

        $this->ledger->judgeMarksOnly($id, ['F1'], []);
        $this->publishedUpdates()->roundChanged($round, new RoundProgress(0, 0, true));

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        self::assertStringStartsWith("This round's findings were not recorded", $updated->filter('#round-'.$id.'-judged p.muted')->text());
        self::assertSame(['F1'], $updated->filter('#round-'.$id.'-judged li.finding-card .fc-id')->each(static fn (Crawler $id): string => $id->text()));
    }

    public function testAJudgedRoundStreamedWithoutItsFindingsStillPointsToAReload(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true), 'reviewed' => null]));

        self::assertSame('The judge has not judged this round yet.', $updated->filter('#round-round-1-judged')->text());
        self::assertSame('The judge has judged this round. Reload the page to see its call and the findings to fix.', $updated->filter('#round-round-1-now-judge')->text());
        self::assertCount(0, $updated->filter('#round-round-1-outcome'));
    }

    public function testAToolCallPublishedForAReviewerThatStartedAfterThePageLoadedKeepsTheStopOfTheRound(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $this->ledger->startRoundOf('feat/thing', $round->toRfc4122(), 1);
        $page = $this->browser->request('GET', '/reviews/'.$round->toRfc4122());
        $this->ledger->startReviewer($round->toRfc4122(), 'security');

        $this->publishedUpdates()->toolCalled($round, $round->toRfc4122().'-security', 'read_file');

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        self::assertSame('Stop', $updated->filter('.page-head form.stop-round button')->text());
    }

    // The round waits for the judge, so its Stop stays until the judged round's update (decision D4 of #2369).
    public function testAStopPublishedThroughTheHubShowsTheReviewerStoppedAndKeepsTheStopOfTheUnjudgedRound(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);
        $this->ledger->startReviewer($id, 'security');
        $page = $this->browser->request('GET', '/reviews/'.$id);
        self::assertCount(1, $page->filter('form.stop-round'));

        $this->publishedUpdates()->reviewerStopped($round, $id.'-security');
        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 1, false, [$id.'-security' => FinishedState::Stopped]));

        self::assertSame([['review/rounds/'.$id], ['review/rounds/'.$id]], array_map(static fn (Update $update): array => $update->getTopics(), $this->published));
        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));
        self::assertSame('stopped', $updated->filter('#round-'.$id.'-now-reviewer-'.$id.'-security-state')->text());
        self::assertCount(1, $updated->filter('form.stop-round'));
    }

    public function testAReviewerThatAnswersAfterThePageLoadedShowsItsOutcomeChipNeverTheWordVerdict(): void
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);
        $this->ledger->startReviewer($id, 'correctness');
        $page = $this->browser->request('GET', '/reviews/'.$id);
        $this->ledger->answer($id, 'correctness', 2);
        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 2, false, [$id.'-correctness' => FinishedState::Verdict]));

        $updated = $this->replaced($page, implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published)));

        self::assertSame('Changes requested', $updated->filter('#round-'.$id.'-reviewer-'.$id.'-correctness-state .chip')->text());
        self::assertSame('Changes requested', $updated->filter('#round-'.$id.'-now-reviewer-'.$id.'-correctness-state .chip')->text());
        self::assertStringNotContainsString('verdict', $updated->filter('#round-'.$id.'-reviewers')->text());
    }

    public function testAReviewerThatFinishesAfterThePageLoadedWithoutItGetsARowInTheCurrentStateBlock(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame('answered', $updated->filter('#round-round-1-now-reviewer-round-1-correctness-state')->text());
        self::assertCount(0, $updated->filter('#round-round-1-now-none'));
    }

    public function testAReviewerThatFinishesAfterThePageLoadedGetsARowWithItsRoleModelOutcomeAndFindings(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');
        $this->ledger->answer($id, 'correctness', 2);

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 2, false, [$id.'-correctness' => FinishedState::Verdict]));

        $rows = $this->rowsOf($this->replaced($page, $this->publishedStreams()));
        self::assertSame([['correctness', 'Kimi K3', 'Changes requested']], array_map(static fn (array $row): array => array_slice($row, 0, 3), $rows));
        self::assertStringStartsWith('2 ', $rows[0][3] ?? '');
    }

    public function testAReviewerStoppedAfterThePageLoadedGetsARowWithItsRoleAndTheStoppedState(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewer($id, 'correctness');

        $this->publishedUpdates()->reviewerStopped($round, $id.'-correctness');

        self::assertSame([['correctness', 'standard tier, model not recorded', 'stopped', 'no answer']], $this->rowsOf($this->replaced($page, $this->publishedStreams())));
    }

    public function testAReviewerStoppedAfterItAnsweredGetsARowWithItsRecordedOutcomeAndFindings(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');
        $this->ledger->answer($id, 'correctness', 1);
        $this->ledger->stopReviewer($id, 'correctness');

        $this->publishedUpdates()->reviewerStopped($round, $id.'-correctness');

        $rows = $this->rowsOf($this->replaced($page, $this->publishedStreams()));
        self::assertSame([['correctness', 'Kimi K3', 'stopped']], array_map(static fn (array $row): array => array_slice($row, 0, 3), $rows));
        self::assertStringStartsWith('1 ', $rows[0][3] ?? '');
    }

    public function testAReviewerThatFailedAfterThePageLoadedShowsTheFailedChipInRedWithItsCauseAsMutedText(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'security', 'Kimi K3');
        $this->ledger->failReviewer($id, 'security', 'the model did not answer in time');

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 2, false, [$id.'-security' => FinishedState::Failed]));

        $state = $this->replaced($page, $this->publishedStreams())->filter('#round-'.$id.'-reviewer-'.$id.'-security-state');
        self::assertSame(['Failed', 'the model did not answer in time'], [$state->filter('.chip.broken')->text(), $state->filter('.muted')->text()]);
    }

    public function testAFailedReviewerTheTableAlreadyListsShowsItsCauseOnceItFails(): void
    {
        [$round, $id] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'security', 'Kimi K3');
        $page = $this->browser->request('GET', '/reviews/'.$id);
        $this->ledger->failReviewer($id, 'security', 'the model did not answer in time');

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 2, false, [$id.'-security' => FinishedState::Failed]));

        $rows = $this->rowsOf($this->replaced($page, $this->publishedStreams()));
        self::assertSame([['security', 'Kimi K3', 'Failed the model did not answer in time']], array_map(static fn (array $row): array => array_slice($row, 0, 3), $rows));
    }

    public function testDuringALiveRoundThePageListsEachReviewerByRoleAndModelWithItsState(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');
        $this->ledger->startReviewerOn($id, 'security', 'GLM 5');

        $this->publishedUpdates()->textStreamed($round, $id.'-correctness', 'Looking');
        $this->publishedUpdates()->toolCalled($round, $id.'-security', 'read_file');

        $updated = $this->replaced($page, $this->publishedStreams());
        self::assertSame(['correctness (Kimi K3): running', 'security (GLM 5): running'], $updated->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
        self::assertSame(['correctness (Kimi K3) live', 'security (GLM 5) live'], $updated->filter('#round-'.$id.'-live .live h3')->each(static fn (Crawler $h3): string => $h3->text()));
        self::assertStringNotContainsString($id.'-correctness (', $updated->html());
    }

    public function testAReviewerPlacedLiveFromAStartWithoutARecordedModelIsNamedByItsTierLikeTheTable(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewer($id, 'correctness');

        $this->publishedUpdates()->toolCalled($round, $id.'-correctness', 'read_file');

        $updated = $this->replaced($page, $this->publishedStreams());
        self::assertSame('correctness (standard tier, model not recorded) live', $updated->filter('#reviewer-'.$id.'-correctness h3')->text());
    }

    public function testAReviewerPlacedWhileItsStartCannotBeReadIsLeftUnnamedUntilAReload(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])])
            .$this->stream('tool_called', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'tool' => 'read_file']));

        self::assertSame([['role not read', 'model not read', 'answered', 'shown after a reload']], $this->rowsOf($updated));
        self::assertSame(['A reviewer (role not read): answered'], $updated->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
        self::assertSame('A reviewer (role not read) live', $updated->filter('#reviewer-round-1-correctness h3')->text());
    }

    public function testAReviewerThatFinishesAfterThePageLoadedFillsAsManyCellsAsATableWithoutAStopColumnHasHeaders(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-security' => FinishedState::Verdict])]));

        self::assertSame([4], $this->cellCountsOf($updated));
        self::assertCount(4, $updated->filter('table.reviewers thead th'));
    }

    public function testAReviewerThatFinishesAfterThePageLoadedFillsAsManyCellsAsATableWithAStopColumnHasHeaders(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-security' => FinishedState::Verdict])]));

        self::assertSame([5, 5], $this->cellCountsOf($updated));
        self::assertCount(5, $updated->filter('table.reviewers thead th'));
    }

    public function testAReviewerTheTableAlreadyListsKeepsItsOneRowWhenItFinishes(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame([['correctness', 'standard tier, model not recorded', 'answered', 'no answer yet', '']], $this->rowsOf($updated));
    }

    public function testAReviewerThatStartsAfterThePageLoadedGetsARunningRowWithItsRoleAndModelInTheCurrentStateBlock(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');

        $this->publishedUpdates()->textStreamed($round, $id.'-correctness', 'Looking');

        self::assertSame(['correctness (Kimi K3): running'], $this->replaced($page, $this->publishedStreams())->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
    }

    public function testAReviewerRowPlacedByAStreamShowsExactlyOneCodeReviewerHeadOf24Pixels(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');

        $this->publishedUpdates()->textStreamed($round, $id.'-correctness', 'Looking');

        $row = $this->replaced($page, $this->publishedStreams())->filter('#round-'.$id.'-now-reviewers li');
        self::assertCount(1, $row);
        self::assertSame(['', ''], $row->children('img.character')->each(static fn (Crawler $img): ?string => $img->attr('alt')));
        self::assertSame([['24', '24'], ['24', '24']], $row->children('img.character')->each(static fn (Crawler $img): array => [$img->attr('width'), $img->attr('height')]));
        self::assertCount(2, $row->filter('img[src*="code_reviewer-"]'));
    }

    public function testAReviewerRowPlacedByAStreamIsTheRowThePageRendersOnLoad(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');
        $this->ledger->answer($id, 'correctness', 1);

        $this->publishedUpdates()->roundChanged($round, new RoundProgress(1, 2, false, [$id.'-correctness' => FinishedState::Verdict]));

        $placed = $this->replaced($page, $this->publishedStreams())->filter('#round-'.$id.'-now-reviewers li')->outerHtml();
        $loaded = $this->browser->request('GET', '/reviews/'.$id)->filter('#round-'.$id.'-now-reviewers li')->outerHtml();
        self::assertSame($loaded, $placed);
    }

    public function testAReviewerRowPlacedWhileItsStartCannotBeReadKeepsItsFallbackTextAndOneHead(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('tool_called', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'tool' => 'read_file']));

        $row = $updated->filter('#round-round-1-now-reviewers li');
        self::assertSame('A reviewer (role not read): running', $row->text());
        self::assertCount(2, $row->children('img.character'));
    }

    public function testTheOwlBesideTheJudgesCallSurvivesTheJudgedUpdate(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true)]));

        self::assertCount(2, $updated->filter('#round-round-1-now-judge')->ancestors()->first()->filter('img[src*="judge-"][width="48"]'));
        self::assertStringStartsWith('The judge has judged this round.', $updated->filter('#round-round-1-now-judge')->text());
    }

    public function testALiveLogBlockPlacedByAStreamShowsExactlyOneCodeReviewerHeadOf24Pixels(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');

        $this->publishedUpdates()->textStreamed($round, $id.'-correctness', 'Looking');

        $block = $this->replaced($page, $this->publishedStreams())->filter('#round-'.$id.'-live .live');
        self::assertCount(1, $block);
        $images = $block->filter('.character-row > img.character');
        self::assertCount(2, $block->filter('img'));
        self::assertSame(['', ''], $images->each(static fn (Crawler $img): ?string => $img->attr('alt')));
        self::assertSame([['24', '24'], ['24', '24']], $images->each(static fn (Crawler $img): array => [$img->attr('width'), $img->attr('height')]));
        self::assertCount(2, $images->filter('img[src*="code_reviewer-"]'));
        self::assertSame('correctness (Kimi K3) live', $block->filter('.character-row')->text());
    }

    public function testALiveLogBlockPlacedByAStreamIsTheBlockThePageRendersOnLoad(): void
    {
        [$round, $id, $page] = $this->givenAPageLoadedBeforeAnyReviewerStarted();
        $this->ledger->startReviewerOn($id, 'correctness', 'Kimi K3');

        $this->publishedUpdates()->textStreamed($round, $id.'-correctness', 'Looking');

        $placed = $this->replaced($page, $this->publishedStreams())->filter('#reviewer-'.$id.'-correctness > .character-row')->outerHtml();
        $loaded = $this->browser->request('GET', '/reviews/'.$id)->filter('#reviewer-'.$id.'-correctness > .character-row')->outerHtml();
        self::assertSame($loaded, $placed);
    }

    public function testALiveLogBlockPlacedWhileItsStartCannotBeReadKeepsItsFallbackHeadingAndOneHead(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('tool_called', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'tool' => 'read_file']));

        $row = $updated->filter('#round-round-1-live .live .character-row');
        self::assertSame('A reviewer (role not read) live', $row->text());
        self::assertCount(2, $row->children('img.character'));
    }

    public function testTheOwlBesideTheRoundsFindingsHeadingSurvivesTheJudgedUpdate(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('round_judged', ['round' => 'round-1', 'progress' => new RoundProgress(2, 2, true)]));

        self::assertCount(2, $updated->filter('#round-round-1-judged')->previousAll()->first()->filter('img[src*="judge-"][width="24"]'));
    }

    public function testAReviewerThePageAlreadyListsKeepsItsOneRowWhenItFinishes(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => 'Looking'])
            .$this->stream('round_changed', ['round' => 'round-1', 'progress' => new RoundProgress(1, 2, false, ['round-1-correctness' => FinishedState::Verdict])]));

        self::assertSame(['correctness (standard tier, model not recorded): answered'], $updated->filter('.state-block .sb-list li')->each(static fn (Crawler $li): string => $li->text()));
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

    public function testAReviewerAskedAgainShowsWhereTheRetryStartsBetweenTheFirstTrysTextAndTheRetrysText(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');
        $askedAgain = $this->stream('reviewer_asked_again', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'try' => 'try-2']);

        $updated = $this->replaced($page, $this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => '{"verdict":"appro'])
            .$askedAgain.$askedAgain
            .$this->stream('text_streamed', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'text' => '{"verdict":"changes"}']));

        $text = $updated->filter('#reviewer-round-1-correctness-text');
        self::assertCount(1, $text->filter('.asked-again'));
        self::assertSame('{"verdict":"appro'.self::ASKED_AGAIN.'{"verdict":"changes"}', $text->text(normalizeWhitespace: false));
    }

    public function testAReadAnswerReachesTheReviewPageAsItsTypedAnswerAfterTheRetryMarkerAndNeverAsJson(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');
        $answer = ReadAnswer::of(new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'src/Cart.php', 12, null, 'Boundary', FixableBy::Builder, false, null, null)], [], []));

        $updated = $this->replaced($page, $this->stream('reviewer_asked_again', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'try' => 'try-2'])
            .$this->stream('answer_read', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'answer' => $answer]));

        $text = $updated->filter('#reviewer-round-1-correctness-text');
        self::assertCount(1, $text->filter('.asked-again'));
        self::assertSame(['Verdict: changes'], $text->filter('.answer-read')->each(static fn (Crawler $line): string => $line->text()));
        self::assertSame(['P1 src/Cart.php:12 Boundary'], $text->filter('.answer-finding')->each(static fn (Crawler $line): string => $line->text()));
        self::assertStringNotContainsString('{"', $text->text());
    }

    public function testAnUnreadableAnswerReachesTheReviewPageAsOneCouldNotBeReadLineAndNoRawText(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');

        $updated = $this->replaced($page, $this->stream('answer_read', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'answer' => ReadAnswer::unreadable()]));

        $text = $updated->filter('#reviewer-round-1-correctness-text');
        self::assertSame(['The answer could not be read.'], $text->children()->each(static fn (Crawler $line): string => $line->text()));
        self::assertCount(1, $text->filter('.answer-unreadable'));
        self::assertStringNotContainsString('{', $text->text());
    }

    public function testAFindingWithoutAFileReachesTheReviewPageAsItsSeverityAndTitleOnly(): void
    {
        $this->givenARunningRound();
        $page = $this->browser->request('GET', '/reviews/round-1');
        $answer = ReadAnswer::of(new Verdict(Decision::Changes, [new Finding('F1', Severity::P2, null, null, null, 'Missing changelog', FixableBy::Builder, false, null, null)], [], []));

        $updated = $this->replaced($page, $this->stream('answer_read', ['round' => 'round-1', 'reviewer' => 'round-1-correctness', 'answer' => $answer]));

        self::assertSame(['P2 Missing changelog'], $updated->filter('#reviewer-round-1-correctness-text .answer-finding')->each(static fn (Crawler $line): string => $line->text()));
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

        self::assertStringStartsWith('0.9000 USD estimated', $updated->filter('.ls-cost dd')->first()->text());
        self::assertSame($before, $updated->filter('.ls-cost dd')->eq(1)->text());
    }

    public function testASummaryPushedToThePageRendersFindingIdsAsHighlightButtons(): void
    {
        $this->givenARunningRoundWithTheSummary(new SessionSummary([], Cost::inUsd(0.1), [], [], [], [], false));
        $page = $this->browser->request('GET', '/reviews/round-1');
        $reported = new Finding('F7', Severity::P2, 'src/A.php', 3, null, 'Name it.', FixableBy::Builder, false, null, null);

        $updated = $this->replaced($page, $this->stream('summary_changed', ['round' => 'round-1', 'summary' => new SessionSummary([], Cost::none(), [], [], [$reported], [], false)]));

        self::assertSame('F7', $updated->filter('#round-round-1-summary-findings .ls-findings button')->attr('data-highlight-finding'));
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
        self::assertSame(['Stop', 'Stop this reviewer'], $held);
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

        // Unread unless a test gives them, as MercureRoundUpdates sends them when the ledger cannot be read.
        return $twig->render(sprintf('dashboard/stream/%s.stream.html.twig', $name), $context + ['outcome' => null, 'outcomes' => []]);
    }

    /**
     * @return array{Uuid, string, Crawler}
     */
    private function givenAPageLoadedBeforeAnyReviewerStarted(): array
    {
        $round = $this->givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);

        return [$round, $id, $this->browser->request('GET', '/reviews/'.$id)];
    }

    /**
     * @return array{Uuid, string, Crawler}
     */
    private function givenAPageLoadedBeforeTheJudgeSpentTokens(): array
    {
        $this->browser->disableReboot();
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn(new SessionSummary([], Cost::inUsd(0.1), [], [], [], [], false));
        self::getContainer()->set(SessionSummaries::class, $summaries);
        $this->givenTheHubRecordsWhatIsPublished();
        $round = Uuid::v7();
        $id = $round->toRfc4122();
        $this->ledger->startRoundOf('feat/thing', $id, 1);
        $this->ledger->startReviewer($id, 'security');
        $page = $this->browser->request('GET', '/reviews/'.$id);

        $this->ledger->answer($id, 'security', 0);
        $this->ledger->useTokens($id, 1000, 200);
        $this->ledger->judgeFindings($id, []);

        return [$round, $id, $page];
    }

    private function publishedStreams(): string
    {
        return implode('', array_map(static fn (Update $update): string => $update->getData(), $this->published));
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(Crawler $page): array
    {
        return $page->filter('table.reviewers tbody tr')->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => $td->text()));
    }

    /**
     * @return list<int>
     */
    private function cellCountsOf(Crawler $page): array
    {
        return $page->filter('table.reviewers tbody tr')->each(static fn (Crawler $tr): int => $tr->filter('td')->count());
    }

    // Done by hand on the parsed page, because no browser runs Turbo in the tests.
    private function appended(Crawler $target, string $template): Crawler
    {
        $node = $target->getNode(0);
        self::assertNotNull($node);
        $document = $node->ownerDocument;
        self::assertNotNull($document);

        // Every child node, text included, because a streamed text is a bare text node; parsed inside a template, because only there a table row keeps its cells.
        $wrapper = new Crawler('<template>'.$template.'</template>')->filter('template')->getNode(0);
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
        $pages = $container->get(LedgerReviewPages::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::assertInstanceOf(SessionSummaries::class, $summaries);
        self::assertInstanceOf(LedgerReviewPages::class, $pages);
        $container->set(RoundUpdates::class, new MercureRoundUpdates(new MercureLiveUpdates($hub, new NullLogger()), $twig, $summaries, $pages, new LockFactory(new InMemoryStore()), new NullLogger()));
    }

    // No stored summary, so the round's own streams are the only updates the hub records.
    private function givenTheHubRecordsWhatIsPublishedForARoundWithoutSummary(): Uuid
    {
        $this->browser->disableReboot();
        self::getContainer()->set(SessionSummaries::class, self::createStub(SessionSummaries::class));
        $this->givenTheHubRecordsWhatIsPublished();

        return Uuid::v7();
    }

    private function publishedUpdates(): RoundUpdates
    {
        $updates = self::getContainer()->get(RoundUpdates::class);
        self::assertInstanceOf(RoundUpdates::class, $updates);

        return $updates;
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
