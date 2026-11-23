<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Budget\Contract\BudgetServices;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\ReviewServices;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Core\ReviewerForLoop\Contract\ProjectModes;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ServiceProviderInterface;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class.
#[CoversNothing]
final class ProjectTiersTest extends KernelTestCase
{
    // The compiled containers are keyed by these variables alone, so the container must not read at compile time any other variable that a test changes, or a reused container would carry a stale value.
    private const array KEYS = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'INTAKT_CONFIG',
        'ANTHROPIC_API_KEY_TEAM', 'OPENAI_API_KEY_TEAM', 'OPENAI_COMPATIBLE_BASE_URL_TEAM', 'OPENAI_COMPATIBLE_API_KEY_TEAM', 'CLAUDE_CODE_OAUTH_TOKEN_TEAM',
    ];

    private string $file = '';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    private CompiledContainers $containers;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testTheContainerBuildsWithTwoProjectsThatMapTiersDifferently(): void
    {
        $resolver = $this->bootedContainer()->get(TierResolver::class);
        self::assertInstanceOf(TierResolver::class, $resolver);

        self::assertSame(['alpha-one', 'beta'], $resolver->projects());
        self::assertSame('small', $resolver->resolve('alpha-one', Tier::Light)->model);
        self::assertSame(Provider::Anthropic, $resolver->resolve('alpha-one', Tier::Strong)->provider);

        $this->expectException(TierProviderDisabled::class);
        $resolver->resolve('beta', Tier::Strong);
    }

    public function testAProjectReviewSectionLoadsAsAPanel(): void
    {
        self::assertSame('correctness', $this->bootedPanels()->panelFor('alpha-one')->reviewers[0]->role->name);
    }

    public function testAProjectWithoutAReviewSectionHasNoPanel(): void
    {
        $panels = $this->bootedPanels();

        $this->expectException(PanelNotConfigured::class);
        $panels->panelFor('beta');
    }

    public function testAnUnknownKeyBesideTheReviewSectionStillStopsTheBoot(): void
    {
        file_put_contents($this->file, <<<'YAML'
            projects:
                alpha-one:
                    tiers:
                        strong: { provider: anthropic, model: big }
                    review:
                        quorum: all
                        reviewers:
                            - { role: correctness, tier: strong, provider: anthropic, prompt: Check the logic., budget: 3 }
                    reveiw: { quorum: all }
            YAML);

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/reveiw/');

        $this->bootedContainer();
    }

    public function testAProjectWatchSectionLoadsAsAWatchedProjectBesideItsTiers(): void
    {
        $container = $this->bootedContainer();

        self::assertSame(['alpha-one' => ['repository' => 'repos/alpha', 'base' => 'main', 'task' => 'Review the pull request.']], $container->getParameter(WatcherServices::PROJECTS));
        self::assertInstanceOf(TierResolver::class, $container->get(TierResolver::class));
    }

    public function testAWatchSectionWithoutItsTaskStopsTheBoot(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        watch: { repository: repos/beta, base: main }\n        tiers:\n            strong: { provider: openai, model: other }\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/"task" under "intakt\.projects\.beta" must be configured/');

        $this->bootedContainer();
    }

    public function testAnUnknownProjectModeStopsTheBoot(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        mode: quiet\n        tiers:\n            strong: { provider: openai, model: other }\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/"quiet" is not allowed for path "intakt\.projects\.beta\.mode"/');

        $this->bootedContainer();
    }

    public function testAShadowModeStopsTheBootAsAnUnknownMode(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        mode: shadow\n        tiers:\n            strong: { provider: openai, model: other }\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/"shadow" is not allowed for path "intakt\.projects\.beta\.mode"/');

        $this->bootedContainer();
    }

    public function testAReviewerForLoopModeIsTheModeTheContainerReadsForItsProject(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        mode: reviewer-for-loop\n        tiers:\n            strong: { provider: openai, model: other }\n");

        $modes = $this->bootedContainer()->get(ProjectModes::class);

        self::assertInstanceOf(ProjectModes::class, $modes);
        self::assertSame([ProjectMode::ReviewerForLoop, ProjectMode::Live], [$modes->of('beta'), $modes->of('gamma')]);
    }

    public function testAProjectReviewJobImageIsTheImageTheContainerReadsForItsProject(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        review_job: { image: 'intakt-job/beta:review' }\n        tiers:\n            strong: { provider: openai, model: other }\n    gamma:\n        tiers:\n            strong: { provider: openai, model: other }\n");

        self::assertSame(['beta' => 'intakt-job/beta:review'], $this->bootedContainer()->getParameter(ReviewServices::JOB_IMAGES));
    }

    public function testAReviewJobSectionWithoutItsImageStopsTheBoot(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        review_job: {}\n        tiers:\n            strong: { provider: openai, model: other }\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/"image" under "intakt\.projects\.beta" must be configured/');

        $this->bootedContainer();
    }

    public function testAProjectsValueThatIsNoMapStopsTheBoot(): void
    {
        file_put_contents($this->file, "projects: broken\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/Invalid type for path "intakt\.projects"/');

        $this->bootedContainer();
    }

    public function testTheOwnersCeilingsBesideTheProjectsReachTheBudgetWithTheDefaultForAWindowLeftOut(): void
    {
        file_put_contents($this->file, "budget:\n    ceilings:\n        five-hour: 80\nprojects:\n    beta:\n        tiers:\n            strong: { provider: openai, model: other }\n");

        self::assertSame(['five-hour' => 80.0, 'weekly' => 90.0], $this->bootedContainer()->getParameter(BudgetServices::CEILINGS));
    }

    public function testTheOwnersPricesBesideTheCeilingsReachTheBudgetWithTheInputPriceForACachePriceLeftOut(): void
    {
        file_put_contents($this->file, "budget:\n    ceilings:\n        five-hour: 80\n    prices:\n        other: { input: 2, output: 8 }\nprojects:\n    beta:\n        tiers:\n            strong: { provider: openai, model: other }\n");

        self::assertSame(['other' => ['input' => 2.0, 'output' => 8.0, 'cache_read' => 2.0, 'cache_write' => 2.0]], $this->bootedContainer()->getParameter(BudgetServices::PRICES));
    }

    public function testTheStartGuardOfTheContainerRefusesAStartAtTheOwnersCeiling(): void
    {
        file_put_contents($this->file, "budget:\n    ceilings:\n        five-hour: 80\n");
        $guard = $this->bootedStartGuard();

        $this->measureBothWindowsAt(85.0);

        self::assertSame(StartDecision::AtCeiling, $guard->decide(new Account(Provider::Anthropic, 'default'), 0));
    }

    public function testTheStartGuardOfTheContainerStartsBelowTheOwnersCeiling(): void
    {
        file_put_contents($this->file, "budget:\n    ceilings:\n        five-hour: 80\n");
        $guard = $this->bootedStartGuard();

        $this->measureBothWindowsAt(75.0);

        self::assertSame(StartDecision::Start, $guard->decide(new Account(Provider::Anthropic, 'default'), 0));
    }

    public function testAShadowCeilingsSectionStopsTheBoot(): void
    {
        file_put_contents($this->file, "budget:\n    shadow_ceilings:\n        weekly: 50\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/Unrecognized option "shadow_ceilings" under "intakt\.budget"/');

        $this->bootedContainer();
    }

    public function testTheCodexProbeOfTheContainerUsesTheOwnersCodexSection(): void
    {
        file_put_contents($this->file, "codex: { model: gpt-6.2-sol, effort: high }\n");
        $this->bootedContainer();
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('intakt:codex:probe'));

        $tester->execute(['account' => 'wiring']);

        self::assertStringContainsString('gpt-6.2-sol', $tester->getDisplay());
    }

    public function testACeilingAboveAHundredPercentStopsTheBoot(): void
    {
        file_put_contents($this->file, "budget:\n    ceilings:\n        weekly: 120\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/intakt\.budget\.ceilings\.weekly/');

        $this->bootedContainer();
    }

    public function testTheTierPlatformReachesTheProjectModelsThroughTheBundleModelCatalog(): void
    {
        $platforms = $this->bootedContainer()->get(AiServices::PROVIDER_PLATFORMS);
        self::assertInstanceOf(ServiceProviderInterface::class, $platforms);
        self::assertTrue($platforms->has(Provider::Anthropic->value));
        $anthropic = $platforms->get(Provider::Anthropic->value);
        self::assertInstanceOf(PlatformInterface::class, $anthropic);

        self::assertSame([Provider::Anthropic->value], array_keys($platforms->getProvidedServices()));
        self::assertInstanceOf(Claude::class, $anthropic->getModelCatalog()->getModel('big'));
        self::assertInstanceOf(TierPlatform::class, self::getContainer()->get(TierPlatform::class));
    }

    public function testTheAccountsOfAProviderAreTriedInTheListedOrderEachOnItsOwnPlatform(): void
    {
        $this->listAccounts('anthropic: [team, default]');
        $_SERVER['ANTHROPIC_API_KEY_TEAM'] = 'test-value';

        $container = $this->bootedContainer();
        $platforms = $container->get(AiServices::PROVIDER_PLATFORMS);
        self::assertInstanceOf(ServiceProviderInterface::class, $platforms);
        self::assertTrue($platforms->has(Provider::Anthropic->value));
        $anthropic = $platforms->get(Provider::Anthropic->value);

        self::assertSame(['team', 'default'], $this->accountsOf($container)[Provider::Anthropic->value]);
        self::assertInstanceOf(AccountFailoverPlatform::class, $anthropic);
        self::assertInstanceOf(Claude::class, $anthropic->getModelCatalog()->getModel('big'));
    }

    public function testAnAccountWithoutItsKeyIsLeftOutWithAMessageWhileItsProviderStaysEnabled(): void
    {
        $this->listAccounts('anthropic: [default, team]');

        $container = $this->bootedContainer();

        self::assertSame(['default'], $this->accountsOf($container)[Provider::Anthropic->value]);
        self::assertSame(['Account "team" of provider "anthropic" is disabled: set ANTHROPIC_API_KEY_TEAM to enable it.'], $container->getParameter(AiServices::DISABLED_ACCOUNTS));
        self::assertArrayNotHasKey(Provider::Anthropic->value, $container->getParameter(AiServices::DISABLED_PROVIDERS));
    }

    public function testAProviderWhoseEveryListedAccountLacksItsKeyIsDisabled(): void
    {
        $this->listAccounts('anthropic: [team]');

        $disabled = $this->bootedContainer()->getParameter(AiServices::DISABLED_PROVIDERS);

        self::assertSame('Provider "anthropic" is disabled: set ANTHROPIC_API_KEY_TEAM to enable it.', $disabled[Provider::Anthropic->value]);
    }

    /**
     * @return iterable<string, array{Provider, list<string>, string}>
     */
    public static function otherAccounts(): iterable
    {
        yield 'openai' => [Provider::OpenAi, ['OPENAI_API_KEY_TEAM'], 'other'];
        yield 'openai_compatible' => [Provider::OpenAiCompatible, ['OPENAI_COMPATIBLE_BASE_URL_TEAM', 'OPENAI_COMPATIBLE_API_KEY_TEAM'], 'local'];
        yield 'claude_code' => [Provider::ClaudeCode, ['CLAUDE_CODE_OAUTH_TOKEN_TEAM'], 'sonnet'];
    }

    /**
     * @param list<string>     $variables
     * @param non-empty-string $model
     */
    #[DataProvider('otherAccounts')]
    public function testAnotherAccountOfEachProviderGetsAPlatformThatKnowsTheProjectModels(Provider $provider, array $variables, string $model): void
    {
        file_put_contents($this->file, "projects:\n    gamma:\n        tiers:\n            strong: { provider: {$provider->value}, model: {$model} }\naccounts:\n    {$provider->value}: [team]\n");
        foreach ($variables as $variable) {
            // Prefixed because the OpenAI bridge refuses a key without its prefix.
            $_SERVER[$variable] = str_contains($variable, 'BASE_URL') ? 'http://localhost:8080' : 'sk-test-value';
        }

        $platforms = $this->bootedContainer()->get(AiServices::PROVIDER_PLATFORMS);
        self::assertInstanceOf(ServiceProviderInterface::class, $platforms);
        $platform = $platforms->get($provider->value);

        self::assertInstanceOf(AccountFailoverPlatform::class, $platform);
        self::assertSame($model, $platform->getModelCatalog()->getModel($model)->getName());
    }

    public function testWithItsSectionEveryListedCodexAccountGetsAPlatformOfItsOwn(): void
    {
        file_put_contents($this->file, <<<'YAML'
            codex: { model: gpt-6.2-sol, effort: high }
            accounts:
                codex: [default, team]
            projects:
                alpha-one:
                    tiers:
                        strong: { provider: codex, model: gpt-6.2-sol }
            YAML);

        $container = $this->bootedContainer();

        self::assertInstanceOf(PlatformInterface::class, $container->get(Provider::Codex->platformId(new Account(Provider::Codex, Account::DEFAULT))));
        self::assertInstanceOf(PlatformInterface::class, $container->get(Provider::Codex->platformId(new Account(Provider::Codex, 'team'))));
        self::assertSame(['default', 'team'], $this->parameterList($container, AiServices::ACCOUNTS)['codex'] ?? null);
        self::assertArrayNotHasKey('codex', $this->parameterList($container, AiServices::DISABLED_PROVIDERS));
    }

    public function testWithoutItsSectionCodexIsLeftOutWithoutADisabledMessage(): void
    {
        $container = $this->bootedContainer();

        self::assertFalse($container->has(Provider::Codex->platformId(new Account(Provider::Codex, Account::DEFAULT))));
        self::assertArrayNotHasKey('codex', $this->parameterList($container, AiServices::ACCOUNTS));
        self::assertArrayNotHasKey('codex', $this->parameterList($container, AiServices::DISABLED_PROVIDERS));
    }

    protected function setUp(): void
    {
        $this->containers = self::compiledContainers();

        foreach (self::KEYS as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-projects-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->file, <<<'YAML'
            projects:
                alpha-one:
                    mode: reviewer-for-loop
                    tiers:
                        strong: { provider: anthropic, model: big }
                        light: { provider: anthropic, model: small }
                    review:
                        quorum: all
                        reviewers:
                            - { role: correctness, tier: strong, provider: anthropic, prompt: Check the logic., budget: 3 }
                    watch: { repository: repos/alpha, base: main, task: Review the pull request. }
                beta:
                    tiers:
                        strong: { provider: openai, model: other }
            YAML);
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';
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

    private function bootedStartGuard(): StartGuard
    {
        $guard = $this->bootedContainer()->get('test.intakt.start_guard');
        self::assertInstanceOf(StartGuard::class, $guard);
        $this->emptyDatabase();

        return $guard;
    }

    private function measureBothWindowsAt(float $percent): void
    {
        $measured = self::getContainer()->get(MeasuredUse::class);
        self::assertInstanceOf(MeasuredUse::class, $measured);
        foreach (UsageWindow::cases() as $window) {
            self::assertSame(ReadingOutcome::Recorded, $measured->record(new WindowReading(new Account(Provider::Anthropic, 'default'), $window, new Usage($percent, null))));
        }
    }

    private function emptyDatabase(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
    }

    private function listAccounts(string $line): void
    {
        file_put_contents($this->file, "\naccounts:\n    {$line}\n", FILE_APPEND);
    }

    /**
     * @return array<string, list<string>>
     */
    private function accountsOf(ContainerInterface $container): array
    {
        /** @var array<string, list<string>> $accounts */
        $accounts = $container->getParameter(AiServices::ACCOUNTS);

        return $accounts;
    }

    private function bootedPanels(): ConfiguredPanels
    {
        // Nothing injects the loader yet, so the compiled container drops it; the parameter is what the config feeds it.
        /** @var array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}> $parameter */
        $parameter = $this->bootedContainer()->getParameter(ReviewServices::PANELS);

        return new ConfiguredPanels($parameter);
    }

    /**
     * @return array<mixed>
     */
    private function parameterList(ContainerInterface $container, string $name): array
    {
        $parameter = $container->getParameter($name);
        self::assertIsArray($parameter);

        return $parameter;
    }

    private function bootedContainer(): ContainerInterface
    {
        self::ensureKernelShutdown();
        // Keyed by the config and keys, because the container is built from those alone.
        $this->containers->useFor($this->containerInputs());
        self::bootKernel();

        return self::getContainer();
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'project-tiers');
    }

    // The keys without INTAKT_CONFIG, because its path changes per test while the file's content is what the container is built from.
    /** @return array<mixed> */
    private function containerInputs(): array
    {
        $keys = array_map(static fn (string $name): mixed => $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name), array_diff(self::KEYS, ['INTAKT_CONFIG']));

        return [(string) file_get_contents($this->file), $keys];
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
