<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\Routing\RouteNamesFollowUseCases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(RouteNamesFollowUseCases::class)]
final class RouteNamesFollowUseCasesTest extends TestCase
{
    public function testARouteNamedAfterItsModuleAndUseCaseFolderIsAccepted(): void
    {
        $routes = $this->routes(['dashboard_show_review_record' => 'Intakt\Core\Dashboard\UseCase\ShowReviewRecord\ShowReviewRecordController']);

        self::assertSame([], new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    public function testAControllerGivenWithItsMethodIsCheckedLikeAnInvokableOne(): void
    {
        $routes = $this->routes(['dashboard_show_review' => 'Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController::show']);

        self::assertSame([], new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    public function testARouteNamedAfterAnotherUseCaseIsRejectedWithTheExpectedName(): void
    {
        $routes = $this->routes(['dashboard_show_demo' => 'Intakt\Core\Dashboard\UseCase\PlayDemo\ShowDemoController']);

        self::assertSame(
            ['Route "dashboard_show_demo" is served by Intakt\Core\Dashboard\UseCase\PlayDemo\ShowDemoController, so it must be named "dashboard_play_demo" (<module>_<use_case>): rename the route, or move the controller into the UseCase/<Name>/ folder the name says.'],
            new RouteNamesFollowUseCases()->findingsIn($routes),
        );
    }

    public function testASecondRouteServedFromTheSameUseCaseFolderIsRejected(): void
    {
        $routes = $this->routes([
            'dashboard_show_review' => 'Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController',
            'dashboard_show_review_record' => 'Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController::record',
        ]);

        self::assertCount(1, new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    public function testAPluginControllerIsNamedAfterItsPluginModule(): void
    {
        $routes = $this->routes(['git_hub_work_source_receive_hook' => 'Intakt\Plugin\GitHubWorkSource\UseCase\ReceiveHook\ReceiveHookController']);

        self::assertSame([], new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    public function testAControllerOutsideAUseCaseFolderIsRejected(): void
    {
        $routes = $this->routes(['dashboard_health' => 'Intakt\Core\Dashboard\Infrastructure\HealthController']);

        $findings = new RouteNamesFollowUseCases()->findingsIn($routes);

        self::assertCount(1, $findings);
        self::assertStringContainsString('not in a UseCase/<Name>/ folder', $findings[0]);
    }

    public function testAControllerOutsideTheIntaktNamespaceIsIgnored(): void
    {
        $routes = $this->routes(['_wdt' => 'web_profiler.controller.profiler::toolbarAction', 'mcp_endpoint' => 'Mcp\Server\Controller']);

        self::assertSame([], new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    public function testARouteWithoutAControllerStringIsIgnored(): void
    {
        $routes = new RouteCollection();
        $routes->add('redirect', new Route('/'));

        self::assertSame([], new RouteNamesFollowUseCases()->findingsIn($routes));
    }

    /**
     * @param array<string, string> $controllers route name to controller
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
