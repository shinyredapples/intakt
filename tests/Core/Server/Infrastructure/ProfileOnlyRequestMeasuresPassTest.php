<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\ProfileOnlyRequestMeasuresPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profiler;

#[CoversClass(ProfileOnlyRequestMeasuresPass::class)]
final class ProfileOnlyRequestMeasuresPassTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function measures(): iterable
    {
        foreach (['db', 'messenger', 'time', 'memory'] as $measure) {
            yield $measure => [$measure];
        }
    }

    #[DataProvider('measures')]
    public function testACollectorThatMeasuresARequestKeepsItsTag(string $measure): void
    {
        $container = $this->containerWithAProfilerAndACollector(['id' => $measure]);

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertTrue($container->getDefinition('collector')->hasTag('data_collector'));
    }

    public function testACollectorOutsideTheAllowListLosesItsTag(): void
    {
        $container = $this->containerWithAProfilerAndACollector(['id' => 'request']);

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertFalse($container->getDefinition('collector')->hasTag('data_collector'));
        self::assertTrue($container->hasDefinition('collector'));
    }

    public function testACollectorOutsideTheAllowListIsResetWithTheOtherServices(): void
    {
        $container = $this->containerWithAProfilerAndACollector(['id' => 'serializer']);

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertSame([['method' => 'reset']], $container->getDefinition('collector')->getTag('kernel.reset'));
    }

    public function testACollectorOutsideTheAllowListKeepsTheResetItAlreadyHas(): void
    {
        $container = $this->containerWithAProfilerAndACollector(['id' => 'serializer']);
        $container->getDefinition('collector')->addTag('kernel.reset', ['method' => 'clear']);

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertSame([['method' => 'clear']], $container->getDefinition('collector')->getTag('kernel.reset'));
    }

    #[DataProvider('measures')]
    public function testACollectorThatMeasuresARequestIsLeftToTheProfilerToReset(string $measure): void
    {
        $container = $this->containerWithAProfilerAndACollector(['id' => $measure]);

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertFalse($container->getDefinition('collector')->hasTag('kernel.reset'));
    }

    public function testACollectorTaggedWithoutAnIdLosesItsTag(): void
    {
        $container = $this->containerWithAProfilerAndACollector([]);

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertFalse($container->getDefinition('collector')->hasTag('data_collector'));
    }

    public function testAContainerWithoutAProfilerKeepsEveryCollectorTag(): void
    {
        $container = $this->containerWithAProfilerAndACollector(['id' => 'request']);
        $container->removeDefinition('profiler');

        new ProfileOnlyRequestMeasuresPass()->process($container);

        self::assertTrue($container->getDefinition('collector')->hasTag('data_collector'));
    }

    /**
     * @param array<string, string> $tag
     */
    private function containerWithAProfilerAndACollector(array $tag): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('profiler', Profiler::class);
        $container->register('collector', RequestDataCollector::class)->addTag('data_collector', $tag);

        return $container;
    }
}
