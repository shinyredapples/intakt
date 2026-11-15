<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\AgentStep;
use Intakt\Core\Dashboard\Domain\FirstRun;
use Intakt\Core\Dashboard\Domain\PanelOfProject;
use Intakt\Core\Dashboard\Domain\ProjectStep;
use Intakt\Core\Dashboard\Domain\ProviderStep;
use Intakt\Core\Dashboard\Domain\StepState;
use Intakt\Core\Dashboard\Domain\TierLine;
use Intakt\Core\Dashboard\UseCase\FirstRun\FirstRunController;
use Intakt\Core\Dashboard\UseCase\FirstRun\FirstRunSteps;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(FirstRunController::class)]
#[CoversClass(FirstRunSteps::class)]
#[CoversClass(FirstRun::class)]
#[CoversClass(ProviderStep::class)]
#[CoversClass(ProjectStep::class)]
#[CoversClass(AgentStep::class)]
#[CoversClass(PanelOfProject::class)]
#[CoversClass(TierLine::class)]
#[CoversClass(StepState::class)]
final class FirstRunControllerTest extends WebTestCase
{
    // The container is built from these, so each one a test changes is cleared first and restored after.
    private const array VARIABLES = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'INTAKT_CONFIG',
        'MCP_OWNER_TOKEN', 'MCP_OWNER_ACCESS', 'INTAKT_COMPOSE_SERVICE',
    ];

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

    public function testTheMarkersShowTheThreeStepsOfThisInstallAndNoStepOfTheLaterWizard(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame(['1 AI providers', '2 A project', '3 Your own agent'], $page->filter('ol.steps li')->each(static fn (Crawler $marker): string => $marker->text()));
        self::assertSame(['done', 'done', 'missing'], $page->filter('ol.steps li')->each(static fn (Crawler $marker): string => (string) $marker->attr('class')));
    }

    public function testTheProgressCountsTheStepsThatAreDone(): void
    {
        $page = $this->firstRunPage(self::PROJECTS, []);

        self::assertSame('1 of 3 steps done', $page->filter('.step-count')->text());
    }

    public function testTheProgressCountsTheStepsThatAreDoneWithAKeyAndAToken(): void
    {
        $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;

        $page = $this->firstRunPage(self::PROJECTS, ['ANTHROPIC_API_KEY']);

        self::assertSame('3 of 3 steps done', $page->filter('.step-count')->text());
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
        self::getContainer()->set(Ledger::class, new DashboardLedger());
        $page = $browser->request('GET', '/setup');
        self::assertResponseIsSuccessful();

        return $page;
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
