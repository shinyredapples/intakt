<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Domain\TourCaption;
use Intakt\Core\Dashboard\Domain\TourStage;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
use Intakt\Core\Dashboard\Infrastructure\FormIsRefused;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRuns;
use Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoController;
use Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoRun;
use Intakt\Core\Dashboard\UseCase\PlayDemo\ShowDemoController;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardPageTestCase;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(ShowDemoController::class)]
#[CoversClass(PlayDemoController::class)]
#[CoversClass(DemoRuns::class)]
#[CoversClass(DemoReadiness::class)]
#[CoversClass(DemoTour::class)]
#[UsesClass(TourCaption::class)]
#[UsesClass(TourStage::class)]
final class PlayDemoControllerTest extends DashboardPageTestCase
{
    use AssertsLoggedErrors;

    // Read when a service is built, so each test sets them before its first request and puts them back after.
    private const array VARIABLES = ['INTAKT_COMPOSE_SERVICE', 'INTAKT_DEMO_RECORDING'];

    private string $recording = '';

    /** @var array<string, array{server: mixed, env: mixed}> */
    private array $saved = [];

    public function testTheDemoPageOffersPlayTheDemoRunWithAFormToken(): void
    {
        $page = $this->demoPage();

        self::assertSame('ready', $page->filter('.demo-readiness')->attr('data-readiness'));
        self::assertSame('/demo', $page->filter('form.play-demo')->attr('action'));
        self::assertNotSame('', $page->filter('form.play-demo input[name=_token]')->attr('value'));
        self::assertSame(['Play the demo run'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testTheDemoPageDrawsTheFourStepsOfAReviewInsteadOfListingTheStages(): void
    {
        $page = $this->demoPage();

        self::assertSame(['The change', 'Two AI labs', 'Checks every finding', 'To fix'], $page->filter('ol.review-strip .rs-label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertCount(0, $page->filter('ol.tour-stages'));
    }

    public function testTheDemoPageShowsTheNoKeyNeededFactAsAChip(): void
    {
        $page = $this->demoPage();

        self::assertSame(['No key needed', 'Replay, not a verdict'], $page->filter('.demo-facts .chip')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function testTheDemoPageSaysItInOneShortLine(): void
    {
        $page = $this->demoPage();

        self::assertSame(['A real review, recorded and played back.'], $page->filter('main .demo p:not(.demo-facts):not([hidden])')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testWhenTheRunIsReadyTheButtonStandsForTheReadinessLine(): void
    {
        $page = $this->demoPage();

        self::assertSame('', $page->filter('.demo-readiness[hidden]')->text());
    }

    public function testAfterTheRunTheDemoPageOffersNoPlayAgain(): void
    {
        $this->demoRunAt(1);

        self::assertCount(0, $this->demoPage()->filter('main .demo button, main .demo .btn'));
    }

    public function testOutsideTheComposeDemoThePageSaysWhyAndOffersNoButton(): void
    {
        $this->onTheComposeDemo('', $this->recording);

        $page = $this->demoPage();

        self::assertSame(DemoReadiness::NotComposeDemo->sentence(), $page->filter('.demo-readiness')->text());
        self::assertCount(0, $page->filter('form.play-demo'));
    }

    public function testWithoutARecordingThePageSaysNoneIsInstalled(): void
    {
        $this->onTheComposeDemo('intakt', '');

        $page = $this->demoPage();

        self::assertSame('No demo recording is installed: set INTAKT_DEMO_RECORDING to a file written by intakt:demo:record.', $page->filter('.demo-readiness')->text());
        self::assertCount(0, $page->filter('form.play-demo'));
    }

    public function testARecordingFileThatIsMissingCountsAsNoRecording(): void
    {
        $this->onTheComposeDemo('intakt', $this->recording.'.gone');

        self::assertSame('no-recording', $this->demoPage()->filter('.demo-readiness')->attr('data-readiness'));
    }

    public function testAfterTheRunThePageSaysItIsInTheLedgerAndOffersNoButton(): void
    {
        $this->demoRunAt(1);

        $page = $this->demoPage();

        self::assertSame(DemoReadiness::Played->sentence(), $page->filter('.demo-readiness')->text());
        self::assertCount(0, $page->filter('form.play-demo'));
    }

    public function testPressingPlayQueuesOnePlayDemoRunAndComesBackWithTheStartLine(): void
    {
        $token = $this->tokenFromThePage();

        $this->browser->request('POST', '/demo', ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertMatchesRegularExpression('~^/demo\?play=[0-9a-f]{32}$~', (string) $this->browser->getResponse()->headers->get('Location'));
        $play = substr((string) $this->browser->getResponse()->headers->get('Location'), -32);
        self::assertEquals([new PlayDemoRun($play)], array_map(static fn (Envelope $sent): object => $sent->getMessage(), $this->queued()));
        $page = $this->browser->followRedirect();
        self::assertCount(1, $page->filter('#demo-play-'.$play.' .flash-success'));
        self::assertSelectorTextContains('.flash-success', 'The demo run is starting');
    }

    public function testThePagePlaysRedirectReachesTellsTheTourToFollowTheRun(): void
    {
        $token = $this->tokenFromThePage();
        $this->browser->request('POST', '/demo', ['_token' => $token]);

        $page = $this->browser->followRedirect();

        self::assertSame(['The demo run is starting: the caption at the bottom of each page names the stage it is in.'], $page->filter('[data-tour-follow]')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testALaterVisitToTheDemoPageDoesNotTellTheTourToFollow(): void
    {
        $token = $this->tokenFromThePage();
        $this->browser->request('POST', '/demo', ['_token' => $token]);
        $this->browser->followRedirect();

        self::assertCount(0, $this->demoPage()->filter('[data-tour-follow]'));
    }

    public function testAVisitFromAnotherTabBeforePlaysRedirectArrivesLeavesTheFollowToTheRedirect(): void
    {
        $token = $this->tokenFromThePage();
        $this->browser->request('POST', '/demo', ['_token' => $token]);
        $redirect = (string) $this->browser->getResponse()->headers->get('Location');

        $otherTab = $this->demoPage();
        $page = $this->browser->request('GET', $redirect);

        self::assertCount(0, $otherTab->filter('[data-tour-follow]'));
        self::assertCount(1, $page->filter('[data-tour-follow]'));
    }

    public function testAPlaySignThatNoPlayGaveDoesNotTellTheTourToFollow(): void
    {
        $token = $this->tokenFromThePage();
        $this->browser->request('POST', '/demo', ['_token' => $token]);

        $page = $this->browser->request('GET', '/demo?play='.str_repeat('0', 32));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-tour-follow]'));
    }

    public function testAPlaySignInAnotherShapeIsIgnored(): void
    {
        $page = $this->browser->request('GET', '/demo?play=started');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-tour-follow]'));
    }

    public function testASuccessLineFromAnotherFormDoesNotTellTheTourToFollow(): void
    {
        $this->demoPage();
        $session = $this->browser->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $session->getFlashBag()->add('success', 'The provider key is kept.');
        $session->save();

        $page = $this->demoPage();

        self::assertSame('The provider key is kept.', $page->filter('.flash-success')->text());
        self::assertCount(0, $page->filter('[data-tour-follow]'));
    }

    public function testPressingPlayWithoutAValidTokenStartsNothing(): void
    {
        $this->demoPage();

        $page = $this->browser->request('POST', '/demo', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->queued());
        self::assertSame('The form token is missing or wrong, so the demo run was not started. Go back, reload the page and press the button again.', $page->filter('.page-head .lead')->text());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so the demo run was not started."', FormIsRefused::class));
    }

    public function testPressingPlayWithATokenThatIsNoTextStartsNothingAndShowsTheSameRefusal(): void
    {
        $this->demoPage();

        $page = $this->browser->request('POST', '/demo', ['_token' => ['wrong']]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->queued());
        self::assertSame('The form token is missing or wrong, so the demo run was not started. Go back, reload the page and press the button again.', $page->filter('.page-head .lead')->text());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so the demo run was not started."', FormIsRefused::class));
    }

    public function testPressingPlayOutsideTheComposeDemoStartsNothingAndSaysWhy(): void
    {
        $token = $this->tokenFromThePage();
        $this->onTheComposeDemo('', $this->recording);
        // Rebooted, because only a new kernel reads the variable again; the session that holds the token stays.
        $this->browser->enableReboot();

        $this->browser->request('POST', '/demo', ['_token' => $token]);

        self::assertResponseRedirects('/demo');
        self::assertSame([], $this->queued());
        // Read from the session, because the rebooted kernel no longer holds the ledger double the next page would read.
        $session = $this->browser->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame([DemoReadiness::NotComposeDemo->sentence()], $session->getFlashBag()->peek('error'));
    }

    public function testPressingPlayAfterTheRunStartsNothingAndSaysItIsInTheLedger(): void
    {
        $token = $this->tokenFromThePage();
        $this->demoRunAt(1);

        $this->browser->request('POST', '/demo', ['_token' => $token]);

        self::assertSame([], $this->queued());
        $this->browser->followRedirect();
        self::assertSelectorTextSame('.flash-error', DemoReadiness::Played->sentence());
    }

    public function testTheCaptionSitsOnEveryPageWhileADemoRunIsInTheLedger(): void
    {
        $this->demoRunAt(1);

        foreach (['/', '/projects', '/reviews', '/demo', '/reviews/demo-round-1'] as $path) {
            $page = $this->browser->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame('Step 1 of 5', $page->filter('aside#tour-caption .tour-dots')->attr('aria-label'), $path);
        }
    }

    public function testTheCaptionNamesTheStageAndLinksTheReviewAndCanBePausedAndClosed(): void
    {
        $this->demoRunAt(1);

        $caption = $this->demoPage()->filter('aside#tour-caption');

        self::assertSame(TourStage::ChangeArrives->sentence(), $caption->filter('.tour-line')->text());
        self::assertSame('/reviews/demo-round-1', $caption->filter('a.tour-review')->attr('href'));
        self::assertSame('false', $caption->filter('button[data-tour-pause]')->attr('aria-pressed'));
        self::assertCount(1, $caption->filter('button[data-tour-close]'));
    }

    public function testWhileTheReviewersReadTheCaptionNamesAKnownReviewerAsModelAndAiLabWithItsIdInTheTitle(): void
    {
        $this->demoRunAt(1);
        $this->startReviewerOf('correctness', 'moonshotai/Kimi-K3.1:deepinfra');

        $reviewer = $this->demoPage()->filter('aside#tour-caption .tour-reviewers span');

        self::assertSame(['Kimi K3.1 (Moonshot AI)', 'moonshotai/Kimi-K3.1:deepinfra'], [$reviewer->text(), $reviewer->attr('title')]);
        self::assertSame(TourStage::ReviewersRead->sentence().' Reviewers: Kimi K3.1 (Moonshot AI).', $this->demoPage()->filter('aside#tour-caption .tour-line')->text());
    }

    public function testTheCaptionNamesAReviewerOfAnUnknownModelByItsIdAlone(): void
    {
        $this->demoRunAt(1);
        $this->startReviewerOf('correctness', 'acme/robot-7');
        $this->startReviewerOf('security', 'glm-5');

        self::assertSame(['acme/robot-7', 'GLM 5 (Z.ai)'], $this->demoPage()->filter('aside#tour-caption .tour-reviewers span')->each(static fn (Crawler $name): string => $name->text()));
    }

    public function testTheCaptionSitsAfterThePageContentAndNotInIt(): void
    {
        $this->demoRunAt(1);

        $page = $this->demoPage();

        self::assertCount(1, $page->filter('.main > main.content + aside#tour-caption'));
        self::assertCount(0, $page->filter('main.content #tour-caption'));
    }

    public function testASecondRoundMovesTheCaptionOn(): void
    {
        $this->demoRunAt(1);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"demo-round-1","act":[],"follow_ups":[]}'));
        $this->demoRunAt(2);

        self::assertSame(TourStage::ChangeArrives->value, $this->demoPage()->filter('aside#tour-caption')->attr('data-stage'));
    }

    public function testOnlyTheCaptionOfTheLastStageSaysItIsTheLast(): void
    {
        $this->demoRunAt(1);
        self::assertCount(0, $this->demoPage()->filter('aside#tour-caption[data-tour-last]'));

        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"demo-round-1","act":[],"follow_ups":[]}'));

        self::assertSame(TourStage::RoundEnds->value, $this->demoPage()->filter('aside#tour-caption[data-tour-last]')->attr('data-stage'));
    }

    public function testThePageTheLastStageLinksToHoldsTheElementTheStageMarks(): void
    {
        $this->browser->disableReboot();
        $this->demoRunAt(1);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"demo-round-1","act":[],"follow_ups":[]}'));
        $caption = $this->demoPage()->filter('aside#tour-caption[data-tour-last]');

        $review = $this->browser->request('GET', (string) $caption->filter('a.tour-review')->attr('href'));

        self::assertSame('#review-verdict[data-round="demo-round-1"]', $caption->attr('data-tour-at'));
        self::assertCount(1, $review->filter($caption->attr('data-tour-at')));
    }

    public function testAnotherRoundsReviewPageHoldsNoElementTheLastStageMarks(): void
    {
        $this->browser->disableReboot();
        $this->demoRunAt(1);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"demo-round-1","act":[],"follow_ups":[]}'));
        $this->ledger->startRoundOf('feat/thing', 'round-9', 1);
        $this->ledger->judgeFindings('round-9', []);
        $at = (string) $this->demoPage()->filter('aside#tour-caption')->attr('data-tour-at');

        $other = $this->browser->request('GET', '/reviews/round-9');

        self::assertSame([1, 0], [$other->filter('#review-verdict')->count(), $other->filter($at)->count()]);
    }

    public function testTheCaptionShowsOneDotPerStageWithTheCurrentOneMarkedAndNoStageCount(): void
    {
        $this->demoRunAt(1);

        $caption = $this->demoPage()->filter('aside#tour-caption');

        self::assertCount(TourStage::count(), $caption->filter('.tour-dot'));
        self::assertCount(1, $caption->filter('.tour-dot.now'));
        self::assertDoesNotMatchRegularExpression('/Stage \d+ of/', $caption->text());
    }

    public function testTheCaptionPointsAtTheElementsOfItsOwnRoundOnly(): void
    {
        $this->demoRunAt(1);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"demo-round-1","act":[],"follow_ups":[]}'));
        $this->demoRunAt(2);

        $at = $this->demoPage()->filter('aside#tour-caption')->attr('data-tour-at');

        self::assertSame('[id="round-demo-round-2-now-phase"]', $at);
        self::assertStringNotContainsString('demo-round-1', $at);
    }

    public function testBeforeADemoRunTheComposeDemoPagesHoldOnlyTheEmptyCaption(): void
    {
        $page = $this->demoPage();

        self::assertCount(1, $page->filter('div#tour-caption[hidden]'));
        self::assertCount(0, $page->filter('.tour-caption'));
    }

    public function testOutsideTheComposeDemoNoPageHoldsACaption(): void
    {
        $this->onTheComposeDemo('', $this->recording);
        $this->demoRunAt(1);

        self::assertCount(0, $this->demoPage()->filter('#tour-caption'));
    }

    public function testTheErrorPageHoldsNoCaption(): void
    {
        $this->demoRunAt(1);

        $page = $this->browser->request('GET', '/no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $page->filter('#tour-caption'));
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780/no-such-page""', NotFoundHttpException::class));
    }

    public function testTheFirstRunPageOffersPlayTheDemoRunAsItsOnePrimaryButton(): void
    {
        $page = $this->browser->request('GET', '/setup');

        self::assertResponseIsSuccessful();
        self::assertSame('/demo', $page->filter('form.play-demo')->attr('action'));
        self::assertSame(['Play the demo run'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
        self::assertNotSame('', $page->filter('form.play-demo input[name=_token]')->attr('value'));
    }

    public function testTheFirstRunPageSaysPlayNeedsNoKeyAndWhatItShows(): void
    {
        $page = $this->browser->request('GET', '/setup');

        self::assertSame('1 minute · no key', $page->filter('#see-review .fr-when')->text());
        self::assertSame('Two AI labs read a change, then the owl decides what to fix.', $page->filter('#see-review .next')->text());
    }

    public function testTheFirstRunPageOffersNoDemoRunOnceItIsInTheLedger(): void
    {
        $this->demoRunAt(1);

        $page = $this->browser->request('GET', '/setup');

        self::assertCount(0, $page->filter('form.play-demo'));
    }

    public function testTheFirstRunPagesDemoButtonQueuesTheRun(): void
    {
        $token = $this->browser->request('GET', '/setup')->filter('form.play-demo input[name=_token]')->attr('value') ?? '';

        $this->browser->request('POST', '/demo', ['_token' => $token]);

        self::assertCount(1, $this->queued());
    }

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null];
        }

        $this->recording = sys_get_temp_dir().'/intakt-play-demo-page-'.bin2hex(random_bytes(6)).'.json';
        new Filesystem()->dumpFile($this->recording, '{}');
        $this->onTheComposeDemo('intakt', $this->recording);
        parent::setUp();
        // Kept between requests, so the ledger double and the transport stay for the post after the page that hands out the token.
        $this->browser->disableReboot();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            unset($_SERVER[$name], $_ENV[$name]);
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
        }

        new Filesystem()->remove($this->recording);
        parent::tearDown();
    }

    private function demoPage(): Crawler
    {
        $page = $this->browser->request('GET', '/demo');
        self::assertResponseIsSuccessful();

        return $page;
    }

    private function tokenFromThePage(): string
    {
        return $this->demoPage()->filter('form.play-demo input[name=_token]')->attr('value') ?? '';
    }

    private function onTheComposeDemo(string $composeService, string $recording): void
    {
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = $_ENV['INTAKT_COMPOSE_SERVICE'] = $composeService;
        $_SERVER['INTAKT_DEMO_RECORDING'] = $_ENV['INTAKT_DEMO_RECORDING'] = $recording;
    }

    // Started as a replay writes it, in the flagged demo project, so the pages read it as a demo run.
    private function demoRunAt(int $number): void
    {
        $this->flagAsReplay(CreateDemoProjectCommand::NAME);
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, sprintf('{"round":"demo-round-%d","project":"%s","reviewers":2,"change":"sample-change","number":%d,"replay_factor":4.0}', $number, CreateDemoProjectCommand::NAME, $number)));
    }

    private function startReviewerOf(string $role, string $model): void
    {
        $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStarted, sprintf('{"round":"demo-round-1","reviewer":"demo-round-1-%1$s","role":"%1$s","tier":"standard","model":"%2$s"}', $role, $model)));
    }

    /**
     * @return list<Envelope>
     */
    private function queued(): array
    {
        return array_values(self::getQueuedMessages('async'));
    }
}
