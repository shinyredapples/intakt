<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Mcp\Infrastructure;

use Intakt\Core\Mcp\Infrastructure\AuthorizedToolCalls;
use Intakt\Core\Mcp\Infrastructure\AuthorizeToolCallsPass;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(AuthorizeToolCallsPass::class)]
final class AuthorizeToolCallsPassTest extends TestCase
{
    public function testAServerWithAHandlerLocatorGetsTheWrapperOverThatLocator(): void
    {
        $container = $this->containerWithServer();
        $container->getDefinition('mcp.server.owner.builder')->addMethodCall('setContainer', [new Reference('owner.locator')]);

        new AuthorizeToolCallsPass()->process($container);

        self::assertEquals(new Reference('owner.locator'), $this->innerLocator($container));
    }

    public function testAServerWithoutAHandlerLocatorIsWrappedAllTheSame(): void
    {
        $container = $this->containerWithServer();

        new AuthorizeToolCallsPass()->process($container);

        self::assertNull($this->innerLocator($container));
    }

    private function containerWithServer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('mcp.server.owner.builder', Builder::class)->addTag('mcp.server.builder', ['server' => 'owner']);

        return $container;
    }

    private function innerLocator(ContainerBuilder $container): mixed
    {
        $calls = $container->getDefinition('mcp.server.owner.builder')->getMethodCalls();
        $handlerCall = array_last($calls);
        self::assertIsArray($handlerCall);
        self::assertSame('setReferenceHandler', $handlerCall[0]);
        self::assertEquals([new Reference('mcp.server.owner.builder.authorized_tool_calls')], $handlerCall[1]);

        $wrapper = $container->getDefinition('mcp.server.owner.builder.authorized_tool_calls');
        self::assertSame(AuthorizedToolCalls::class, $wrapper->getClass());
        $inner = $wrapper->getArgument(0);
        self::assertInstanceOf(Definition::class, $inner);

        return $inner->getArgument(0);
    }
}
