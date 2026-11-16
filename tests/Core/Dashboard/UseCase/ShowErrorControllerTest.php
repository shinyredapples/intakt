<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\ShowError\ShowErrorController;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(ShowErrorController::class)]
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
        self::assertSame('/', $crawler->filter('main .btn.primary')->attr('href'));
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
}
