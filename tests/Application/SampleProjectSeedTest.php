<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
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
    private const array VARIABLES = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'INTAKT_CONFIG'];

    private string $directory = '';

    /** @var array<string, array{server: mixed, env: mixed, process: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            $this->unsetVariable($name);
        }

        $this->directory = sys_get_temp_dir().'/intakt-sample-project-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
        // An empty file, so the seeding kernel has no projects of a shipped config/intakt.yaml.
        $_SERVER['INTAKT_CONFIG'] = $this->directory.'/none.yaml';
        new Filesystem()->dumpFile($_SERVER['INTAKT_CONFIG'], '');
        $this->removeCache();
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
        new Filesystem()->remove($this->directory);
        $this->removeCache();
    }

    public function testWithOneKeyTheSeededPanelLoadsOnThatProvider(): void
    {
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';

        $this->seed();

        self::assertSame([Provider::Anthropic, Provider::Anthropic], $this->seededPanelProviders());
    }

    public function testWithTwoKeysTheSeededPanelLoadsOnTwoProviders(): void
    {
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';
        $_SERVER['OPENAI_API_KEY'] = 'test-value';

        $this->seed();

        self::assertSame([Provider::Anthropic, Provider::OpenAi], $this->seededPanelProviders());
    }

    public function testEveryTierOfTheSeededProjectResolvesToAnEnabledProvider(): void
    {
        $_SERVER['OPENAI_API_KEY'] = 'test-value';

        $this->seed();

        $resolver = $this->bootSeeded()->get(TierResolver::class);
        self::assertInstanceOf(TierResolver::class, $resolver);
        foreach (Tier::cases() as $tier) {
            self::assertSame(Provider::OpenAi, $resolver->resolve('sample', $tier)->provider);
        }
    }

    private function seed(): void
    {
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

        $panel = $panels->panelForChange('sample', new ChangeDescription(1, [], []));

        return array_map(static fn (PanelReviewer $reviewer): Provider => $reviewer->model->provider, $panel->reviewers);
    }

    private function bootSeeded(): ContainerInterface
    {
        self::ensureKernelShutdown();
        // Removed because the container is built once per environment, and the seeded file must be read into a new one.
        $this->removeCache();
        $_SERVER['INTAKT_CONFIG'] = $this->directory.'/intakt.yaml';

        return self::getContainer();
    }

    private function removeCache(): void
    {
        new Filesystem()->remove(self::createKernel()->getCacheDir());
    }

    private function unsetVariable(string $name): void
    {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}
