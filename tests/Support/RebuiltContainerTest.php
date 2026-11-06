<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Container;

#[CoversClass(RebuiltContainer::class)]
final class RebuiltContainerTest extends TestCase
{
    public function testItAnswersWhetherTheBuiltContainerHasAService(): void
    {
        $built = new Container();
        $built->set('probe', new stdClass());

        self::assertTrue(new RebuiltContainer($built)->has('probe'));
        self::assertFalse(new RebuiltContainer($built)->has('absent'));
    }

    public function testItHandsOutTheServiceOfTheBuiltContainer(): void
    {
        $service = new stdClass();
        $built = new Container();
        $built->set('probe', $service);

        self::assertSame($service, new RebuiltContainer($built)->get('probe'));
    }

    public function testItReadsAParameterOfTheBuiltContainer(): void
    {
        $built = new Container();
        $built->setParameter('probe.limit', 3);

        self::assertSame(3, new RebuiltContainer($built)->getParameter('probe.limit'));
    }
}
