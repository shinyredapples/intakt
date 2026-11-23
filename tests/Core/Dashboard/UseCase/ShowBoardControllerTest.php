<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\InfrastructureFigure;
use Intakt\Core\Dashboard\Domain\InfrastructureRow;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Domain\WorkingCard;
use Intakt\Core\Dashboard\Domain\WorkStep;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\SampleChangeSource;
use Intakt\Core\Dashboard\Infrastructure\WaitingCount;
use Intakt\Core\Dashboard\UseCase\ShowBoard\ShowBoardController;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use Intakt\Core\Server\Infrastructure\AdminApi;
use Intakt\Core\Server\Infrastructure\AdminApiServerStatus;
use Intakt\Core\Server\Infrastructure\PrometheusSamples;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;
use Intakt\Tests\Core\Server\RecordedAdminApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ShowBoardController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Character::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(Lane::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(WorkingCard::class)]
#[UsesClass(WorkStep::class)]
#[UsesClass(Headline::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(FailingMains::class)]
#[UsesClass(FailingMain::class)]
#[UsesClass(HeadFault::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(SampleChangeSource::class)]
#[UsesClass(WaitingCount::class)]
#[UsesClass(InfrastructureRow::class)]
#[UsesClass(InfrastructureFigure::class)]
#[UsesClass(AdminApi::class)]
#[UsesClass(AdminApiServerStatus::class)]
#[UsesClass(PrometheusSamples::class)]
#[UsesClass(ServerReading::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(ServerMetrics::class)]
#[UsesClass(EndpointDown::class)]
final class ShowBoardControllerTest extends DashboardPageTestCase
{
    /**
     * @var list<OutageNotice>
     */
    private array $notices = [];

    /**
     * @var list<list<OutageNotice>|RuntimeException>
     */
    private array $readings = [];

    public function testTheRootAddressShowsTheBoard(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Board', $page->filter('.page-head h1')->text());
    }

    public function testAFreshInstallsBoardOpensWithNothingIsRunningYet(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame('Nothing is running yet. Start the first review.', $page->filter('.page-head .lead')->text());
    }

    public function testAFreshInstallsBoardShowsTheWaitingOctopusInEachThemeAsDecoration(): void
    {
        $images = $this->browser->request('GET', '/')->filter('#board-octopus img.character');

        self::assertSame(['', ''], $images->each(static fn (Crawler $image): ?string => $image->attr('alt')));
        self::assertSame([['144', '144', null], ['144', '144', null]], $images->each(static fn (Crawler $image): array => [$image->attr('width'), $image->attr('height'), $image->attr('role')]));
        self::assertSame(1, preg_match('~octopus-waiting-light[^"]*\.webp$~', $images->eq(0)->attr('src') ?? ''));
        self::assertSame(1, preg_match('~octopus-waiting-dark[^"]*\.webp$~', $images->eq(1)->attr('src') ?? ''));
    }

    public function testAFreshInstallsBoardStacksTheWaitingOctopusAboveTheFirstDayPanelsText(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertCount(1, $page->filter('main > section.firstday#board-octopus > img.character-light:first-child + img.character-dark + div > h2'));
        self::assertCount(0, $page->filter('.page-head img.character'));
    }

    public function testAFreshInstallsBoardGivesTheThreeStepsToItsFirstTask(): void
    {
        $panel = $this->browser->request('GET', '/')->filter('#board-octopus');

        self::assertSame('Give Intakt its first task', $panel->filter('h2')->text());
        self::assertSame('board-octopus-h', $panel->attr('aria-labelledby'));
        self::assertCount(3, $panel->filter('ol > li'));
    }

    public function testAFreshInstallsBoardKeepsSetUpIntaktAsItsOnePrimaryHeadAction(): void
    {
        $head = $this->browser->request('GET', '/')->filter('.page-head');

        self::assertSame(['Set up Intakt'], $head->filter('.btn.primary')->each(static fn (Crawler $link): string => $link->text()));
    }

    public function testABoardAfterAReviewShowsNoFirstDayPanel(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        self::assertCount(0, $this->browser->request('GET', '/')->filter('.firstday'));
    }

    public function testAFreshInstallsBoardShowsOneOctopusOnly(): void
    {
        self::assertSame(2, $this->browser->request('GET', '/')->filter('img[src*="octopus-"]')->count());
    }

    public function testAFreshInstallsBoardCanBeScrolledFromTheKeyboardThoughItHasNoCards(): void
    {
        self::assertSame('0', $this->browser->request('GET', '/')->filter('.board')->attr('tabindex'));
    }

    public function testAnIdleBoardShowsTheAsleepOctopusInEachThemeAsDecoration(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $images = $this->browser->request('GET', '/')->filter('div.asleep#board-octopus:not([hidden]) > img.character');

        self::assertSame([['', '144'], ['', '144']], $images->each(static fn (Crawler $image): array => [$image->attr('alt'), $image->attr('height')]));
        self::assertSame(1, preg_match('~octopus-asleep-light[^"]*\.webp$~', $images->eq(0)->attr('src') ?? ''));
        self::assertSame(1, preg_match('~octopus-asleep-dark[^"]*\.webp$~', $images->eq(1)->attr('src') ?? ''));
    }

    public function testABoardWithARunningReviewShowsNoOctopus(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        self::assertSame(0, $this->browser->request('GET', '/')->filter('img[src*="octopus-"]')->count());
    }

    public function testABuildInFlightShowsNoOctopus(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->passBuildStep('alpha', 'task-7', 'build_job');

        self::assertSame(0, $this->browser->request('GET', '/')->filter('img[src*="octopus-"]')->count());
    }

    public function testABuildInFlightKeepsTheHeadlineFromSayingNothingIsRunning(): void
    {
        $this->ledger->passBuildStep('alpha', 'task-7', 'task');

        self::assertSame('A build is running.', $this->browser->request('GET', '/')->filter('.page-head .lead')->text());
    }

    public function testABuildInFlightKeepsTheStatusLineFromCallingTheBoardQuiet(): void
    {
        $this->ledger->passBuildStep('alpha', 'task-7', 'task');

        self::assertSame('Running smoothly · 1 build running', trim($this->browser->request('GET', '/')->filter('a.status-line')->text()));
    }

    public function testATaskWhoseLatestStepFailedWithNoCardShowsTheAsleepOctopus(): void
    {
        $this->ledger->passBuildStep('alpha', 'task-7', 'task');
        $this->ledger->failBuildStep('task-7', 'mirror', 'The mirror could not fetch.');

        self::assertSame(2, $this->browser->request('GET', '/')->filter('#board-octopus img[src*="octopus-asleep-"]')->count());
    }

    public function testATaskWhosePushPassedWithNoCardShowsTheAsleepOctopus(): void
    {
        $this->ledger->passBuildStep('alpha', 'task-7', 'pre_push_check');
        $this->ledger->passBuildStep('alpha', 'task-7', 'push');

        self::assertSame(2, $this->browser->request('GET', '/')->filter('#board-octopus img[src*="octopus-asleep-"]')->count());
    }

    public function testAnInstallWithRecordedBuildWorkIsPastItsFirstDay(): void
    {
        $this->ledger->failBuildStep('task-7', 'task', 'The task could not be read.');

        $page = $this->browser->request('GET', '/');

        self::assertSame([0, 0], [$page->filter('.firstday')->count(), $page->filter('#board-setup:not([hidden])')->count()]);
        self::assertSame('Nothing is running.', $page->filter('.page-head .lead')->text());
        self::assertSame(2, $page->filter('#board-octopus img[src*="octopus-asleep-"]')->count());
    }

    public function testAnIdleProjectBesideABusyOneCarriesTheAsleepOctopusHiddenForTheProjectFilter(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRound('round-2', 'beta');
        $this->ledger->startRound('round-3', 'gamma');
        $this->ledger->judgeRound('round-3');
        $this->ledger->passBuildStep('gamma', 'task-7', 'clone');

        $octopus = $this->browser->request('GET', '/')->filter('div.asleep#board-octopus[hidden]');

        self::assertSame('["alpha"]', $octopus->attr('data-idle-projects'));
        self::assertSame(2, $octopus->filter('img[src*="octopus-asleep-"]')->count());
    }

    public function testAProjectWithOnlyFinishedBuildWorkCanBeChosenInTheFilterWhileAnotherProjectWorks(): void
    {
        $this->ledger->passBuildStep('build-only', '42', 'push');
        $this->ledger->startRound('round-1', 'reviewing');

        $page = $this->browser->request('GET', '/');

        self::assertSame('["build-only"]', $page->filter('#board-octopus')->attr('data-idle-projects'));
        self::assertCount(1, $page->filter('#projfilter option[value="build-only"]'));
    }

    public function testADemoInstallWithoutAReviewOpensOnTheFirstRunPage(): void
    {
        $this->onADemoInstall();

        $this->browser->request('GET', '/');

        self::assertResponseRedirects('/setup', Response::HTTP_SEE_OTHER);
    }

    public function testADemoInstallWithAReviewOpensOnTheBoard(): void
    {
        $this->onADemoInstall();
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('Board', $page->filter('.page-head h1')->text());
    }

    public function testABoardDuringAReviewOpensWithTheCodeReviewersAndTheirChange(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');

        $lead = $this->browser->request('GET', '/')->filter('.page-head .lead');

        self::assertSame('The Code reviewers are reviewing change 42 of alpha. The Code reviewers are reviewing 1 change.', $lead->text());
        self::assertSame(['/reviews/round-1'], $lead->filter('a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    public function testACardOfAProjectFlaggedAsReplayShowsTheReplayMarkOnTheBoard(): void
    {
        $this->flagAsReplay('alpha');
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $card = $this->browser->request('GET', '/')->filter('article[data-project="alpha"]');

        self::assertSame(['Replay'], $card->filter('.card .top .replay-mark')->each(static fn (Crawler $mark): string => $mark->text()));
    }

    public function testACardOfALiveProjectShowsNoReplayMarkOnTheBoard(): void
    {
        $this->flagAsReplay('beta');
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $card = $this->browser->request('GET', '/')->filter('article[data-project="alpha"]');

        self::assertSame([1, 0], [$card->count(), $card->filter('.replay-mark')->count()]);
    }

    public function testTheCardOfAChangeWhoseLatestRoundIsAReplayAfterALiveRoundShowsTheReplayMark(): void
    {
        $this->flagAsReplay('alpha');
        $this->storeRoundAs('round-1', ReplayMark::Live);
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->storeRoundAs('round-2', ReplayMark::Replay);
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $card = $this->browser->request('GET', '/')->filter('article[data-project="alpha"]');

        self::assertSame([1, ['Replay']], [$card->count(), $card->filter('.card .top .replay-mark')->each(static fn (Crawler $mark): string => $mark->text())]);
    }

    public function testTheCardOfAChangeWhoseLatestRoundIsLiveAfterAReplayRoundShowsNoReplayMark(): void
    {
        $this->storeRoundAs('round-1', ReplayMark::Replay);
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->storeRoundAs('round-2', ReplayMark::Live);
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $card = $this->browser->request('GET', '/')->filter('article[data-project="alpha"]');

        self::assertSame([1, 0], [$card->count(), $card->filter('.replay-mark')->count()]);
    }

    public function testABoardAfterAJudgedReviewSaysNothingIsRunning(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/');

        self::assertSame('Nothing is running.', $page->filter('.page-head .lead')->text());
    }

    public function testMarkupInAProjectNameIsShownAsTextInTheHeadline(): void
    {
        $this->ledger->startRoundOf('<b>42</b>', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $lead = $this->browser->request('GET', '/')->filter('.page-head .lead');

        self::assertStringContainsString('change <b>42</b> of alpha', $lead->text());
        self::assertCount(0, $lead->filter('b'));
    }

    public function testAFreshInstallsBoardShowsWaitingOnYouInReviewAndDoneInTheOrderOfTheDesign(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame(
            ['Waiting on you', 'In review', 'Done'],
            $page->filter('.board > section.col .col-head h3')->each(static fn (Crawler $name): string => $name->text()),
        );
    }

    public function testAFreshInstallsBoardNamesTheEmptyColumnsOfFeaturesNotRunningYetInOneComingNextLine(): void
    {
        $line = $this->browser->request('GET', '/')->filter('p.coming-next');

        self::assertCount(1, $line);
        self::assertSame('Coming next Backlog, Ready, Planned, Building, Checking and Merging show here once a task is in one of them', $line->text());
        self::assertSame(['Coming next'], $line->filter('.chip')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    // A label and no control, because the line has nothing to open (#3154).
    public function testTheComingNextLineIsTextWithoutAButtonOrLink(): void
    {
        $line = $this->browser->request('GET', '/')->filter('p.coming-next');

        self::assertCount(0, $line->filter('button, a, [tabindex]'));
    }

    public function testABoardWithReviewsKeepsTheEmptyColumnsOfFeaturesNotRunningYetInTheComingNextLine(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('43', 'round-2', 1);

        $page = $this->browser->request('GET', '/');

        self::assertCount(0, $page->filter('#col-backlog, #col-ready, #col-planned, #col-building, #col-checking, #col-merging'));
        self::assertCount(1, $page->filter('p.coming-next'));
    }

    public function testAReviewWhoseLatestRoundRunsIsACardInInReview(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['#42'], $this->cardNumbersIn($page, 'In review'));
        self::assertSame([], $this->cardNumbersIn($page, 'Done'));
    }

    public function testAReviewWhoseLatestRoundIsJudgedIsACardInDone(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/');

        self::assertSame(['#42'], $this->cardNumbersIn($page, 'Done'));
        self::assertSame([], $this->cardNumbersIn($page, 'In review'));
    }

    public function testAChangeReviewedInSeveralRoundsIsOneCard(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['#42'], $this->cardNumbersIn($page, 'In review'));
        self::assertSame([], $this->cardNumbersIn($page, 'Done'));
    }

    public function testOnceAReviewHasRunEveryColumnWithoutACardStillSaysWhatLandsThere(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        $empty = [BoardColumn::WaitingOnYou, BoardColumn::Done];
        self::assertSame(
            array_map(static fn (BoardColumn $column): string => $column->value.': '.$column->emptyLine(), $empty),
            $page->filter('.board > section.col')->reduce(static fn (Crawler $column): bool => 1 === $column->filter('.col-empty')->count())->each(static fn (Crawler $column): string => $column->filter('h3')->text().': '.$column->filter('.col-empty')->text()),
        );
    }

    public function testAColumnWithACardHasNoEmptyLine(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        self::assertCount(0, $this->column($this->browser->request('GET', '/'), 'In review')->filter('.col-empty'));
    }

    public function testAnEmptyWaitingOnYouColumnSaysWhatOnlyYouCanDecideAppearsThere(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $column = $this->column($this->browser->request('GET', '/'), 'Waiting on you');

        self::assertSame('What only you can decide appears here when Intakt asks you', $column->filter('.col-empty')->text());
    }

    public function testOnlyTheFirstColumnIsPinnedAsWaitingOnYou(): void
    {
        $pinned = $this->browser->request('GET', '/')->filter('.board > section.col.col-needs');

        self::assertCount(1, $pinned);
        self::assertSame('Waiting on you', $pinned->filter('.col-head h3')->text());
        self::assertSame('col-waiting-on-you', $this->browser->getCrawler()->filter('.board > section.col')->first()->attr('id'));
    }

    public function testAnEmptyWaitingOnYouColumnCountsZeroWithoutAmber(): void
    {
        $count = $this->column($this->browser->request('GET', '/'), 'Waiting on you')->filter('.col-head .count');

        self::assertSame('0', $count->text());
        self::assertStringNotContainsString('needs', (string) $count->attr('class'));
    }

    // Backlog folds too once it holds a task, but it holds none yet, so it is in the Coming next line instead of on the board.
    public function testOnlyDoneStartsCollapsedWhileAReviewRuns(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['col-done'], $page->filter('.board > section.col.collapsed')->each(static fn (Crawler $column): string => (string) $column->attr('id')));
        self::assertCount(1, $page->filter('.board .col-fold'));
        self::assertCount(1, $page->filter('.board .col-hide'));
    }

    public function testTheFinishedReviewsCardIsVisibleOnArrivalWhenNothingElseIsOnTheBoard(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/');

        $done = $this->column($page, 'Done');
        self::assertStringNotContainsString('collapsed', (string) $done->attr('class'));
        self::assertSame('/reviews/round-1', $done->filter('.col-cards a.card')->attr('href'));
        self::assertNotNull($page->filter('.board-wrap')->attr('data-scroll-to-end'));
    }

    // The layout puts this tab's own fold back on a pushed column, so an open Done still needs the strip that opens it again.
    public function testADoneColumnThatStartsOpenKeepsItsStripSayingItIsExpandedAndItsHide(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $done = $this->column($this->browser->request('GET', '/'), 'Done');

        self::assertSame('true', $done->filter('button.col-fold')->attr('aria-expanded'));
        self::assertCount(1, $done->filter('.col-head button.col-hide'));
    }

    public function testABoardWithARunningReviewStartsAtItsFirstColumn(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('43', 'round-2', 1);

        self::assertNull($this->browser->request('GET', '/')->filter('.board-wrap')->attr('data-scroll-to-end'));
    }

    public function testACollapsedColumnsStripShowsItsNameAndCountSideBySide(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('43', 'round-2', 1);

        $strip = $this->column($this->browser->request('GET', '/'), 'Done')->filter('button.col-fold');

        self::assertSame(['Show Done', '1', 'task'], $strip->children()->each(static fn (Crawler $part): string => $part->text()));
        self::assertSame('count', $strip->filter('.count')->attr('class'));
    }

    // A speech user says the words they see, so the name holds them instead of an aria-label that would replace them.
    public function testACollapsedColumnsStripIsNamedByItsVisibleWordsBetweenHiddenOnes(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('43', 'round-2', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame('Show Done1 task', $this->column($page, 'Done')->filter('button.col-fold')->text());
        self::assertSame(['Show ', ' task'], $this->column($page, 'Done')->filter('button.col-fold .sr-only')->each(static fn (Crawler $hidden): string => $hidden->text(normalizeWhitespace: false)));
        self::assertNull($this->column($page, 'Done')->filter('button.col-fold')->attr('aria-label'));
    }

    public function testACollapsedColumnsStripSaysItIsCollapsedAndControlsTheColumnsCards(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $column = $this->column($this->browser->request('GET', '/'), 'Done');
        $strip = $column->filter('button.col-fold');

        self::assertSame(['false', 'cards-done'], [$strip->attr('aria-expanded'), $strip->attr('aria-controls')]);
        self::assertSame('cards-done', $column->filter('.col-cards')->attr('id'));
    }

    public function testACollapsedColumnOffersHideInItsHeadNamingTheColumn(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $hide = $this->column($this->browser->request('GET', '/'), 'Done')->filter('.col-head button.col-hide');

        self::assertSame(['Hide', 'Hide Done', 'button'], [$hide->text(), $hide->attr('aria-label'), $hide->attr('type')]);
    }

    public function testAnOpenColumnHasNoStripAndNoHide(): void
    {
        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertStringNotContainsString('collapsed', (string) $column->attr('class'));
        self::assertCount(0, $column->filter('.col-fold, .col-hide'));
    }

    public function testEachColumnCountsEveryCardOfEveryProject(): void
    {
        $this->ledger->startRound('round-alpha', 'alpha');
        $this->ledger->startRound('round-beta', 'beta');

        $page = $this->browser->request('GET', '/');

        self::assertSame('2', $this->column($page, 'In review')->filter('.col-head .count')->text());
        self::assertSame('0', $this->column($page, 'Done')->filter('.col-head .count')->text());
    }

    public function testACardCarriesItsProjectForTheFilterAndOnTheCard(): void
    {
        $this->ledger->startRound('round-1', 'beta');

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('[data-project]');

        self::assertSame('beta', $card->attr('data-project'));
        self::assertSame('beta', $card->filter('.card .pname')->text());
    }

    public function testACardLinksToTheReviewOfItsLatestRound(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('a.card');

        self::assertSame('/reviews/round-2', $card->attr('href'));
        self::assertSame('Review of 42', $card->filter('.title')->text());
    }

    public function testACardShowsItsRoundItsReviewersAndItsCost(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');
        $this->ledger->startRoundOf('42', 'round-2', 2);

        $facts = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card .meta > span');

        self::assertCount(3, $facts);
        self::assertSame('Round 2', $this->wordsWithoutExplanationOf($facts->eq(0)->filter('.term')));
        self::assertSame(['2 reviewers', 'Cost '.Cost::none()->reading()], [$facts->eq(1)->text(), $facts->eq(2)->text()]);
    }

    public function testACardOfARoundThatAskedForChangesLeadsWithTheChangesRequestedChipAndItsIconAboveTheTitle(): void
    {
        $this->ledger->startRoundOfQuorum('42', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 2);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $outcome = $this->column($this->browser->request('GET', '/'), 'Done')->filter('.card .top + .outcome');

        self::assertSame('Changes requested', $outcome->filter('.chip')->text());
        self::assertCount(1, $outcome->filter('.chip svg.i'));
        self::assertCount(1, $outcome->nextAll()->filter('.title'));
    }

    public function testACardOfARoundStillInReviewShowsNoOutcome(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card');

        self::assertCount(0, $card->filter('.outcome'));
    }

    public function testTheRoundOnACardExplainsTheWord(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $term = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card .meta .term');

        self::assertSame('round', $term->attr('data-term'));
        self::assertSame('One build, its checks and its review. Sending work back starts the next round.', $term->filter('.term-tip')->text());
    }

    public function testACardOfARoundStartedBeforeTheLedgerNamedItsChangeShowsTheRound(): void
    {
        $this->ledger->startRound('round-7', 'alpha');

        $card = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card');

        self::assertSame('#round-7', $card->filter('.top span')->first()->text());
        self::assertSame('Review of round round-7', $card->filter('.title')->text());
        self::assertSame('Round 1', $this->wordsWithoutExplanationOf($card->filter('.meta .term')));
    }

    public function testACrowdedColumnShowsTwelveCardsAndOffersTheOtherTwentyEight(): void
    {
        $this->startRoundsOf(40);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertSame('12', $column->attr('data-page-limit'));
        self::assertCount(12, $column->filter('.col-cards > article:not([data-past-limit])'));
        self::assertCount(28, $column->filter('.col-cards > article[data-past-limit]'));
        self::assertSame(['Show 28 more', 'button'], [$column->filter('.col-cards > button.more-btn')->text(), $column->filter('.more-btn')->attr('type')]);
    }

    public function testACrowdedColumnCountsEveryCardNotOnlyTheShownOnes(): void
    {
        $this->startRoundsOf(40);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertSame('40', $column->filter('.col-head .count')->text());
    }

    public function testAColumnOfThirteenCardsOffersOneMoreAfterTheFirstTwelveInTheBoardsOrder(): void
    {
        $this->startRoundsOf(13);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertSame('Show 1 more', $column->filter('.more-btn')->text());
        self::assertSame(['#13'], $column->filter('[data-past-limit] .top .num')->each(static fn (Crawler $number): string => $number->text()));
        self::assertSame('#12', $column->filter('article:not([data-past-limit]) .top .num')->last()->text());
    }

    public function testAColumnOfTwelveCardsShowsThemAllWithoutShowMore(): void
    {
        $this->startRoundsOf(12);

        $column = $this->column($this->browser->request('GET', '/'), 'In review');

        self::assertCount(12, $column->filter('.col-cards > article'));
        self::assertCount(0, $column->filter('.more-btn, [data-past-limit]'));
        self::assertNull($column->attr('data-page-limit'));
    }

    // DESIGN.md: a card clamps a long title to three lines and the corner truncates a long project name, so the page carries both whole for the clamp and the tooltip.
    public function testACardCarriesALongTitleAndALongProjectNameWhole(): void
    {
        $change = str_repeat('a-change-with-a-long-name-', 8);
        $project = 'a-project-with-a-name-longer-than-its-corner';
        $this->ledger->startRoundOf($change, 'round-1', 1);
        $this->ledger->startRound('round-2', $project);

        $cards = $this->column($this->browser->request('GET', '/'), 'In review')->filter('.card');

        self::assertSame('Review of '.$change, $cards->first()->filter('.title')->text());
        self::assertSame(['#'.$change, '#'.$change], [$cards->first()->filter('.top .num')->text(), $cards->first()->filter('.top .num')->attr('title')]);
        self::assertSame([$project, $project], [$cards->last()->filter('.pname')->text(), $cards->last()->filter('.pname')->attr('title')]);
    }

    public function testTheBoardShowsTheStatusLineInPlaceOfTheLanesLinkedToTheStatusPage(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');

        $page = $this->browser->request('GET', '/');

        self::assertSame('Running smoothly · 2 reviewers working', $page->filter('a.status-line')->text());
        self::assertSame('/lanes', $page->filter('a.status-line')->attr('href'));
        self::assertCount(0, $page->filter('.lane, details.working, .working-none'));
        self::assertCount(1, $page->filter('a.status-line + section.working + section .board-wrap > .board'));
    }

    public function testEachReviewInProgressIsAWorkingCardWithItsChange(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $cards = $this->browser->request('GET', '/')->filter('.working .wcard');

        self::assertCount(1, $cards);
        self::assertSame('42', $cards->filter('.wcard-title')->text());
        self::assertSame('Reviewing', $cards->filter('.chip')->text());
    }

    public function testEachWorkingCardLinksToTheReviewPageOfItsRound(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startRoundOf('43', 'round-2', 3);
        $this->ledger->startReviewer('round-2', 'security');

        $cards = $this->browser->request('GET', '/')->filter('.working a.wcard');

        self::assertEqualsCanonicalizing(['/reviews/round-1', '/reviews/round-2'], $cards->each(static fn (Crawler $card): ?string => $card->attr('href')));
    }

    public function testTheCodeReviewerStandsOnTheReviewStepWithAltTextNamingRoleAndStep(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $images = $this->browser->request('GET', '/')->filter('.wcard .path li.now .who img.character');

        self::assertSame(
            [['Code reviewer, now on Review', '48'], ['Code reviewer, now on Review', '48']],
            $images->each(static fn (Crawler $image): array => [$image->attr('alt'), $image->attr('width')]),
        );
        self::assertSame(1, preg_match('~^\S*/roles/code_reviewer-light-\S+\.webp 1x, \S*/roles/code_reviewer-light-\S+\.webp 2x$~', $images->eq(0)->attr('srcset') ?? ''));
        self::assertSame(1, preg_match('~^\S*/roles/code_reviewer-dark-\S+\.webp 1x, \S*/roles/code_reviewer-dark-\S+\.webp 2x$~', $images->eq(1)->attr('srcset') ?? ''));
    }

    public function testThePathMarksTheStepsBeforeReviewDoneAndMergeNext(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $path = $this->browser->request('GET', '/')->filter('.wcard ol.path');

        self::assertSame('Progress: Review, step 4 of 5', $path->attr('aria-label'));
        self::assertSame(
            [['done', 'Plan (done)'], ['done', 'Build (done)'], ['done', 'Check (done)'], ['now', 'Review'], [null, 'Merge (next)']],
            $path->filter('li')->each(static fn (Crawler $step): array => [$step->attr('class'), $step->text()]),
        );
    }

    public function testTheBoardCardsAndTheWorkingCardsHeadsShowNoCharacter(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $page = $this->browser->request('GET', '/');

        self::assertCount(0, $page->filter('.board .card img, .wcard-head img, .working > h2 img'));
    }

    public function testABoardWithNobodyWorkingShowsNoMeerkat(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        self::assertCount(0, $this->browser->request('GET', '/')->filter('img[src*="/roles/"]'));
    }

    public function testAJudgedReviewIsNoWorkingCard(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        self::assertCount(0, $this->browser->request('GET', '/')->filter('.working .wcard'));
    }

    public function testTheHeadingCountsTheChangesInProgress(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startReviewer('round-1', 'security');

        self::assertSame('Working now 1', $this->browser->request('GET', '/')->filter('.working > h2')->text());
    }

    public function testOnceAReviewHasRunABoardWithNobodyRunningSaysSoQuietly(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        self::assertSame('Quiet · nobody working', $this->browser->request('GET', '/')->filter('a.status-line')->text());
    }

    // Working now keeps its own empty line: the Status page's line speaks of reviewers, this one of the changes in progress (#3386, decision 6).
    public function testOnceAReviewHasRunWorkingNowSaysWhatWillShowUpThere(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/');

        self::assertSame('Nobody is working yet: reviewers appear here while they review a change', $page->filter('p#working-now.working-none')->text());
        self::assertCount(0, $page->filter('details.working, .working .wcard'));
        self::assertCount(1, $page->filter('p.working-none + section .board-wrap > .board'));
    }

    public function testTheStatusLineAndTheCardsShowNoCharacter(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        self::assertCount(0, $this->browser->request('GET', '/')->filter('.status-line img, .board .card img'));
    }

    public function testWhileAReviewRunsWorkingNowIsASectionOfCardsAboveTheColumnsWithNoDisclosure(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $page = $this->browser->request('GET', '/');

        self::assertCount(1, $page->filter('section.working + section .board-wrap > .board'));
        self::assertCount(0, $page->filter('details, .working-none'));
    }

    public function testWhileSomethingWaitsNeedsYouCountsItLinksToWaitingOnYouAndConductsWithTheOctopus(): void
    {
        self::clockAt('2026-11-01T12:00:00+00:00');
        $this->noticesAre(new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00')));
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $card = $this->browser->request('GET', '/?board=1')->filter('a.needs-you');

        self::assertSame(['/waiting', '1 decision', 'Oldest since 11:00 UTC'], [$card->attr('href'), $card->filter('.decisions')->text(), $card->filter('.meta')->text()]);
        self::assertCount(2, $card->filter('img[src*="octopus-waiting"]'));
    }

    public function testAWaitFromAnEarlierDayNamesItsDateOnNeedsYou(): void
    {
        self::clockAt('2026-11-03T09:00:00+00:00');
        $this->noticesAre(new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00')));

        self::assertSame('Oldest since 1 Nov, 11:00 UTC', $this->browser->request('GET', '/?board=1')->filter('a.needs-you .meta')->text());
    }

    public function testNothingWaitingShowsNoNeedsYouCard(): void
    {
        $this->noticesAre();
        $this->ledger->startRoundOf('42', 'round-1', 1);

        self::assertCount(0, $this->browser->request('GET', '/?board=1')->filter('.needs-you'));
    }

    public function testTheBoardStaysAvailableWithoutNeedsYouWhenTheOwnerNoticesCannotBeRead(): void
    {
        $this->readings = [new RuntimeException('notice store unavailable'), new RuntimeException('notice store unavailable')];
        $this->ledger->startRoundOf('42', 'round-1', 1);

        $page = $this->browser->request('GET', '/?board=1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('.needs-you'));
    }

    public function testNeedsYouAndTheYouBadgeShowTheSameReadingWhenANoticeClearsMeanwhile(): void
    {
        $notice = new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-01T08:00:00+00:00'), new DateTimeImmutable('2026-11-01T11:00:00+00:00'));
        $this->readings = [[$notice], []];

        $page = $this->browser->request('GET', '/?board=1');

        self::assertSame(['1 decision', '1'], [$page->filter('.needs-you .decisions')->text(), $page->filter('a[href="/waiting"]:not(.needs-you) .count')->first()->text('missing')]);
    }

    public function testTheBoardLinksToTheReviewListBehindIt(): void
    {
        $link = $this->browser->request('GET', '/')->filter('.page-head .actions a.secondary');

        self::assertSame('/reviews', $link->attr('href'));
        self::assertSame('Open the review list', $link->text());
    }

    public function testAFreshInstallsBoardLinksTheFirstRunPageAsItsPrimaryAction(): void
    {
        $link = $this->browser->request('GET', '/')->filter('.page-head .actions a.btn.primary');

        self::assertSame(['/setup'], $link->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('Set up Intakt', $link->text());
    }

    public function testOnceAReviewHasRunTheBoardNoLongerLinksTheFirstRunPage(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        self::assertCount(0, $this->browser->request('GET', '/')->filter('a[href="/setup"]'));
    }

    public function testOnAFreshInstallEveryShownColumnSaysWhatWillAppearInIt(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame(
            array_map(static fn (BoardColumn $column): string => $column->emptyLine(), [BoardColumn::WaitingOnYou, BoardColumn::InReview, BoardColumn::Done]),
            $page->filter('.board > section.col .col-empty')->each(static fn (Crawler $empty): string => $empty->text()),
        );
    }

    public function testOnAFreshInstallTheStatusLineSaysNobodyIsWorking(): void
    {
        self::assertSame('Quiet · nobody working', $this->browser->request('GET', '/')->filter('a.status-line')->text());
    }

    public function testABoardWithoutFailingHeadsShowsNoBanner(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);

        self::assertCount(0, $this->browser->request('GET', '/')->filter('.banner'));
    }

    public function testEachFailingHeadIsABrokenBannerOfItsProjectAboveTheStatusLine(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $this->ledger->failHead('beta', '8', 1);

        $page = $this->browser->request('GET', '/');

        self::assertSame(['alpha', 'beta'], $page->filter('.page-head ~ .banner.broken')->each(static fn (Crawler $banner): ?string => $banner->attr('data-project')));
        self::assertCount(1, $page->filter('.banner ~ .status-line'));
        self::assertCount(0, $page->filter('.status-line ~ .banner'));
    }

    public function testAMainThatFailsToBootIsOneBrokenBannerOfItsProject(): void
    {
        $this->ledger->failMainBoot('alpha', 'No status.');

        $banner = $this->browser->request('GET', '/')->filter('.page-head ~ .banner.broken');

        self::assertCount(1, $banner);
        self::assertSame('alpha', $banner->attr('data-project'));
        self::assertSame('Main of alpha does not boot. Reviews go ahead without the boot gate until it boots again: No status.', $banner->filter('p')->text());
    }

    public function testABackingOffHeadsBannerNamesItsPullRequestItsCauseAndItsNextTry(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $banner = $this->browser->request('GET', '/')->filter('.banner');

        self::assertSame('Pull request 7 of alpha failed', $banner->filter('.banner-toggle span')->text());
        self::assertSame('Pull request 7 of alpha failed. 1 failure in a row, next try at 12:08 UTC: RuntimeException: commit a not fetched', $banner->filter('p')->text());
    }

    public function testAHandedOverHeadsBannerSaysTheMaintainerAgentHasIt(): void
    {
        $this->ledger->handOverHead('alpha', '7', 'c');

        $text = $this->browser->request('GET', '/')->filter('.banner p')->text();

        self::assertSame('Pull request 7 of alpha failed. Handed over to the maintainer agent after 4 failures: RuntimeException: commit c not fetched', $text);
    }

    public function testABannerLinksToTheFailedPullRequestsOnTheReviewList(): void
    {
        $this->ledger->failHead('alpha', '7', 1);

        $link = $this->browser->request('GET', '/')->filter('.banner > a.btn');

        self::assertSame('/reviews#failing-heads', $link->attr('href'));
        self::assertSame('See the failed pull requests', $link->text());
    }

    public function testACredentialInTheCauseIsRedactedOnTheBanner(): void
    {
        $token = 'ghp_'.str_repeat('a1', 18);
        $this->ledger->failHeadBecause('alpha', '7', 'clone refused for token '.$token);

        $page = $this->browser->request('GET', '/');

        self::assertStringContainsString('clone refused for token [redacted]', $page->filter('.banner p')->text());
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent());
    }

    public function testMarkupInTheProjectAndTheCauseIsShownAsTextOnTheBanner(): void
    {
        $this->ledger->failHeadBecause('<i>alpha</i>', '7', '<b>exit 1</b>');

        $banner = $this->browser->request('GET', '/')->filter('.banner');

        self::assertSame('<i>alpha</i>', $banner->attr('data-project'));
        self::assertSame('Pull request 7 of <i>alpha</i> failed', $banner->filter('.banner-toggle span')->text());
        self::assertStringEndsWith('of <i>alpha</i> failed. 2 failures in a row, next try at 12:08 UTC: <b>exit 1</b>', $banner->filter('p')->text());
        self::assertCount(0, $banner->filter('i, b'));
    }

    public function testABoardWithoutLiveUpdatesWiresTheBannerToggleToTheBannerScript(): void
    {
        $this->ledger->failHead('alpha', '7', 1);
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $page = $this->browser->request('GET', '/');

        self::assertCount(0, $page->filter('script[type="importmap"]'));
        self::assertCount(0, $page->filter('.banner[data-controller], .banner [data-action]'));
        self::assertSame('false', $page->filter('.banner > button.banner-toggle')->attr('aria-expanded'));
        $script = $page->filter('head script[type="module"]')->text();
        self::assertStringContainsString(sprintf("import { toggleBanners } from '%s';", $packages->getUrl('banner-toggle.js')), $script);
        self::assertStringContainsString('toggleBanners(document);', $script);
    }

    public function testTheBannerScriptTheBoardImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('banner-toggle.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function toggleBanners(page)', $this->browser->getInternalResponse()->getContent());
    }

    public function testABoardWithoutLiveUpdatesStillImportsTheCopyCommandScript(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $page = $this->browser->request('GET', '/');

        self::assertCount(0, $page->filter('script[type="importmap"]'));
        $script = $page->filter('head script[type="module"]')->text();
        self::assertStringContainsString(sprintf("import { copyCommands } from '%s';", $packages->getUrl('copy-command.js')), $script);
        self::assertStringContainsString('copyCommands(document, window);', $script);
    }

    public function testTheCopyCommandScriptTheBoardImportsIsServedByTheInstall(): void
    {
        $packages = self::getContainer()->get(Packages::class);
        self::assertInstanceOf(Packages::class, $packages);

        $this->browser->request('GET', $packages->getUrl('copy-command.js'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export function copyCommands(page, view)', $this->browser->getInternalResponse()->getContent());
    }

    public function testTheBoardUsesTheFullWidthOfThePage(): void
    {
        self::assertCount(1, $this->browser->request('GET', '/')->filter('main.content.wide'));
    }

    public function testTheRootAddressRefusesAHostThatDoesNotNameTheDashboard(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_HOST' => 'attacker.example:7780']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testTheBoardRendersTheInfrastructureRowFromRecordedMetricsAndThreadsResponses(): void
    {
        $page = $this->browser->request('GET', '/');

        self::assertSame([
            ['Threads busy', '0'],
            ['Threads idle', '2'],
            ['Queue depth', '0'],
            ['Worker restarts', '2'],
            ['Hub', 'healthy'],
        ], $this->infrastructureIn($page));
    }

    public function testTheBoardShowsTheInfrastructureRowAsDownWhenTheAdminApiIsUnreachable(): void
    {
        $this->server = new RecordedAdminApi()->unreachable()->status();

        $row = $this->browser->request('GET', '/')->filter('#infrastructure');

        self::assertResponseIsSuccessful();
        self::assertSame(['Worker threads', 'Server metrics', 'Hub'], $row->filter('.infra-figure.down dt')->each(static fn (Crawler $label): string => $label->text()));
        self::assertStringContainsString('/frankenphp/threads did not answer: Failed to connect to localhost port 2019', $row->filter('.infra-figure.down dd')->first()->text());
    }

    public function testTheBoardShowsTheInfrastructureRowAsDownInsteadOfFailingWhenTheAdminApiUrlIsNotOnLocalhost(): void
    {
        $this->server = new RecordedAdminApi()->status('https://admin.example:2019');

        $row = $this->browser->request('GET', '/')->filter('#infrastructure');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $row->filter('.infra-figure.down'));
        self::assertStringContainsString('must be plain http on localhost, 127.0.0.1 or [::1], not https on admin.example', $row->text());
    }

    public function testTheBoardMarksAnUnhealthyHubInTheInfrastructureRow(): void
    {
        $this->server = new RecordedAdminApi()->answering('/mercure/health/ready', '{"status":"down"}', 503)->status();

        $hub = $this->browser->request('GET', '/')->filter('#infrastructure .infra-figure.unhealthy');

        self::assertSame(['Hub', 'unhealthy'], [$hub->filter('dt')->text(), $hub->filter('dd')->text()]);
    }

    public function testACredentialInTheReasonOfADownSourceIsRedactedInTheInfrastructureRow(): void
    {
        $token = 'ghp_'.str_repeat('a1', 18);
        $status = self::createStub(ServerStatus::class);
        $status->method('read')->willReturn(new ServerReading(new EndpointDown('refused for token '.$token), new ServerMetrics(0, 0), HubHealth::Healthy));
        $this->server = $status;

        $page = $this->browser->request('GET', '/');

        self::assertStringContainsString('refused for token [redacted]', $page->filter('#infrastructure .infra-figure.down dd')->text());
        self::assertStringNotContainsString($token, (string) $this->browser->getResponse()->getContent());
    }

    // Nothing waits unless a test says so, because the board reads the owner notices for its Needs you card on every load and a page test creates no schema.
    protected function setUp(): void
    {
        parent::setUp();
        $stub = self::createStub(OwnerNotices::class);
        $stub->method('stillRunningOldestFirst')->willReturnCallback(function (): array {
            $reading = array_shift($this->readings) ?? $this->notices;

            return $reading instanceof RuntimeException ? throw $reading : $reading;
        });
        self::getContainer()->set(OwnerNotices::class, $stub);
    }

    private function column(Crawler $page, string $name): Crawler
    {
        return $page->filter('.board > section.col')->reduce(static fn (Crawler $column): bool => $column->filter('.col-head h3')->text() === $name);
    }

    // Replaced in the container, because the repository is read when the container is built and these tests share one built without it.
    private function onADemoInstall(): void
    {
        $container = self::getContainer();
        $container->set(SampleChangeSource::class, new SampleChangeSource($container->get(TierResolver::class), '/repos/sample'));
    }

    private function startRoundsOf(int $changes): void
    {
        foreach (range(1, $changes) as $change) {
            $this->ledger->startRoundOf((string) $change, 'round-'.$change, 1);
        }
    }

    private function wordsWithoutExplanationOf(Crawler $term): string
    {
        return trim(str_replace($term->filter('.term-tip')->text(), '', $term->text()));
    }

    /**
     * @return list<string>
     */
    private function cardNumbersIn(Crawler $page, string $name): array
    {
        return $this->column($page, $name)->filter('.col-cards .card .top .num')->each(static fn (Crawler $number): string => $number->text());
    }

    private function noticesAre(OutageNotice ...$notices): void
    {
        $this->notices = array_values($notices);
    }

    /**
     * @return list<array{string, string}>
     */
    private function infrastructureIn(Crawler $page): array
    {
        return $page->filter('#infrastructure .infra-figure')->each(static fn (Crawler $figure): array => [$figure->filter('dt')->text(), $figure->filter('dd')->text()]);
    }
}
