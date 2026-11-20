<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Server\Infrastructure\ProfileOnlyRequestMeasuresPass;
use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[CoversClass(ProfileOnlyRequestMeasuresPass::class)]
#[UsesClass(Kernel::class)]
final class ProfilerAllowListTest extends TestCase
{
    private string $cache;

    private mixed $sharedCache;

    private ?Kernel $kernel = null;

    protected function setUp(): void
    {
        $this->sharedCache = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->cache = sys_get_temp_dir().'/intakt-profiler-allow-list-'.bin2hex(random_bytes(6));
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

    public function testASavedDevelopmentProfileHoldsExactlyTheCollectorsThatMeasureARequest(): void
    {
        self::assertSame(['db', 'memory', 'messenger', 'time'], $this->collectorsOfASavedProfile(new Kernel('dev', true)));
    }

    public function testASavedDevelopmentProfileWithoutDebugHoldsTheSameCollectors(): void
    {
        self::assertSame(['db', 'memory', 'messenger', 'time'], $this->collectorsOfASavedProfile(new Kernel('dev', false)));
    }

    /**
     * @return list<string>
     */
    private function collectorsOfASavedProfile(Kernel $kernel): array
    {
        $this->kernel = $kernel;
        $request = Request::create('/mcp/review', 'POST');

        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        $profiler = $this->kernel->getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $profile = $profiler->loadProfile((string) $response->headers->get('X-Debug-Token'));
        self::assertNotNull($profile);
        $collectors = array_keys($profile->getCollectors());
        sort($collectors);

        return $collectors;
    }
}
