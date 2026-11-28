<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoController;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Dashboard\UseCase\ShowReviewRecord\ShowReviewRecordController;
use Intakt\Gates\Routing\RouteNamesFollowUseCases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(RouteNamesFollowUseCases::class)]
final class RouteNamesFollowUseCasesTest extends TestCase
{
    public function testARouteNamedAfterItsModuleAndUseCaseFolderIsAccepted(): void
    {
        $routes = $this->routes(['dashboard_show_review_record' => ShowReviewRecordController::class]);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testAControllerGivenWithItsMethodIsCheckedLikeAnInvokableOne(): void
    {
        $routes = $this->routes(['dashboard_show_review' => ShowReviewController::class.'::show']);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testARouteNamedAfterAnotherUseCaseIsRejectedWithTheExpectedName(): void
    {
        $routes = $this->routes(['dashboard_show_demo' => 'Intakt\Core\Dashboard\UseCase\PlayDemo\ShowDemoController']);

        self::assertSame(
            ['Route "dashboard_show_demo" is served by Intakt\Core\Dashboard\UseCase\PlayDemo\ShowDemoController, so it must be named "dashboard_play_demo" (<module>_<use_case>): rename the route, or move the controller into the UseCase/<Name>/ folder the name says.'],
            new RouteNamesFollowUseCases([])->findingsIn($routes),
        );
    }

    public function testASecondRouteServedFromTheSameUseCaseFolderIsRejected(): void
    {
        $routes = $this->routes([
            'dashboard_show_review' => ShowReviewController::class,
            'dashboard_show_review_record' => ShowReviewController::class.'::record',
        ]);

        self::assertCount(1, new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testAPluginControllerIsNamedAfterItsPluginModule(): void
    {
        $routes = $this->routes(['git_hub_work_source_receive_hook' => 'Intakt\Plugin\GitHubWorkSource\UseCase\ReceiveHook\ReceiveHookController']);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testAControllerOutsideAUseCaseFolderIsRejected(): void
    {
        $routes = $this->routes(['dashboard_health' => 'Intakt\Core\Dashboard\Infrastructure\HealthController']);

        $findings = new RouteNamesFollowUseCases([])->findingsIn($routes);

        self::assertCount(1, $findings);
        self::assertStringContainsString('not in a UseCase/<Name>/ folder', $findings[0]);
    }

    public function testAUseCaseFolderNestedBelowTheModuleIsRejected(): void
    {
        $routes = $this->routes(['pages_show_demo' => 'Intakt\Core\Dashboard\Pages\UseCase\ShowDemo\ShowDemoController']);

        $findings = new RouteNamesFollowUseCases([])->findingsIn($routes);

        self::assertCount(1, $findings);
        self::assertStringContainsString('not in a UseCase/<Name>/ folder directly inside its module', $findings[0]);
    }

    public function testAControllerWrittenWithALeadingBackslashIsCheckedToo(): void
    {
        $routes = $this->routes(['dashboard_show_demo' => '\Intakt\Core\Dashboard\UseCase\PlayDemo\ShowDemoController']);

        self::assertStringContainsString('must be named "dashboard_play_demo"', implode("\n", new RouteNamesFollowUseCases([])->findingsIn($routes)));
    }

    public function testAControllerWrittenWithALeadingBackslashIsAcceptedUnderItsUseCaseName(): void
    {
        $routes = $this->routes(['dashboard_play_demo' => '\\'.PlayDemoController::class]);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testAControllerGivenAsClassAndMethodListIsRejectedWithTheExpectedName(): void
    {
        $routes = $this->routes(['dashboard_show_demo' => [PlayDemoController::class, '__invoke']]);

        self::assertSame(
            ['Route "dashboard_show_demo" is served by Intakt\Core\Dashboard\UseCase\PlayDemo\PlayDemoController, so it must be named "dashboard_play_demo" (<module>_<use_case>): rename the route, or move the controller into the UseCase/<Name>/ folder the name says.'],
            new RouteNamesFollowUseCases([])->findingsIn($routes),
        );
    }

    public function testAControllerListWithALeadingBackslashIsAcceptedUnderItsUseCaseName(): void
    {
        $routes = $this->routes(['dashboard_play_demo' => ['\\'.PlayDemoController::class, '__invoke']]);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testAControllerListWhoseFirstElementIsNoClassNameIsIgnored(): void
    {
        $routes = $this->routes(['dashboard_show_demo' => [new stdClass(), '__invoke']]);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testAControllerGivenAsAServiceIdIsRejectedWithTheNameOfItsServiceClass(): void
    {
        $routes = $this->routes(['dashboard_wrong' => 'dashboard.route_service_probe::__invoke']);
        $rule = new RouteNamesFollowUseCases(['dashboard.route_service_probe' => 'Intakt\Core\Dashboard\UseCase\RouteServiceProbe\RouteServiceProbeController']);

        self::assertStringContainsString('must be named "dashboard_route_service_probe"', implode("\n", $rule->findingsIn($routes)));
    }

    public function testAControllerGivenAsAServiceIdIsAcceptedUnderTheUseCaseNameOfItsServiceClass(): void
    {
        $routes = $this->routes(['dashboard_play_demo' => ['dashboard.play_demo', '__invoke']]);

        self::assertSame([], new RouteNamesFollowUseCases(['dashboard.play_demo' => PlayDemoController::class])->findingsIn($routes));
    }

    public function testAControllerWrittenInLowerCaseIsRejectedWithTheNameOfItsDeclaredClass(): void
    {
        $routes = $this->routes(['dashboard_wrong' => strtolower(PlayDemoController::class).'::__invoke']);
        $rule = new RouteNamesFollowUseCases([PlayDemoController::class => PlayDemoController::class]);

        self::assertStringContainsString('must be named "dashboard_play_demo"', implode("\n", $rule->findingsIn($routes)));
    }

    public function testAControllerWrittenInLowerCaseIsAcceptedUnderTheUseCaseNameOfItsDeclaredClass(): void
    {
        $routes = $this->routes(['dashboard_play_demo' => strtolower(PlayDemoController::class)]);

        self::assertSame([], new RouteNamesFollowUseCases([PlayDemoController::class => PlayDemoController::class])->findingsIn($routes));
    }

    public function testAServiceClassSpelledInAnotherCaseIsRejectedWithTheNameOfItsDeclaredClass(): void
    {
        $routes = $this->routes(['dashboard_routecasing' => 'demo.casing::__invoke']);
        $rule = new RouteNamesFollowUseCases(
            ['demo.casing' => 'Intakt\Core\Dashboard\UseCase\Routecasing\FixtureController'],
            ['Intakt\Core\Dashboard\UseCase\RouteCasing\FixtureController'],
        );

        self::assertStringContainsString('must be named "dashboard_route_casing"', implode("\n", $rule->findingsIn($routes)));
    }

    public function testAServiceClassSpelledInAnotherCaseIsAcceptedUnderTheUseCaseNameOfItsDeclaredClass(): void
    {
        $routes = $this->routes(['dashboard_route_casing' => 'demo.casing::__invoke']);
        $rule = new RouteNamesFollowUseCases(
            ['demo.casing' => 'Intakt\Core\Dashboard\UseCase\Routecasing\FixtureController'],
            ['Intakt\Core\Dashboard\UseCase\RouteCasing\FixtureController'],
        );

        self::assertSame([], $rule->findingsIn($routes));
    }

    public function testAControllerInAnotherCaseThanAnyKnownClassIsRejected(): void
    {
        $routes = $this->routes(['dashboard_wrong' => 'intakt\Core\Dashboard\UseCase\RouteCaseProbe\RouteCaseProbeController::__invoke']);

        self::assertStringContainsString('write the class in the case it is declared in', implode("\n", new RouteNamesFollowUseCases([])->findingsIn($routes)));
    }

    public function testAControllerOutsideTheIntaktNamespaceIsIgnored(): void
    {
        $routes = $this->routes(['_wdt' => 'web_profiler.controller.profiler::toolbarAction', 'mcp_endpoint' => 'Mcp\Server\Controller']);

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    public function testARouteWithoutAControllerStringIsIgnored(): void
    {
        $routes = new RouteCollection();
        $routes->add('redirect', new Route('/'));

        self::assertSame([], new RouteNamesFollowUseCases([])->findingsIn($routes));
    }

    /**
     * @param array<string, string|list<mixed>> $controllers
     */
    private function routes(array $controllers): RouteCollection
    {
        $routes = new RouteCollection();

        foreach ($controllers as $name => $controller) {
            $routes->add($name, new Route('/'.$name, ['_controller' => $controller]));
        }

        return $routes;
    }
}
