<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\SampleProjectData;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

// Covers nothing because what is proven is the wiring: the seed command in the container, and its file read back through config/packages/ai.php.
#[CoversNothing]
final class SampleProjectSeedTest extends KernelTestCase
{
    private const array VARIABLES = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'INTAKT_CONFIG'];

    private string $directory = '';

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

        $this->directory = sys_get_temp_dir().'/intakt-sample-project-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        // An empty file, so the seeding kernel has no projects of a shipped config/intakt.yaml.
        $_SERVER['INTAKT_CONFIG'] = $this->directory.'/none.yaml';
        new Filesystem()->dumpFile($_SERVER['INTAKT_CONFIG'], '');
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
        new Filesystem()->remove($this->directory);
    }

    public function testWithAnAnthropicKeyAndTheHuggingFaceRouterTheSeededPanelLoadsOnBothAndEveryTierResolvesToAnEnabledProvider(): void
    {
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';
        $this->setTheHuggingFaceRouter();

        $this->seed();

        self::assertSame([Provider::Anthropic, Provider::OpenAiCompatible], $this->seededPanelProviders());
        $resolver = $this->bootSeeded()->get(TierResolver::class);
        self::assertInstanceOf(TierResolver::class, $resolver);
        $expected = [Tier::Strong->value => Provider::Anthropic, Tier::Standard->value => Provider::OpenAiCompatible, Tier::Light->value => Provider::Anthropic];
        foreach (Tier::cases() as $tier) {
            self::assertSame($expected[$tier->value], $resolver->resolve('sample', $tier)->provider);
        }
    }

    public function testASeededInstallPricesTheModelsOfItsTiersSoACostIsAFigure(): void
    {
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';
        $this->setTheHuggingFaceRouter();
        $this->seed();

        $container = $this->bootSeeded();
        $prices = $container->get(Prices::class);
        $resolver = $container->get(TierResolver::class);
        self::assertInstanceOf(Prices::class, $prices);
        self::assertInstanceOf(TierResolver::class, $resolver);
        foreach (Tier::cases() as $tier) {
            $cost = $prices->costOf($resolver->resolve('sample', $tier)->model, new TokenFigures(1000, 0, 0, 0, 100));
            self::assertNotNull($cost->usd);
        }
    }

    public function testWithoutAProviderKeyTheSeedSucceedsWithTheRepositoryAndNoProjectSoTheStartGoesOn(): void
    {
        $this->seed();

        self::assertDirectoryExists($this->directory.'/sample-repository/.git');
        self::assertFileDoesNotExist($this->directory.'/intakt.yaml');
    }

    // Set directly, because HF_TOKEN becomes these two only in the image's entrypoint, which this test does not run.
    private function setTheHuggingFaceRouter(): void
    {
        $_SERVER['OPENAI_COMPATIBLE_BASE_URL'] = new SampleProjectData()->routerUrl()->toRawString();
        $_SERVER['OPENAI_COMPATIBLE_API_KEY'] = 'test-value';
    }

    private function seed(): void
    {
        $this->containers->useFor($this->buildInputs());
        $tester = new CommandTester(new Application(self::bootKernel())->find('intakt:sample:seed'));

        $tester->execute(['repository' => $this->directory.'/sample-repository', 'configuration' => $this->directory.'/intakt.yaml']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * @return list<Provider>
     */
    private function seededPanelProviders(): array
    {
        $panels = $this->bootSeeded()->get(Panels::class);
        self::assertInstanceOf(Panels::class, $panels);

        $panel = $panels->panelForChange('sample', new ChangeDescription(1, new Paths(), new Labels()));

        return array_map(static fn (PanelReviewer $reviewer): Provider => $reviewer->model->provider, $panel->reviewers);
    }

    private function bootSeeded(): ContainerInterface
    {
        self::ensureKernelShutdown();
        $_SERVER['INTAKT_CONFIG'] = $this->directory.'/intakt.yaml';
        // A container of its own, because the seeding one was built before the file existed.
        $this->containers->useFor($this->buildInputs());

        return self::getContainer();
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'sample-project-seed');
    }

    // The config's content rather than its path, because the path changes per test while the content is what the container is built from.
    /** @return array<mixed> */
    private function buildInputs(): array
    {
        $keys = array_map(static fn (string $name): array => [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)], array_diff(self::VARIABLES, ['INTAKT_CONFIG']));

        return [(string) file_get_contents($this->configuration()), $keys];
    }

    private function configuration(): string
    {
        $configuration = $_SERVER['INTAKT_CONFIG'];
        self::assertIsString($configuration);

        return $configuration;
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
