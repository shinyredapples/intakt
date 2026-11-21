<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Domain\TourCaption;
use Intakt\Core\Dashboard\Domain\TourStage;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        self::assertResponseRedirects('/demo');
        self::assertEquals([new PlayDemoRun()], array_map(static fn (Envelope $sent): object => $sent->getMessage(), $this->queued()));
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'The demo run is starting');
    }

    public function testPressingPlayWithoutAValidTokenStartsNothing(): void
    {
        $this->demoPage();

        $this->browser->request('POST', '/demo', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->queued());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so the demo run was not started."', BadRequestHttpException::class));
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

    public function testWithoutTheChoicesTheFirstRunPageShowsTheNoKeyNeededChipBesidePlay(): void
    {
        $page = $this->browser->request('GET', '/setup');

        self::assertSame('No key needed', $page->filter('.play-demo-offer .chip')->text());
        self::assertCount(0, $page->filter('.play-demo-offer p'));
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

    /**
     * @return list<Envelope>
     */
    private function queued(): array
    {
        return array_values(self::getQueuedMessages('async'));
    }
}
