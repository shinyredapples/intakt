<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Gates\Routing\RouteNamesFollowUseCases;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(RouteNamesFollowUseCases::class)]
final class AppRouteNamesTest extends KernelTestCase
{
    public function testEveryRouteTheApplicationRegistersIsNamedAfterItsModuleAndUseCase(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);

        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame([], new RouteNamesFollowUseCases()->findingsIn($router->getRouteCollection()));
    }
}
