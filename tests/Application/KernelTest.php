<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

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

    public function testTheDefaultDatabaseIsSqlite(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? null;

        self::assertIsString($url);
        self::assertStringStartsWith('sqlite:', $url);
    }
}
