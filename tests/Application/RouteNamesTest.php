<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

// Covers nothing because the rule lives in this test: AGENTS.md names routes <module>_<use_case>, from the controller's module and its UseCase/<Name>/ folder.
#[CoversNothing]
final class RouteNamesTest extends KernelTestCase
{
    public function testEveryRouteOfTheApplicationIsNamedAfterItsModuleAndUseCase(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = $router->getRouteCollection();

        self::assertNotSame(0, $routes->count());
        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    public function testARouteNamedAfterADifferentUseCaseThanItsControllersFolderIsRejected(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_start_first_review', new Route('/setup', ['_controller' => 'Intakt\Core\Dashboard\UseCase\StartReview\StartFirstReviewController']));

        self::assertSame(
            ['dashboard_start_first_review: name it dashboard_start_review (<module>_<use_case>, or that name followed by _<action> for a second route of the use case), after the module Dashboard and the folder UseCase/StartReview/'],
            $this->misnamedRoutesIn($routes),
        );
    }

    public function testARouteNamedAfterItsModuleAndUseCaseIsAllowed(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_start_review', new Route('/a', ['_controller' => 'Intakt\Core\Dashboard\UseCase\StartReview\StartReviewController']));

        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    public function testASecondRouteOfAUseCaseMayAddAnActionSuffix(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_show_review_record', new Route('/a', ['_controller' => 'Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController::record']));

        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    public function testANameThatOnlyStartsWithTheUseCaseWordsIsRejected(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_show_reviewer', new Route('/a', ['_controller' => 'Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController']));

        self::assertCount(1, $this->misnamedRoutesIn($routes));
    }

    public function testARouteOfAnotherModuleIsJudgedByItsOwnModuleName(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_show_board', new Route('/a', ['_controller' => 'Intakt\Core\ReviewLedger\UseCase\ShowBoard\ShowBoardController']));

        self::assertCount(1, $this->misnamedRoutesIn($routes));
    }

    public function testARouteOfAFrameworkControllerIsNotJudged(): void
    {
        $routes = new RouteCollection();
        $routes->add('_wdt', new Route('/a', ['_controller' => 'web_profiler.controller.profiler::toolbarAction']));

        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    /**
     * @return list<string>
     */
    private function misnamedRoutesIn(RouteCollection $routes): array
    {
        $problems = [];

        foreach ($routes as $name => $route) {
            $controller = $route->getDefault('_controller');

            if (1 !== preg_match('/^Intakt\\\\(?:Core|Plugin)\\\\(.+?)\\\\UseCase\\\\([^\\\\]+)\\\\/', is_string($controller) ? $controller : '', $found)) {
                continue;
            }

            $expected = $this->snake(str_replace('\\', '', $found[1])).'_'.$this->snake($found[2]);

            if ($name !== $expected && !str_starts_with($name, $expected.'_')) {
                $problems[] = sprintf('%s: name it %s (<module>_<use_case>, or that name followed by _<action> for a second route of the use case), after the module %s and the folder UseCase/%s/', $name, $expected, $found[1], $found[2]);
            }
        }

        return $problems;
    }

    private function snake(string $words): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $words));
    }
}
