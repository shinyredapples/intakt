<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\UseCase\ShowError\ShowErrorController;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(ShowErrorController::class)]
#[UsesClass(DashboardHost::class)]
#[UsesClass(FilterProjects::class)]
final class ShowErrorOutsideDashboardTest extends WebTestCase
{
    public function testAnUncaughtFailureOnAnMcpRouteAnswersInTheRequestedFormatNotWithTheDashboardPage(): void
    {
        $response = $this->failingRequest('POST', '/mcp/owner');

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('json', (string) $response->headers->get('Content-Type'));
        self::assertStringNotContainsString('<html', (string) $response->getContent());
        self::assertCount(1, $this->controllerLogLines());
    }

    // `/mcp/review` refuses a call without a credential before its controller runs, so `/mcp/owner` stands for both endpoints here.
    public function testAServerErrorOutsideTheDashboardIsLoggedWithoutTheDashboardWording(): void
    {
        $this->failingRequest('POST', '/mcp/owner');

        self::assertSame(['A request failed'], $this->controllerLogLines());
    }

    public function testAServerErrorOnADashboardRouteStillShowsTheShellAndLogsAsADashboardPage(): void
    {
        $response = $this->failingRequest('GET', '/');

        self::assertStringContainsString('class="shell', (string) $response->getContent());
        self::assertSame(['A dashboard page failed'], $this->controllerLogLines());
    }

    // The failure is thrown once routing has named the route, so the kernel's error path runs as it does for a failing MCP or dashboard controller.
    private function failingRequest(string $method, string $path): Response
    {
        $browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event): void {
            if ($event->isMainRequest()) {
                throw new RuntimeException('boom');
            }
        });
        $browser->request(
            $method,
            $path,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        return $browser->getResponse();
    }

    /**
     * @return list<string>
     */
    private function controllerLogLines(): array
    {
        // Taking every record marks the kernel's own critical line as asserted too; the reference is random, so only the wording before it is compared.
        $messages = array_column(LogRecords::ofThisRun()?->takeErrors() ?? [], 'message');
        $lines = [];
        foreach ($messages as $message) {
            if (1 === preg_match('/^(.+), reference [0-9a-f]{8}\.$/', $message, $match)) {
                $lines[] = $match[1];
            }
        }

        return $lines;
    }
}
