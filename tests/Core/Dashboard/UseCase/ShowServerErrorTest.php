<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\UseCase\ShowError\ShowErrorController;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

// Apart from the page tests, because their base class fixes the ledger the kernel serves and a failing one must replace it.
#[CoversClass(ShowErrorController::class)]
#[UsesClass(DashboardHost::class)]
#[UsesClass(FilterProjects::class)]
final class ShowServerErrorTest extends WebTestCase
{
    use AssertsLoggedErrors;

    private KernelBrowser $browser;

    public function testAServerErrorShowsTheShellAndAReferenceTheLogCarriesToo(): void
    {
        $this->readsFailOnce();

        $crawler = $this->browser->request('GET', '/');

        self::assertResponseStatusCodeSame(500);
        self::assertSame('Something went wrong', $crawler->filter('.page-head h1')->text());
        self::assertSame(1, $crawler->filter('.shell .rail')->count());
        $reference = $crawler->filter('main code')->text();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $reference);
        self::assertLoggedError(LogLevel::ERROR, sprintf('A dashboard page failed, reference %s.', $reference));
        self::assertLoggedError(LogLevel::CRITICAL, sprintf('Uncaught PHP Exception %s: "the ledger is gone"', RuntimeException::class));
    }

    public function testAServerErrorPageShowsNeitherTheExceptionMessageNorATrace(): void
    {
        $this->readsFailOnce();

        $crawler = $this->browser->request('GET', '/');

        $page = $crawler->html();
        self::assertStringNotContainsString('the ledger is gone', $page);
        self::assertStringNotContainsString('RuntimeException', $page);
        self::assertStringNotContainsString('Stack Trace', $page);
        $this->takeServerErrorLogs($crawler);
    }

    public function testALedgerThatStaysDownStillLeavesTheShellWithTheThemeSwitch(): void
    {
        $failing = self::createStub(Ledger::class);
        $failing->method('readInOrder')->willThrowException(new RuntimeException('the ledger is gone'));
        self::getContainer()->set(Ledger::class, $failing);

        $crawler = $this->browser->request('GET', '/');

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $crawler->filter('.shell .rail')->count());
        self::assertSame(1, $crawler->filter('[data-theme-toggle]')->count());
        self::assertSame(['All projects'], $crawler->filter('#projfilter option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertStringNotContainsString('the ledger is gone', $crawler->html());
        self::assertLoggedError(LogLevel::WARNING, 'The error page lists no projects, because they could not be read.');
        $this->takeServerErrorLogs($crawler);
    }

    public function testAServerErrorWhosePageCannotBeRenderedStillShowsAReferenceAndALinkToTheBoard(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents([]));
        self::getContainer()->set(Ledger::class, $ledger);
        $twig = self::createStub(Environment::class);
        $twig->method('render')->willThrowException(new RuntimeException('the templates are gone'));
        self::getContainer()->set('twig', $twig);

        $crawler = $this->browser->request('GET', '/');

        self::assertResponseStatusCodeSame(500);
        self::assertSame('/?board=1', $crawler->filter('a')->attr('href'));
        $reference = $crawler->filter('code')->text();
        self::assertLoggedError(LogLevel::ERROR, sprintf('A dashboard page failed, reference %s.', $reference));
        self::assertLoggedError(LogLevel::WARNING, 'The error page was shown without the dashboard shell.');
        self::assertLoggedError(LogLevel::CRITICAL, sprintf('Uncaught PHP Exception %s: "the templates are gone"', RuntimeException::class));
    }

    public function testAnErrorPageForAHostThatDoesNotNameTheDashboardNeverReadsTheLedger(): void
    {
        $ledger = $this->createMock(Ledger::class);
        $ledger->expects(self::never())->method('readInOrder');
        self::getContainer()->set(Ledger::class, $ledger);

        $this->browser->request('GET', '/does-not-exist', server: ['HTTP_HOST' => 'attacker.example:7780']);

        self::assertResponseStatusCodeSame(404);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "No route found for "GET http://attacker.example:7780/does-not-exist""', NotFoundHttpException::class));
    }

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
    }

    private function readsFailOnce(): void
    {
        $reads = 0;
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturnCallback(static function () use (&$reads): RecordedEvents {
            if (1 === ++$reads) {
                throw new RuntimeException('the ledger is gone');
            }

            return new RecordedEvents([]);
        });
        self::getContainer()->set(Ledger::class, $ledger);
    }

    private function takeServerErrorLogs(Crawler $crawler): void
    {
        self::assertLoggedError(LogLevel::ERROR, sprintf('A dashboard page failed, reference %s.', $crawler->filter('main code')->text()));
        self::assertLoggedError(LogLevel::CRITICAL, sprintf('Uncaught PHP Exception %s: "the ledger is gone"', RuntimeException::class));
    }
}
