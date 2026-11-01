<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use Intakt\Core\Shadow\Infrastructure\ShadowGuardedForgeWrites;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ServiceProviderInterface;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class.
#[CoversNothing]
final class ProjectTiersTest extends KernelTestCase
{
    private const array KEYS = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'INTAKT_CONFIG',
        'ANTHROPIC_API_KEY_TEAM', 'OPENAI_API_KEY_TEAM', 'OPENAI_COMPATIBLE_BASE_URL_TEAM', 'OPENAI_COMPATIBLE_API_KEY_TEAM', 'CLAUDE_CODE_OAUTH_TOKEN_TEAM',
    ];

    private string $file = '';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-projects-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->file, <<<'YAML'
            projects:
                alpha-one:
                    mode: shadow
                    tiers:
                        strong: { provider: anthropic, model: big }
                        light: { provider: anthropic, model: small }
                    review:
                        quorum: all
                        reviewers:
                            - { role: correctness, tier: strong, provider: anthropic, prompt: Check the logic., budget: 3 }
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
        new Filesystem()->remove([$this->file, self::createKernel()->getCacheDir()]);
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

    public function testAShadowProjectsReviewNeverReachesTheForgeTheContainerWritesTo(): void
    {
        $forge = $this->bootedContainer()->get(ForgeWrites::class);
        self::assertInstanceOf(ForgeWrites::class, $forge);

        $forge->postReview('alpha-one', new ChangeId('feature'), 'approve');

        self::assertInstanceOf(ShadowGuardedForgeWrites::class, $forge);
    }

    public function testAProjectWithoutAModeWritesToTheForgeTheContainerWritesTo(): void
    {
        $forge = $this->bootedContainer()->get(ForgeWrites::class);
        self::assertInstanceOf(ForgeWrites::class, $forge);

        $this->expectExceptionObject(new LogicException('The test forge refuses every write.'));

        $forge->postReview('beta', new ChangeId('feature'), 'approve');
    }

    public function testAnUnknownProjectModeStopsTheBoot(): void
    {
        file_put_contents($this->file, "projects:\n    beta:\n        mode: quiet\n        tiers:\n            strong: { provider: openai, model: other }\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/"quiet" is not allowed for path "intakt\.projects\.beta\.mode"/');

        $this->bootedContainer();
    }

    public function testAProjectsValueThatIsNoMapStopsTheBoot(): void
    {
        file_put_contents($this->file, "projects: broken\n");

        $this->expectException(LoaderLoadException::class);
        $this->expectExceptionMessageMatches('/Invalid type for path "intakt\.projects"/');

        $this->bootedContainer();
    }

    public function testTheTierPlatformReachesTheProjectModelsThroughTheBundleModelCatalog(): void
    {
        $platforms = $this->bootedContainer()->get(TierPlatform::PLATFORMS);
        self::assertInstanceOf(ServiceProviderInterface::class, $platforms);
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
        $platforms = $container->get(TierPlatform::PLATFORMS);
        self::assertInstanceOf(ServiceProviderInterface::class, $platforms);
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
        self::assertSame(['Account "team" of provider "anthropic" is disabled: set ANTHROPIC_API_KEY_TEAM to enable it.'], $container->getParameter('intakt.ai.disabled_accounts'));
        self::assertArrayNotHasKey(Provider::Anthropic->value, (array) $container->getParameter('intakt.ai.disabled_providers'));
    }

    public function testAProviderWhoseEveryListedAccountLacksItsKeyIsDisabled(): void
    {
        $this->listAccounts('anthropic: [team]');

        $disabled = (array) $this->bootedContainer()->getParameter('intakt.ai.disabled_providers');

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

        $platforms = $this->bootedContainer()->get(TierPlatform::PLATFORMS);
        self::assertInstanceOf(ServiceProviderInterface::class, $platforms);
        $platform = $platforms->get($provider->value);

        self::assertInstanceOf(AccountFailoverPlatform::class, $platform);
        self::assertSame($model, $platform->getModelCatalog()->getModel($model)->getName());
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
        $accounts = $container->getParameter('intakt.ai.accounts');

        return $accounts;
    }

    private function bootedPanels(): ConfiguredPanels
    {
        // Nothing injects the loader yet, so the compiled container drops it; the parameter is what the config feeds it.
        /** @var array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}> $parameter */
        $parameter = $this->bootedContainer()->getParameter('intakt.review.panels');

        return new ConfiguredPanels($parameter);
    }

    private function bootedContainer(): ContainerInterface
    {
        new Filesystem()->remove(self::createKernel()->getCacheDir());
        self::ensureKernelShutdown();
        self::bootKernel();

        return self::getContainer();
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
