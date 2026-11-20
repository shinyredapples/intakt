<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\ProfilerCollectorAllowListPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[CoversClass(ProfilerCollectorAllowListPass::class)]
final class ProfilerCollectorAllowListPassTest extends TestCase
{
    public function testItRemovesTheCollectorTagOfACollectorOutsideTheAllowList(): void
    {
        $container = $this->containerWithCollectors();
        $container->register('profiler', Profiler::class);

        new ProfilerCollectorAllowListPass()->process($container);

        self::assertSame(['data_collector.db'], array_keys($container->findTaggedServiceIds('data_collector')));
    }

    public function testItRemovesTheCollectorTagOfACollectorWithoutAnId(): void
    {
        $container = new ContainerBuilder();
        $container->register('profiler', Profiler::class);
        $container->register('data_collector.unnamed')->addTag('data_collector');

        new ProfilerCollectorAllowListPass()->process($container);

        self::assertSame([], $container->findTaggedServiceIds('data_collector'));
    }

    public function testItLeavesAContainerWithoutAProfilerUnchanged(): void
    {
        $container = $this->containerWithCollectors();

        new ProfilerCollectorAllowListPass()->process($container);

        self::assertSame(['data_collector.request', 'data_collector.db'], array_keys($container->findTaggedServiceIds('data_collector')));
    }

    private function containerWithCollectors(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('data_collector.request')->addTag('data_collector', ['id' => 'request']);
        $container->register('data_collector.db')->addTag('data_collector', ['id' => 'db']);

        return $container;
    }
}
