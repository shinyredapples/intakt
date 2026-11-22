<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Server\Infrastructure\ProfileOnlyRequestMeasuresPass;
use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\ContainerInterface;
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

    public function testAnOptedInTestProfileWithoutDebugCountsAQueryRunDuringItsRequest(): void
    {
        $kernel = new Kernel('test', false);
        $this->kernel = $kernel;
        $kernel->boot();
        $this->createSchemaUncounted($kernel);
        $client = new KernelBrowser($kernel);
        $client->enableProfiler();

        $client->request('GET', '/status', server: ['HTTP_HOST' => 'localhost:7780']);

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $collector->getQueryCount());
    }

    // The holder is reset after the schema is created, because otherwise its statements would count as the request's queries.
    private function createSchemaUncounted(Kernel $kernel): void
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $manager = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        new SchemaTool($manager)->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $holder = $container->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
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
