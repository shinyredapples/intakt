<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\ShowReviewRecord\ShowReviewRecordController;
use Intakt\Core\Dashboard\UseCase\StartReview\StartReviewController;
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
        $routes->add('dashboard_start_first_review', new Route('/setup', ['_controller' => StartReviewController::class]));

        self::assertSame(
            ['dashboard_start_first_review: name it dashboard_start_review (<module>_<use_case>), after the module Dashboard and the folder UseCase/StartReview/; a route of another use case needs a controller in a UseCase/ folder of its own'],
            $this->misnamedRoutesIn($routes),
        );
    }

    public function testASuffixOnARouteOfAnInvokableControllerIsRejected(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_start_review_first', new Route('/a', ['_controller' => StartReviewController::class]));

        self::assertCount(1, $this->misnamedRoutesIn($routes));
    }

    public function testASuffixOnARouteOfTheInvokeMethodIsRejected(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_start_review_invoke', new Route('/a', ['_controller' => StartReviewController::class.'::__invoke']));

        self::assertCount(1, $this->misnamedRoutesIn($routes));
    }

    public function testTheActionMethodAsASuffixOfTheRouteNameIsRejected(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_show_review_record', new Route('/reviews/{round}/record', ['_controller' => ShowReviewController::class.'::record']));

        self::assertSame(
            ['dashboard_show_review_record: name it dashboard_show_review (<module>_<use_case>), after the module Dashboard and the folder UseCase/ShowReview/; a route of another use case needs a controller in a UseCase/ folder of its own'],
            $this->misnamedRoutesIn($routes),
        );
    }

    public function testARouteOnAnActionMethodNamedAfterTheFolderAloneIsAllowed(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_show_review', new Route('/a', ['_controller' => ShowReviewController::class.'::record']));

        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    public function testTheFullRecordRouteIsNamedAfterAFolderOfItsOwn(): void
    {
        $routes = new RouteCollection();
        $routes->add(DashboardRoutes::SHOW_REVIEW_RECORD, new Route('/reviews/{round}/record', ['_controller' => ShowReviewRecordController::class]));

        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    public function testARouteNamedAfterItsModuleAndUseCaseIsAllowed(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_start_review', new Route('/a', ['_controller' => StartReviewController::class]));

        self::assertSame([], $this->misnamedRoutesIn($routes));
    }

    public function testANameThatOnlyStartsWithTheUseCaseWordsIsRejected(): void
    {
        $routes = new RouteCollection();
        $routes->add('dashboard_show_reviewer', new Route('/a', ['_controller' => ShowReviewController::class]));

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
            $found = $this->moduleAndUseCaseOf($route);

            if ([] !== $found && !$this->isNamedAfter($name, $found)) {
                $problems[] = sprintf('%s: name it %s (<module>_<use_case>), after the module %s and the folder UseCase/%s/; a route of another use case needs a controller in a UseCase/ folder of its own', $name, $this->expectedName($found), $found[1], $found[2]);
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function moduleAndUseCaseOf(Route $route): array
    {
        $controller = $route->getDefault('_controller');
        // the action method after :: is matched so such a route is judged, and left out of the name: only the module and the folder give it
        $matched = preg_match('/^Intakt\\\\(?:Core|Plugin)\\\\(.+?)\\\\UseCase\\\\([^\\\\]+)\\\\[^:]+(?:::\w+)?$/', is_string($controller) ? $controller : '', $found);

        return 1 === $matched ? $found : [];
    }

    /**
     * @param list<string> $found
     */
    private function isNamedAfter(string $name, array $found): bool
    {
        return $name === $this->expectedName($found);
    }

    /**
     * @param list<string> $found
     */
    private function expectedName(array $found): string
    {
        return $this->snake(str_replace('\\', '', $found[1])).'_'.$this->snake($found[2]);
    }

    private function snake(string $words): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $words));
    }
}
