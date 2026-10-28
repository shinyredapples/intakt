<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolver;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

// Covers nothing because the behavior is in config/packages/intakt.php, which is not a class.
#[CoversNothing]
final class ProjectTiersTest extends KernelTestCase
{
    private const array KEYS = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY', 'INTAKT_CONFIG'];

    private string $file = '';

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $name) {
            $this->saved[$name] = $_SERVER[$name] ?? null;
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }

        $this->file = sys_get_temp_dir().'/intakt-projects-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->file, <<<'YAML'
            projects:
                alpha:
                    tiers:
                        strong: { provider: anthropic, model: big }
                        light: { provider: anthropic, model: small }
                beta:
                    tiers:
                        strong: { provider: openai, model: other }
            YAML);
        $_SERVER['INTAKT_CONFIG'] = $this->file;
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-value';
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            unset($_SERVER[$name]);
            if (null !== $value) {
                $_SERVER[$name] = $value;
            }
        }

        parent::tearDown();
        new Filesystem()->remove([$this->file, self::createKernel()->getCacheDir()]);
    }

    public function testTheContainerBuildsWithTwoProjectsThatMapTiersDifferently(): void
    {
        new Filesystem()->remove(self::createKernel()->getCacheDir());
        self::ensureKernelShutdown();
        self::bootKernel();
        $resolver = self::getContainer()->get(TierResolver::class);
        self::assertInstanceOf(TierResolver::class, $resolver);

        self::assertSame(['alpha', 'beta'], $resolver->projects());
        self::assertSame('small', $resolver->resolve('alpha', Tier::Light)->model);
        self::assertSame(Provider::Anthropic, $resolver->resolve('alpha', Tier::Strong)->provider);

        $this->expectException(TierProviderDisabled::class);
        $resolver->resolve('beta', Tier::Strong);
    }
}
