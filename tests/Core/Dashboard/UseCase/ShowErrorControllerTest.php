<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\UseCase\ShowError\ShowErrorController;
use Intakt\Tests\Core\Dashboard\Infrastructure\DashboardHostTest;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ShowErrorController::class)]
#[UsesClass(DashboardHost::class)]
#[UsesClass(FilterProjects::class)]
final class ShowErrorControllerTest extends DashboardPageTestCase
{
    use AssertsLoggedErrors;

    #[DataProvider('missingAddresses')]
    public function testAnAddressThatDoesNotExistShowsTheShellWithAWayBackToTheBoard(string $address): void
    {
        $crawler = $this->browser->request('GET', $address);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Page not found', $crawler->filter('.page-head h1')->text());
        self::assertSame(1, $crawler->filter('.shell .rail')->count());
        self::assertSame(1, $crawler->filter('.topbar')->count());
        self::assertSame('/?board=1', $crawler->filter('main .btn.primary')->attr('href'));
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780%s""', NotFoundHttpException::class, $address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingAddresses(): iterable
    {
        yield 'settings' => ['/settings'];
        yield 'nothing' => ['/does-not-exist'];
    }

    public function testTheNotFoundPageKeepsTheThemeSwitchSoItWorksInDarkAndLight(): void
    {
        $crawler = $this->browser->request('GET', '/settings');

        self::assertSame(1, $crawler->filter('[data-theme-toggle]')->count());
        self::assertStringContainsString("localStorage.getItem('intakt-theme')", $crawler->filter('head script')->first()->html());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://localhost:7780/settings""', NotFoundHttpException::class));
    }

    public function testAMethodTheAddressDoesNotAllowShowsTheShellAndKeepsTheAllowHeader(): void
    {
        $crawler = $this->browser->request('POST', '/');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Allow', 'GET');
        self::assertSame('This request could not be answered.', $crawler->filter('.page-head .lead')->text());
        self::assertSame(1, $crawler->filter('.shell .rail')->count());
        self::assertSame(0, $crawler->filter('main code')->count());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "POST http://localhost:7780/": Method Not Allowed (Allow: GET)"', MethodNotAllowedHttpException::class));
    }

    // Routing fails before the listener that refuses a foreign host has a route to judge, so the error page applies the rule itself.
    #[DataProvider('foreignHosts')]
    public function testAMissingPageShowsNoProjectToAHostThatDoesNotNameTheDashboard(string $host): void
    {
        $this->ledger->startRound('round-1', 'private-project');

        $crawler = $this->browser->request('GET', '/does-not-exist', server: ['HTTP_HOST' => $host]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('private-project', (string) $this->browser->getResponse()->getContent());
        self::assertSame(0, $crawler->filter('.shell, #projfilter')->count());
        self::assertSame('This page does not exist. The address may be mistyped, or the page may have moved.', $crawler->filter('p')->first()->text());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://%s/does-not-exist""', NotFoundHttpException::class, $host));
    }

    #[DataProvider('foreignHosts')]
    public function testARefusedMethodOnAHostThatDoesNotNameTheDashboardShowsNoProjectAndKeepsItsAllowHeader(string $host): void
    {
        $this->ledger->startRound('round-1', 'private-project');

        $crawler = $this->browser->request('POST', '/', server: ['HTTP_HOST' => $host]);

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Allow', 'GET');
        self::assertStringNotContainsString('private-project', (string) $this->browser->getResponse()->getContent());
        self::assertSame(0, $crawler->filter('.shell, #projfilter')->count());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "POST http://%s/": Method Not Allowed (Allow: GET)"', MethodNotAllowedHttpException::class, $host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignHosts(): iterable
    {
        foreach (DashboardHostTest::FOREIGN_HOSTS as $name => $host) {
            yield $name => [$host];
        }
    }

    #[DataProvider('loopbackHosts')]
    public function testAMissingPageStillListsTheProjectsOnALoopbackNameWithTheConfiguredPort(string $host): void
    {
        $this->ledger->startRound('round-1', 'private-project');

        $crawler = $this->browser->request('GET', '/does-not-exist', server: ['HTTP_HOST' => $host]);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('private-project', $crawler->filter('#projfilter')->text());
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://%s/does-not-exist""', NotFoundHttpException::class, $host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackHosts(): iterable
    {
        foreach (DashboardHostTest::LOOPBACK_HOSTS as $name => $host) {
            yield $name => [$host];
        }
    }
}
