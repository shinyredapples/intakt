<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\Server\Infrastructure\ProfileOnlyRequestMeasuresPass;
use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[CoversClass(ProfileOnlyRequestMeasuresPass::class)]
#[UsesClass(Kernel::class)]
final class ProfilerAllowListTest extends TestCase
{
    private string $cache;

    private mixed $sharedCache;

    /**
     * @var array{mixed, mixed}
     */
    private array $sharedDatabase = [null, null];

    private ?Kernel $kernel = null;

    public function testASavedDevelopmentProfileHoldsExactlyTheCollectorsThatMeasureARequest(): void
    {
        self::assertSame(['db', 'memory', 'messenger', 'time'], $this->collectorsOfASavedProfile(new Kernel('dev', true)));
    }

    public function testASavedDevelopmentProfileWithoutDebugHoldsTheSameCollectors(): void
    {
        self::assertSame(['db', 'memory', 'messenger', 'time'], $this->collectorsOfASavedProfile(new Kernel('dev', false)));
    }

    public function testAnOptedInTestProfileWithoutDebugHoldsTheSameCollectors(): void
    {
        $profile = $this->optedInTestProfile(new Kernel('test', false));

        $collectors = array_keys($profile->getCollectors());
        sort($collectors);
        self::assertSame(['db', 'memory', 'messenger', 'time'], $collectors);
    }

    public function testAnOptedInTestProfileWithoutDebugCountsTheQueriesOfItsRequest(): void
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $connection = $doctrine->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeQuery('SELECT 1');

        $collector = $this->optedInTestProfile($kernel)->getCollector('db');

        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertGreaterThan(0, $collector->getQueryCount());
    }

    protected function setUp(): void
    {
        $this->sharedCache = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->cache = sys_get_temp_dir().'/intakt-profiler-allow-list-'.bin2hex(random_bytes(6));
        $_SERVER['APP_CACHE_DIR'] = $this->cache;
        $this->sharedDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        // In memory, because a fresh checkout has no var/ directory for the environment's database file.
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']] = $this->sharedDatabase;
        if (null === $_SERVER['DATABASE_URL']) {
            unset($_SERVER['DATABASE_URL']);
        }
        if (null === $_ENV['DATABASE_URL']) {
            unset($_ENV['DATABASE_URL']);
        }
        unset($_SERVER['APP_CACHE_DIR']);
        if (null !== $this->sharedCache) {
            $_SERVER['APP_CACHE_DIR'] = $this->sharedCache;
        }
        new Filesystem()->remove($this->cache);
    }

    private function optedInTestProfile(Kernel $kernel): Profile
    {
        $this->kernel = $kernel;
        $kernel->boot();
        $client = new KernelBrowser($kernel);
        $client->enableProfiler();

        $client->request('POST', '/mcp/review');

        self::assertSame(401, $client->getResponse()->getStatusCode());
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
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
