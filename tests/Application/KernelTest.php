<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Mcp\Infrastructure\AuthorizeToolCallsPass;
use Intakt\Kernel;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(Kernel::class)]
final class KernelTest extends KernelTestCase
{
    private const string SCHEMA_EDITOR_DEPRECATION = 'https://github.com/doctrine/orm/pull/12780';

    public function testTheKernelBootsWithTheAiAndMcpBundles(): void
    {
        $kernel = self::bootKernel();

        self::assertArrayHasKey('AiBundle', $kernel->getBundles());
        self::assertInstanceOf(AiBundle::class, $kernel->getBundle('AiBundle'));
        self::assertInstanceOf(McpBundle::class, $kernel->getBundle('McpBundle'));
    }

    public function testTheApplicationPassesAuthorizeToolCallsBeforeOptimization(): void
    {
        $container = new ContainerBuilder();
        $earlier = self::createStub(CompilerPassInterface::class);
        $later = self::createStub(CompilerPassInterface::class);
        $container->addCompilerPass($earlier, PassConfig::TYPE_BEFORE_OPTIMIZATION, -9);
        $container->addCompilerPass($later, PassConfig::TYPE_BEFORE_OPTIMIZATION, -11);

        Kernel::addApplicationPasses($container);

        $passes = array_map(get_class(...), $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        $position = array_search(AuthorizeToolCallsPass::class, $passes, true);
        self::assertIsInt($position);
        self::assertSame($earlier::class, $passes[$position - 1]);
        self::assertSame($later::class, $passes[$position + 1]);
    }

    public function testTheSchemaToolOfTheEntityManagerTriggersNoSchemaEditorDeprecation(): void
    {
        $entities = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        // Left on for the rest of the process on purpose, because tracking only counts deprecations and no later test reads or resets the count.
        Deprecation::enableTrackingDeprecations();
        $before = Deprecation::getTriggeredDeprecations()[self::SCHEMA_EDITOR_DEPRECATION] ?? 0;

        new SchemaTool($entities);

        self::assertSame($before, Deprecation::getTriggeredDeprecations()[self::SCHEMA_EDITOR_DEPRECATION] ?? 0);
    }

    public function testTheDefaultDatabaseIsSqlite(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? null;

        self::assertIsString($url);
        self::assertStringStartsWith('sqlite:', $url);
    }
}
