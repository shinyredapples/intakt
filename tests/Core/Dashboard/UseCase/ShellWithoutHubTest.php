<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

// The test environment has no hub key (MERCURE_JWT_SECRET is empty), so no listener can be authorized, as when the hub is not set up.
#[CoversClass(LivePageRendering::class)]
#[UsesClass(ListProjectsController::class)]
final class ShellWithoutHubTest extends DashboardPageTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'project list' => ['/projects'];
        yield 'review list' => ['/reviews'];
    }

    #[DataProvider('pages')]
    public function testAListPageRendersFullyWithoutAStreamSourceWhenTheHubCannotAuthorizeAListener(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertStringNotContainsString('importmap', (string) $this->browser->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('main.content [data-project="alpha"]')->count());
    }

    public function testThePlainPageKeepsTheFlashLineAndLogsAWarning(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('success', 'Started round round-2.');
        $request = Request::create('/projects');
        $request->setSession($session);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
        $log = new BufferingLogger();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $page = new Crawler(new LivePageRendering($twig, $log)->render($request, 'dashboard/projects.html.twig', ['projects' => []]));

        self::assertSame('Started round round-2.', $page->filter('.flash-success')->text());
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertSame(['warning'], array_column($log->cleanLogs(), 0));
    }
}
