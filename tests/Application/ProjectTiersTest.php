<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\ServiceProviderInterface;

// Covers nothing because the behavior is in config/packages/ai.php, which is not a class.
#[CoversNothing]
final class ProjectTiersTest extends KernelTestCase
{
    private const array KEYS = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'INTAKT_CONFIG'];

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

    public function testAProjectReviewSectionLoadsAsAPanelAndLeavesTheTiersStrict(): void
    {
        // Nothing injects the loader yet, so the compiled container drops it; the parameter is what the config feeds it.
        /** @var array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}> $parameter */
        $parameter = $this->bootedContainer()->getParameter('intakt.review.panels');
        $panels = new ConfiguredPanels($parameter);

        self::assertSame('correctness', $panels->panelFor('alpha-one')->reviewers[0]->role);
        $this->expectException(PanelNotConfigured::class);
        $panels->panelFor('beta');
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
