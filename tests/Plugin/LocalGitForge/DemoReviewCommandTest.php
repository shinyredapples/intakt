<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierInvoker;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Mcp\Contract\OwnerClientLine;
use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Review\Contract\AwaitedReviewer;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DemoProgress;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DemoProviderBusy;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DemoReviewCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\OutputFormat;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Ai\Infrastructure\AccountWindowsOnAClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(DemoReviewCommand::class)]
#[CoversClass(OwnerClientLine::class)]
#[UsesClass(OwnerToken::class)]
#[UsesClass(ReviewExit::class)]
#[UsesClass(ReviewReport::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(CleanupOnStop::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(OutputFormat::class)]
#[UsesClass(ReviewOutput::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(DemoProgress::class)]
#[UsesClass(DemoProviderBusy::class)]
#[UsesClass(AwaitedReviewer::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(PinnedProvider::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(Account::class)]
final class DemoReviewCommandTest extends TestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000000';

    private const string KIMI = 'moonshotai/Kimi-K3.1-Pro:deepinfra';

    private const string KIMI_TOGETHER = 'moonshotai/Kimi-K3.1-Pro:together';

    private TemporaryRepository $sample;

    private TierInvoker $tiers;

    private MockClock $clock;

    /**
     * @return iterable<string, array{Decision, int}>
     */
    public static function decisions(): iterable
    {
        yield 'approve' => [Decision::Approve, 0];
        yield 'changes' => [Decision::Changes, 10];
        yield 'blocked' => [Decision::Blocked, 11];
    }

    public function testTheDemoReviewsTheSampleChangeOfTheSampleProjectWithItsTask(): void
    {
        $requests = [];
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request) use (&$requests): ChangeReview {
            $requests[] = $request;

            return $this->outcome(Decision::Approve);
        });

        $this->whenTheDemoRuns($reviews);

        self::assertCount(1, $requests);
        self::assertSame([SampleChange::PROJECT, SampleChange::TASK, SampleChange::CHANGE], [$requests[0]->project, $requests[0]->task, $requests[0]->round->change]);
    }

    public function testTheDemoPrintsTheVerdictAndTheActListFirst(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Changes));

        $report = $this->report()->asLines($this->outcome(Decision::Changes));
        self::assertSame($report, array_slice($this->lines($tester), 0, count($report)));
        self::assertStringStartsWith('changes (', $report[0]);
        self::assertContains('F1 P1 src/Shipping.php:1: Missing check.', $report);
    }

    public function testTheDemoPrintsTheDashboardAddressOfTheReviewedRoundAfterTheReport(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve));

        self::assertSame('Open the review: http://localhost:7781/reviews/'.self::ROUND, $this->lines($tester)[count($this->lines($tester)) - 2]);
    }

    public function testTheDemoPrintsTheMcpLineWithTheOwnerTokenLast(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve), ownerToken: 'demo<token>');

        self::assertSame(
            'Connect an MCP client: claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header \'Authorization: Bearer demo<token>\'',
            array_last($this->lines($tester)),
        );
    }

    public function testWithoutAnOwnerTokenTheMcpLineConnectsWithoutAHeader(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve), ownerToken: '');

        self::assertStringStartsWith('No MCP owner token found: set MCP_OWNER_TOKEN', (string) array_last($this->lines($tester)));
    }

    #[DataProvider('decisions')]
    public function testTheExitCodeIsTheReviewCommandsForTheDecision(Decision $decision, int $code): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing($decision));

        self::assertSame($code, $tester->getStatusCode());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingRepositories(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('missingRepositories')]
    public function testWithoutASampleRepositoryTheDemoFailsNamingTheVariable(?string $repository): void
    {
        $tester = $this->whenTheDemoRunsOn($repository);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('INTAKT_SAMPLE_REPOSITORY is not set', $tester->getDisplay());
        self::assertStringNotContainsString('claude mcp add', $tester->getDisplay());
    }

    public function testAReportableFailureFailsWithItsMessageAndNoAddress(): void
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new PanelNotConfigured(SampleChange::PROJECT));

        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve), panels: $panels);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"sample"', $tester->getDisplay());
        self::assertStringNotContainsString('Open the review', $tester->getDisplay());
    }

    // Issue #2803: the line goes to the error output before the verdict, so the report stays the standard output.
    public function testTheDemoPrintsTheReviewerItWaitsOnBeforeTheVerdict(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request, ReviewProgress $progress): ChangeReview {
            $progress->reached($this->waitingOnCorrectness());

            return $this->outcome(Decision::Approve);
        });

        $tester = $this->whenTheDemoRuns($reviews);

        self::assertSame('Waiting on the correctness reviewer ('.self::KIMI.').', trim($tester->getErrorOutput()));
        self::assertStringStartsWith('approve (', $this->lines($tester)[0]);
    }

    // Forced order, because the worker's 429 must land before the poll: the demo then stops at that poll while the clock has not moved, instead of waiting through 60 s retries (issue #2802).
    public function testARateLimitedReviewerStopsTheDemoAtOnceWithAPlainMessageAndAFailureExit(): void
    {
        $tiers = $this->rateLimitedRouter($this->clock);
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request, ReviewProgress $progress) use ($tiers): ChangeReview {
            try {
                $tiers->invoke($tiers->answering(SampleChange::PROJECT, Tier::Strong), new TierRequest('Review.'))->asText();
            } catch (EveryAccountCoolingDown) {
            }

            $progress->reached($this->waitingOnCorrectness());

            return $this->outcome(Decision::Approve);
        });

        $this->tiers = $tiers;
        $tester = $this->whenTheDemoRuns($reviews);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The correctness reviewer cannot get an answer: openai_compatible is rate-limited for 60 s and no fallback is free.', preg_replace('~\s+~', ' ', $tester->getDisplay()) ?? '');
        self::assertStringNotContainsString('Open the review', $tester->getDisplay());
        self::assertEquals(new DateTimeImmutable('2026-11-20T12:00:00+00:00'), $this->clock->now());
    }

    // Through Symfony AI's generic bridge over a scripted HTTP client, so the 429 is a real response: the pinned provider answers it, the pin cools, and the next call goes to the pinned fallback and completes the demo (issues #2861 and #2816).
    public function testADemoVerdictSucceedsThroughTheScriptedHttpRouterAfterThePrimaryAnswers429(): void
    {
        $models = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$models): MockResponse {
            self::assertIsString($options['body']);
            $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            $models[] = $request['model'];

            if (self::KIMI === $request['model']) {
                return new MockResponse('{"error":{"message":"rate limited"}}', ['http_code' => 429, 'response_headers' => ['content-type: application/json', 'retry-after: 300']]);
            }

            return new MockResponse(json_encode(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'approve'], 'finish_reason' => 'stop']]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });
        $config = [SampleChange::PROJECT => ['tiers' => ['strong' => ['provider' => Provider::OpenAiCompatible->value, 'model' => self::KIMI, 'substitutes' => [['provider' => Provider::OpenAiCompatible->value, 'model' => self::KIMI_TOGETHER]]]]]];
        $bridge = Factory::createPlatform(
            'https://router.example',
            'test',
            httpClient: $http,
            modelCatalog: new InMemoryModelCatalog(new ProjectModelCatalog($config)->models(Provider::OpenAiCompatible)),
        );
        $pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
        $accounts = new AccountWindowsOnAClock($this->clock, [Provider::OpenAiCompatible->value => [Account::DEFAULT]]);
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $bridge], $accounts, $this->clock, new NullLogger(), $pins);
        $tiers = new TierPlatform(new ConfiguredTierResolver($config, []), new ServiceLocator([Provider::OpenAiCompatible->value => static fn (): PlatformInterface => $platform]), $accounts, new NullLogger());
        $reviews = self::createStub(ChangeReviews::class);
        // The decision is the fallback's answer as the HTTP response carried it, so the demo can only approve when that response reached it; no clock move between the attempts, so the retry follows at once.
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request, ReviewProgress $progress) use ($tiers): ChangeReview {
            $answer = null;
            while (null === $answer) {
                $progress->reached($this->waitingOnCorrectness());

                try {
                    $answer = $tiers->invoke($tiers->answering(SampleChange::PROJECT, Tier::Strong), new TierRequest(new MessageBag(Message::ofUser('Review.'))))->asText();
                } catch (EveryAccountCoolingDown) {
                }
            }

            return $this->outcome(Decision::from($answer));
        });
        $this->tiers = $tiers;

        $tester = $this->whenTheDemoRuns($reviews);

        self::assertSame([Command::SUCCESS, [self::KIMI, self::KIMI_TOGETHER]], [$tester->getStatusCode(), $models]);
        self::assertStringContainsString('Waiting on the correctness reviewer ('.self::KIMI_TOGETHER.').', $tester->getErrorOutput());
        self::assertStringStartsWith('approve (', $this->lines($tester)[0]);
        self::assertEquals(new DateTimeImmutable('2026-11-20T12:00:00+00:00'), $this->clock->now());
    }

    // The queued round goes on in the worker after the command exits and reads the checkout, so the exit must not remove it; the judged round does (RemoveKeptSnapshot).
    public function testTheRateLimitExitKeepsTheCheckoutForTheRoundThatGoesOn(): void
    {
        $checkout = null;
        $reviews = self::createStub(ChangeReviews::class);
        $tiers = $this->rateLimitedRouter($this->clock);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request, ReviewProgress $progress) use (&$checkout, $tiers): ChangeReview {
            $checkout = $request->checkoutRoot;
            try {
                $tiers->invoke($tiers->answering(SampleChange::PROJECT, Tier::Strong), new TierRequest('Review.'))->asText();
            } catch (EveryAccountCoolingDown) {
            }

            $progress->reached($this->waitingOnCorrectness());

            return $this->outcome(Decision::Approve);
        });
        $this->tiers = $tiers;

        $this->whenTheDemoRuns($reviews);

        self::assertDirectoryExists((string) $checkout);
        new Filesystem()->remove((string) $checkout);
    }

    public function testAFinishedDemoRemovesItsCheckout(): void
    {
        $checkout = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request) use (&$checkout): ChangeReview {
            $checkout = $request->checkoutRoot;

            return $this->outcome(Decision::Approve);
        });

        $this->whenTheDemoRuns($reviews);

        self::assertDirectoryDoesNotExist((string) $checkout);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-20T12:00:00+00:00');
        $this->tiers = $this->answering();
        $this->sample = new TemporaryRepository();
        $this->sample->commitFile('README.md', "base\n");
        $this->sample->checkoutNewBranch(SampleChange::CHANGE);
        $this->sample->commitFile('src/Shipping.php', "<?php\n");
        $this->sample->checkout(SampleChange::BASE);
    }

    protected function tearDown(): void
    {
        $this->sample->remove();
    }

    private function reviewing(Decision $decision): ChangeReviews
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturn($this->outcome($decision));

        return $reviews;
    }

    private function outcome(Decision $decision): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/Shipping.php', 1, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $verdicts = [new PanelVerdict(Tier::Strong, new Verdict($decision, [$finding], [], []))];

        return new ChangeReview(self::ROUND, 1, $decision, $verdicts, [$finding], RoundTokens::of(1, new TokenFigures(100, 0, 0, 0, 20)), Cost::inUsd(0.00014));
    }

    private function whenTheDemoRuns(ChangeReviews $reviews, string $ownerToken = 'demo-token', ?Panels $panels = null): CommandTester
    {
        return $this->whenTheDemoIsRun($this->demo($this->sample->path, new LocalChangeReview($panels ?? $this->onePanel(), $reviews), $ownerToken));
    }

    private function whenTheDemoRunsOn(?string $repository): CommandTester
    {
        return $this->whenTheDemoIsRun($this->demo($repository, new LocalChangeReview($this->onePanel(), $this->reviewing(Decision::Approve)), 'demo-token'));
    }

    private function report(): ReviewReport
    {
        return new ReviewReport(StoredSerializerFactory::create());
    }

    /**
     * @return list<string>
     */
    private function lines(CommandTester $tester): array
    {
        return explode("\n", rtrim($tester->getDisplay(), "\n"));
    }

    private function onePanel(): Panels
    {
        $reviewer = new PanelReviewer(new ReviewerRole('correctness', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return $panels;
    }

    private function demo(?string $repository, LocalChangeReview $review, string $ownerToken): DemoReviewCommand
    {
        $routes = new RouteCollection();
        $routes->add(DashboardRoutes::SHOW_REVIEW, new Route('/reviews/{round}'));

        // The base URL with a trailing slash, as an owner may set it, so the line proves it is trimmed.
        return new DemoReviewCommand($review, $this->report(), new UrlGenerator($routes, new RequestContext()), $repository, 7781, 'http://localhost:7781/', new OwnerToken($ownerToken, null), $this->tiers, $this->clock);
    }

    private function whenTheDemoIsRun(DemoReviewCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('intakt:demo'));
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function rateLimitedRouter(MockClock $clock): TierPlatform
    {
        $router = MockPlatformFactory::createPlatform(static fn (): never => throw new RateLimitExceededException(60));
        $pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $clock, new LockFactory(new InMemoryStore()));
        $accounts = new AccountWindowsOnAClock($clock, [Provider::OpenAiCompatible->value => [Account::DEFAULT]]);
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $router], $accounts, $clock, new NullLogger(), $pins);
        $resolver = new ConfiguredTierResolver([SampleChange::PROJECT => ['tiers' => ['strong' => ['provider' => Provider::OpenAiCompatible->value, 'model' => self::KIMI]]]], []);

        return new TierPlatform($resolver, new ServiceLocator([Provider::OpenAiCompatible->value => static fn (): PlatformInterface => $platform]), $accounts, new NullLogger());
    }

    private function waitingOnCorrectness(): RoundProgress
    {
        return new RoundProgress(0, 1, false, [], 1, [new AwaitedReviewer('correctness', Tier::Strong, 'reviewer')]);
    }

    private function answering(): TierInvoker
    {
        $tiers = self::createStub(TierInvoker::class);
        $tiers->method('answering')->willReturn(new ResolvedModel(Provider::OpenAiCompatible, self::KIMI));

        return $tiers;
    }
}
