<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

// The profiler is a development tool: dev and test hold it to measure a request, the production container must not hold it at all (#2721, D1).
#[CoversNothing]
final class ProfilerOutsideProdTest extends KernelTestCase
{
    private const string PROFILER = 'profiler';

    public function testTheTestContainerHoldsTheProfiler(): void
    {
        self::assertTrue(self::getContainer()->has(self::PROFILER));
    }

    public function testTheProductionContainerHoldsNoProfilerService(): void
    {
        $this->withTheProductionContainer(static function (ContainerInterface $container): void {
            self::assertTrue($container->has('router'));
            self::assertFalse($container->has(self::PROFILER));
            self::assertFalse($container->has('profiler_listener'));
        });
    }

    // A cache directory of its own, because a kernel without debug never rebuilds a container compiled from an earlier configuration.
    /**
     * @param callable(ContainerInterface): void $check
     */
    private function withTheProductionContainer(callable $check): void
    {
        $cache = dirname(__DIR__, 2).'/var/cache/profiler-prod-'.bin2hex(random_bytes(8));
        $previous = $_SERVER['APP_CACHE_DIR'] ?? null;
        $_SERVER['APP_CACHE_DIR'] = $cache;
        $kernel = new Kernel('prod', false);

        try {
            $kernel->boot();
            $check($kernel->getContainer());
        } finally {
            $kernel->shutdown();
            if (null === $previous) {
                unset($_SERVER['APP_CACHE_DIR']);
            } else {
                $_SERVER['APP_CACHE_DIR'] = $previous;
            }
            new Filesystem()->remove($cache);
        }
    }
}
