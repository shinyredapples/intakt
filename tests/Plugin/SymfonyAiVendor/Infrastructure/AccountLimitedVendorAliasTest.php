<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Kernel;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AccountLimitedVendor;
use Intakt\Plugin\Vendor\Contract\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

// A development kernel, because every test container imports tests/Journeys/services.yaml, which points Vendor at the journey's vendor instead.
#[CoversClass(AccountLimitedVendor::class)]
final class AccountLimitedVendorAliasTest extends TestCase
{
    private string $cache;

    private mixed $sharedCache;

    private ?Kernel $kernel = null;

    public function testOutsideTheTestEnvironmentTheVendorContractIsTheAccountLimitedVendor(): void
    {
        $this->kernel = new Kernel('dev', true);
        $this->kernel->boot();

        self::assertSame(AccountLimitedVendor::class, (string) $this->compiledWiring()->getAlias(Vendor::class));
    }

    protected function setUp(): void
    {
        $this->sharedCache = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->cache = sys_get_temp_dir().'/intakt-vendor-alias-'.bin2hex(random_bytes(6));
        $_SERVER['APP_CACHE_DIR'] = $this->cache;
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        unset($_SERVER['APP_CACHE_DIR']);
        if (null !== $this->sharedCache) {
            $_SERVER['APP_CACHE_DIR'] = $this->sharedCache;
        }
        new Filesystem()->remove($this->cache);
    }

    // Read from the serialized builder debug:container reads beside its dump, because the compiled container drops private aliases such as this one.
    private function compiledWiring(): ContainerBuilder
    {
        $dump = $this->kernel?->getContainer()->getParameter('debug.container.dump');
        self::assertIsString($dump);
        $wiring = unserialize(new Filesystem()->readFile(substr_replace($dump, '.ser', -4)));
        self::assertInstanceOf(ContainerBuilder::class, $wiring);

        return $wiring;
    }
}
