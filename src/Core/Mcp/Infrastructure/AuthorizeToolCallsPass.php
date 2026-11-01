<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Infrastructure;

use Mcp\Capability\Registry\ReferenceHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

// Runs after the bundle's own pass, which is the one that gives each server builder its handler locator.
final class AuthorizeToolCallsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('mcp.server.builder')) as $builderId) {
            $this->authorize($container, $container->getDefinition($builderId), $builderId);
        }
    }

    private function authorize(ContainerBuilder $container, Definition $builder, string $builderId): void
    {
        $locator = $this->locator($builder);

        if (null === $locator) {
            return;
        }

        $handlerId = $builderId.'.authorized_tool_calls';
        $container->register($handlerId, AuthorizedToolCalls::class)
            ->setArguments([new Definition(ReferenceHandler::class, [$locator]), new Reference('security.authorization_checker')]);

        $builder->addMethodCall('setReferenceHandler', [new Reference($handlerId)]);
    }

    private function locator(Definition $builder): mixed
    {
        foreach ($builder->getMethodCalls() as [$method, $arguments]) {
            if ('setContainer' === $method) {
                return $arguments[0];
            }
        }

        return null;
    }
}
