<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Infrastructure\ConfiguredAccounts;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Dashboard\Domain\AgentStep;
use Intakt\Core\Dashboard\Domain\FirstReviewStep;
use Intakt\Core\Dashboard\Domain\FirstRun;
use Intakt\Core\Dashboard\Domain\PanelOfProject;
use Intakt\Core\Dashboard\Domain\ProjectStep;
use Intakt\Core\Dashboard\Domain\ProviderStep;
use Intakt\Core\Dashboard\Domain\StepState;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\FirstRun\FirstRunController;
use Intakt\Core\Dashboard\UseCase\FirstRun\FirstRunSteps;
use Intakt\Core\Dashboard\UseCase\StartReview\ReviewStarts;
use Intakt\Core\Dashboard\UseCase\StartReview\StartFirstReviewController;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LogLevel;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(FirstRunController::class)]
#[CoversClass(FirstRunSteps::class)]
#[CoversClass(FirstRun::class)]
#[CoversClass(ProviderStep::class)]
#[CoversClass(ProjectStep::class)]
#[CoversClass(FirstReviewStep::class)]
#[CoversClass(AgentStep::class)]
#[CoversClass(PanelOfProject::class)]
#[CoversClass(TierLine::class)]
#[CoversClass(StepState::class)]
#[CoversClass(StartFirstReviewController::class)]
#[CoversClass(ReviewStarts::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(ConfiguredAccounts::class)]
#[UsesClass(DashboardLedger::class)]
final class FirstRunControllerTest extends WebTestCase
{
    // The container is built from these, so each one a test changes is cleared first and restored after.
    private const array VARIABLES = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'INTAKT_CONFIG',
        'MCP_OWNER_TOKEN', 'MCP_OWNER_ACCESS', 'INTAKT_COMPOSE_SERVICE', 'INTAKT_SAMPLE_REPOSITORY',
    ];

    private const string SAMPLE_REPOSITORY = '/repos/sample';

    private const string PROJECTS = <<<'YAML'
        projects:
            sample:
                tiers:
                    strong: { provider: anthropic, model: big }
                    standard: { provider: anthropic, model: middle }
                    light: { provider: openai, model: small }
                review:
                    quorum: all
                    reviewers:
                        - { role: correctness, tier: strong, provider: anthropic, prompt: Check the logic., budget: 3 }
                        - { role: security, tier: standard, provider: anthropic, prompt: Check the risks., budget: 3 }
            beta:
                tiers:
                    strong: { provider: openai, model: other }
        YAML;

    private const string OWNER_TOKEN = 'owner-token-for-the-first-run-test';

    private string $file = '';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    private CompiledContainers $containers;

    private DashboardLedger $ledger;

    private ?MessageBusInterface $commands = null;

    private string $now = '2026-10-28T12:00:00+00:00';

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-first-run-'.bin2hex(random_bytes(4)).'.yaml';
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        $_SERVER['MCP_OWNER_ACCESS'] = 'mcp_tool_write';
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = '';
        $_SERVER['MCP_OWNER_TOKEN'] = '';
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = '';
        $this->ledger = new DashboardLedger();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $before) {
            $this->unsetVariable($name);
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (false !== $before['process']) {
                putenv($name.'='.$before['process']);
            }
        }

        parent::tearDown();
        $this->containers->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    public function testWithOneKeyTheProviderStepIsDoneAndNamesTheProviderThatHasIt(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('anthropic has a key.', $page->filter('#step-providers .lead')->text());
    }

    public function testWithOneKeyEveryOtherProviderIsNamedWithTheVariableToSet(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame([
            'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.',
            'Provider "openai_compatible" is disabled: set OPENAI_COMPATIBLE_BASE_URL and OPENAI_COMPATIBLE_API_KEY to enable it.',
            'Provider "claude_code" is disabled: set CLAUDE_CODE_OAUTH_TOKEN to enable it.',
        ], $page->filter('#step-providers .keyless li')->each(static fn (Crawler $line): string => $line->text()));
    }

    public function testEachTierOfTheFirstProjectShowsItsModelOrWhyItHasNone(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The tiers of the project sample:', $page->filter('#step-providers .tiers-of')->text());
        self::assertSame([
            'strong anthropic big',
            'standard anthropic middle',
            'light - Project "sample" maps the "light" tier to a disabled provider. Provider "openai" is disabled: set OPENAI_API_KEY to enable it.',
        ], $page->filter('#step-providers .tiers tbody tr')->each(static fn (Crawler $row): string => implode(' ', $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text()))));
    }

    public function testWithNoKeyTheProviderStepIsMissingAndEveryProviderNamesItsVariable(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('missing', $page->filter('#step-providers')->attr('data-state'));
        self::assertSame('No provider has a key yet: set the variable named below for a provider you use.', $page->filter('#step-providers .lead')->text());
        self::assertCount(4, $page->filter('#step-providers .keyless li'));
        self::assertStringContainsString('set ANTHROPIC_API_KEY to enable it.', $page->filter('#step-providers .keyless')->text());
    }

    public function testWithNoKeyNoTierOfTheFirstProjectHasAModel(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame(['-', '-', '-'], $page->filter('#step-providers .tiers tbody td:nth-child(2)')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testTheProjectStepListsEachProjectWithTheReviewersOfItsPanel(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-project')->attr('data-state'));
        self::assertSame('2 projects are configured.', $page->filter('#step-project .lead')->text());
        self::assertSame(['sample', 'beta'], $page->filter('#step-project .list .t')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(
            'Reviewers: correctness (strong tier on anthropic), security (standard tier on anthropic).',
            $page->filter('#step-project .list .d')->first()->text(),
        );
    }

    public function testAProjectWithoutAPanelSaysSoInsteadOfItsReviewers(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Project "beta" has no review panel configured.', $page->filter('#step-project .list .d')->last()->text());
    }

    public function testWithNoProjectTheProjectStepIsMissingAndTheProviderStepListsNoTiers(): void
    {
        $page = $this->firstRunPage("projects: {}\n", ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-project')->attr('data-state'));
        self::assertSame('No project is configured yet: add one to the project file that INTAKT_CONFIG names.', $page->filter('#step-project .lead')->text());
        self::assertCount(0, $page->filter('#step-project .list'));
        self::assertCount(0, $page->filter('#step-providers .tiers'));
    }

    public function testTheAgentStepShowsTheMcpAddressAndTheClientLineButNeverTheToken(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-agent')->attr('data-state'));
        self::assertSame('Your agent connects with the owner token and may read and start reviews.', $page->filter('#step-agent .lead')->text());
        self::assertSame('http://localhost:7780/mcp/owner', $page->filter('#step-agent .address')->text());
        self::assertSame(
            'claude mcp add --transport http intakt http://localhost:7780/mcp/owner --header "Authorization: Bearer <the token>"',
            $page->filter('#step-agent .client-line')->text(),
        );
        self::assertStringNotContainsString(self::OWNER_TOKEN, (string) $this->browser()->getResponse()->getContent());
    }

    public function testOutsideTheComposeDemoTheTokenIsTheValueOfTheOwnerTokenVariable(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('Replace <the token> with the value of MCP_OWNER_TOKEN.', $page->filter('#step-agent .token-from')->text());
        self::assertCount(0, $page->filter('#step-agent .token-command'));
    }

    public function testInTheComposeDemoTheAgentStepNamesTheCommandThatPrintsTheClientLine(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
        $_SERVER['INTAKT_COMPOSE_SERVICE'] = 'intakt';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('This command prints the line with the token filled in:', $page->filter('#step-agent .token-from')->text());
        self::assertSame('docker compose logs intakt | grep "claude mcp add"', $page->filter('#step-agent .token-command')->text());
    }

    public function testWithAnOwnerTokenButReadOnlyAccessTheAgentStepSaysItMayOnlyRead(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
        $_SERVER['MCP_OWNER_ACCESS'] = 'mcp_tool_read';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-agent')->attr('data-state'));
        self::assertSame('Your agent connects with the owner token; it may only read until MCP_OWNER_ACCESS is mcp_tool_write.', $page->filter('#step-agent .lead')->text());
    }

    public function testWithoutAnOwnerTokenTheAgentStepIsMissingAndTheClientLineHasNoHeader(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-agent')->attr('data-state'));
        self::assertSame(
            'Your agent can connect and read without a token; to let it start reviews, set MCP_OWNER_TOKEN, and MCP_OWNER_ACCESS to mcp_tool_write, in .env.local.',
            $page->filter('#step-agent .lead')->text(),
        );
        self::assertSame('claude mcp add --transport http intakt http://localhost:7780/mcp/owner', $page->filter('#step-agent .client-line')->text());
        self::assertCount(0, $page->filter('#step-agent .token-from'));
    }

    public function testTheMarkersShowTheFourStepsOfThisInstallAndNoStepOfTheLaterWizard(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['1 AI providers', '2 A project', '3 First review', '4 Your own agent'], $page->filter('ol.steps li')->each(static fn (Crawler $marker): string => $marker->text()));
        self::assertSame(['done', 'done', 'missing', 'missing'], $page->filter('ol.steps li')->each(static fn (Crawler $marker): string => (string) $marker->attr('class')));
    }

    public function testTheProgressCountsTheStepsThatAreDone(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('1 of 4 steps done', $page->filter('.step-count')->text());
    }

    public function testAStepThatIsNotNeededLeavesTheProgress(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('not-needed', $page->filter('ol.steps li')->eq(2)->attr('class'));
        self::assertSame('1 of 3 steps done', $page->filter('.step-count')->text());
    }

    public function testTheProgressCountsTheStepsThatAreDoneWithAKeyAReviewAndAToken(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('4 of 4 steps done', $page->filter('.step-count')->text());
    }

    public function testWithTheSampleProjectAndNoReviewTheFirstReviewStepIsMissingAndOffersTheStart(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('missing', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame('No review has run yet: start the review of the change sample-change of the sample project.', $page->filter('#step-first-review .lead')->text());
        self::assertSame('/setup/first-review', $page->filter('#step-first-review form.start-first-review')->attr('action'));
        self::assertSame('post', $page->filter('#step-first-review form.start-first-review')->attr('method'));
    }

    public function testBeforeAReviewRanTheOnlyPrimaryButtonStartsTheFirstReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Start the first review'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame('submit', $page->filter('form.start-first-review .btn.primary')->attr('type'));
    }

    public function testAfterAReviewRanTheFirstReviewStepIsDoneAndLinksToItsReviewPage(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('done', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame('/reviews/round-1', $page->filter('#step-first-review a.btn.primary')->attr('href'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testAfterAReviewRanTheOnlyPrimaryButtonOpensTheReview(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['Open the review'], $page->filter('main .btn.primary')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function testARunningFirstReviewIsShownInProgress(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is in progress.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAFirstReviewThatRanOutOfTimeSaysItEndedWithoutAJudgesCall(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->now = '2026-10-30T12:00:00+00:00';

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, ended without a call of the judge.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAJudgedFirstReviewNamesItsOutcome(): void
    {
        $this->ledger->startRoundOfQuorum(SampleChange::CHANGE, 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is judged with the outcome approve.', $page->filter('#step-first-review .lead')->text());
    }

    public function testAJudgedFirstReviewWithoutARecordedQuorumSaysItsOutcomeWasNotRecorded(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('The first review, of the change sample-change of the alpha project, is judged; its outcome was not recorded.', $page->filter('#step-first-review .lead')->text());
    }

    public function testTheStepLinksToTheLatestRoundOfTheFirstReviewInTheLedger(): void
    {
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-1', 1);
        $this->ledger->startRoundOf(SampleChange::CHANGE, 'round-2', 2);
        $this->ledger->startRoundOf('feat/other', 'round-3', 1);

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('/reviews/round-2', $page->filter('#step-first-review a.btn.primary')->attr('href'));
    }

    public function testWithoutASampleRepositoryAndNoReviewTheStepIsNotNeededAndThePageHasNoPrimaryButton(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertSame(
            'No review has run yet, and this install has no sample change to start one from: review a change of your own project with intakt:review or your agent.',
            $page->filter('#step-first-review .lead')->text(),
        );
        self::assertCount(0, $page->filter('main .btn.primary'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testWithoutTheSampleProjectTheStepIsNotNeeded(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;

        $page = $this->firstRunPage(<<<'YAML'
            projects:
                beta:
                    tiers:
                        strong: { provider: anthropic, model: big }
            YAML, ['ANTHROPIC_API_KEY']);

        self::assertSame('not-needed', $page->filter('#step-first-review')->attr('data-state'));
        self::assertCount(0, $page->filter('form.start-first-review'));
    }

    public function testTheStartButtonStartsTheSampleChangeAndComesBackWithTheRoundId(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')
            ->with(self::equalTo(new StartReview(SampleChange::PROJECT, SampleChange::TASK, new RequestedRound(SampleChange::CHANGE, 1, new ChangeSource(self::SAMPLE_REPOSITORY, SampleChange::BASE)))))
            ->willReturn(new Envelope(new stdClass(), [new HandledStamp('round-1', 'handler')]));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/setup');
        $this->browser()->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Started round round-1.');
    }

    public function testASecondPressComesBackWithTheRefusalAndNoTrace(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $commands = $this->commandBus();
        $token = $this->tokenFromThePage();
        $commands->expects(self::once())->method('dispatch')
            ->willThrowException(new HandlerFailedException(new Envelope(new stdClass()), [RoundNotNext::for(1, 2)]));

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        $this->browser()->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Round 1 was asked for');
        self::assertStringNotContainsString('Stack trace', (string) $this->browser()->getResponse()->getContent());
    }

    public function testAStartWithoutTheTokenDispatchesNothing(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $this->commandBus()->expects(self::never())->method('dispatch');
        $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        $this->browser()->request('POST', '/setup/first-review', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(400);
        $this->assertLoggedClientError(sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', BadRequestHttpException::class));
    }

    public function testAStartAfterTheSampleRepositoryWasUnsetIsRefused(): void
    {
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = self::SAMPLE_REPOSITORY;
        $token = $this->tokenFromThePage();
        $_SERVER['INTAKT_SAMPLE_REPOSITORY'] = '';
        // Rebooted, because only a new kernel reads the variable again; the session that holds the token stays.
        $this->browser()->enableReboot();

        $this->browser()->request('POST', '/setup/first-review', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        $this->assertLoggedClientError(sprintf('Uncaught PHP Exception %s: "This install has no sample change to review, so nothing was started."', NotFoundHttpException::class));
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $page = $this->firstRunPage(<<<'YAML'
            projects:
                '<b>bold</b>':
                    tiers:
                        strong: { provider: anthropic, model: big }
            YAML, ['ANTHROPIC_API_KEY']);

        self::assertCount(0, $page->filter('main b'));
        self::assertSame('<b>bold</b>', $page->filter('#step-project .list .t')->text());
    }

    /**
     * @param list<string> $keys
     */
    private function firstRunPage(string $projects, array $keys): Crawler
    {
        file_put_contents($this->file, $projects);

        foreach ($keys as $key) {
            $_SERVER[$key] = 'test-value';
        }

        self::ensureKernelShutdown();
        // Keyed by what the container is built from, so an install with other keys or projects gets a container of its own.
        $this->containers->useFor([$projects, $keys]);
        $browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        // Kept between requests, so the ledger double and the bus mock stay for the post after the page that hands out the token.
        $browser->disableReboot();
        self::getContainer()->set(Ledger::class, $this->ledger);
        // The ledger stamps its rows at one moment, so a round is not overdue and ended by the time the page reads it.
        self::getContainer()->set(ClockInterface::class, new MockClock($this->now));
        if ($this->commands instanceof MessageBusInterface) {
            self::getContainer()->set('command.bus', $this->commands);
        }
        $page = $browser->request('GET', '/setup');
        self::assertResponseIsSuccessful();

        return $page;
    }

    // Read from the page's form, because the token belongs to the browser's session.
    private function tokenFromThePage(): string
    {
        return $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY'])->filter('form.start-first-review input[name=_token]')->attr('value') ?? '';
    }

    // Set by the next page, because the page's own services take the bus when they are built.
    private function commandBus(): MessageBusInterface&MockObject
    {
        $commands = $this->createMock(MessageBusInterface::class);
        $this->commands = $commands;

        return $commands;
    }

    // Symfony 8.1 logs an uncaught client error (4xx) at error and 8.2 at warning, so the record counts at either level while both are supported.
    private function assertLoggedClientError(string $message): void
    {
        $records = LogRecords::ofThisRun();
        self::assertNotNull($records, 'No test log records: run the tests with the UnassertedErrorLogExtension.');
        self::assertTrue(
            $records->remove(LogLevel::ERROR, $message) || $records->remove(LogLevel::WARNING, $message),
            sprintf('Expected an error or warning log record "%s", none was logged.', $message),
        );
    }

    private function browser(): KernelBrowser
    {
        $browser = self::getClient();
        self::assertInstanceOf(KernelBrowser::class, $browser);

        return $browser;
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'first-run');
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
