<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

// The test environment has no hub key (MERCURE_JWT_SECRET is empty), so no listener can be authorized, as when the hub is not set up.
#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LivePageRendering::class)]
final class ReviewPageWithoutHubTest extends DashboardPageTestCase
{
    public function testThePageRendersTheLedgerStateWhenTheHubCannotAuthorizeAListener(): void
    {
        $this->givenARunningRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame('0 of 2 reviewers finished.', $crawler->filter('#round-round-1-state')->text());
        self::assertSame([['correctness', 'standard', 'running', '', '', '', 'Stop this reviewer']], $crawler->filter('table.reviewers tbody tr')->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => $td->text())));
    }

    public function testThePlainPageStillShowsTheLineTheStartButtonLeftAndLogsAWarning(): void
    {
        $this->givenARunningRound();
        $log = new BufferingLogger();
        $request = $this->requestWithTheLine('Started round round-2.');

        $page = new Crawler((string) $this->controller($log)($request, 'round-1')->getContent());

        self::assertSame('Started round round-2.', $page->filter('.flash-success')->text());
        self::assertCount(0, $page->filter('turbo-mercure-stream-source'));
        self::assertSame(['warning'], array_column($log->cleanLogs(), 0));
    }

    private function givenARunningRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
    }

    private function requestWithTheLine(string $line): Request
    {
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('success', $line);
        $request = Request::create('/reviews/round-1');
        $request->setSession($session);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);

        return $request;
    }

    private function controller(BufferingLogger $log): ShowReviewController
    {
        $reviews = self::getContainer()->get(LedgerReviewPages::class);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(LedgerReviewPages::class, $reviews);
        self::assertInstanceOf(Environment::class, $twig);

        return new ShowReviewController($reviews, new LivePageRendering($twig, $log));
    }
}
