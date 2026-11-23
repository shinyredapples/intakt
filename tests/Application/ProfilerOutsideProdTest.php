<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// Covers nothing because the behavior is in config/packages/framework.yaml, which is not a class.
#[CoversNothing]
final class ProfilerOutsideProdTest extends TestCase
{
    private string $cache;

    private mixed $sharedCache;

    private ?Kernel $kernel = null;

    public function testTheProductionContainerHasNoProfilerService(): void
    {
        $this->kernel = new Kernel('prod', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        self::assertTrue($container->has('router'));
        self::assertFalse($container->has('profiler'));
        self::assertFalse($container->has('profiler_listener'));
    }

    protected function setUp(): void
    {
        $this->sharedCache = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->cache = sys_get_temp_dir().'/intakt-profiler-outside-prod-'.bin2hex(random_bytes(6));
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
}
