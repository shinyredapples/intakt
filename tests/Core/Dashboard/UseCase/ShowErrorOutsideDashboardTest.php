<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\UseCase\ShowError\ShowErrorController;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(ShowErrorController::class)]
#[UsesClass(DashboardHost::class)]
#[UsesClass(FilterProjects::class)]
final class ShowErrorOutsideDashboardTest extends WebTestCase
{
    use AssertsLoggedErrors;

    public function testAnUncaughtFailureOnAnMcpRouteAnswersInTheRequestedFormatNotWithTheDashboardPage(): void
    {
        $response = $this->answer('mcp_owner', 'application/json');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('json', (string) $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('<html', (string) $response->getContent());
        self::assertStringNotContainsString('shell', (string) $response->getContent());
        self::assertCount(1, $this->takeLoggedMessages());
    }

    public function testAServerErrorOutsideTheDashboardIsLoggedWithoutTheDashboardWording(): void
    {
        $this->answer('mcp_review', 'application/json');

        self::assertMatchesRegularExpression('/^A request failed, reference [0-9a-f]{8}\.$/', $this->takeLoggedMessages()[0]);
    }

    public function testAServerErrorOnADashboardRouteStillShowsTheShellAndLogsAsADashboardPage(): void
    {
        $response = $this->answer(DashboardRoutes::SHOW_BOARD, 'application/json');

        self::assertStringContainsString('class="shell', (string) $response->getContent());
        self::assertMatchesRegularExpression('/^A dashboard page failed, reference [0-9a-f]{8}\.$/', $this->takeLoggedMessages()[0]);
    }

    /**
     * @return list<string>
     */
    private function takeLoggedMessages(): array
    {
        // The reference is random, so the exact message cannot be asserted by name, and taking the records marks them as asserted.
        return array_map(static fn (array $record): string => $record['message'], LogRecords::ofThisRun()?->takeErrors() ?? []);
    }

    private function answer(string $route, string $accept): Response
    {
        $request = Request::create('http://localhost:7780/x', server: ['HTTP_ACCEPT' => $accept]);
        $request->attributes->set('_route', $route);
        // The renderer reads the format from the request stack, because it is filled when the kernel handles the error sub-request.
        self::getContainer()->get('request_stack')->push($request);

        return self::getContainer()->get(ShowErrorController::class)(new RuntimeException('boom'), $request);
    }
}
