<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Dashboard\UseCase\ListReviews\OpenDashboardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(OpenDashboardController::class)]
#[UsesClass(ListReviewsController::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
final class OpenDashboardControllerTest extends DashboardPageTestCase
{
    public function testTheRootAddressSendsTheBrowserToTheReviewList(): void
    {
        $this->browser->request('GET', '/');

        self::assertResponseRedirects('/reviews', Response::HTTP_FOUND);
    }

    public function testFollowingTheRootAddressShowsTheReviewList(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        // The ledger double lives in this kernel's container, so the redirect must not boot a new one.
        $this->browser->disableReboot();
        $this->browser->followRedirects();

        $crawler = $this->browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(['round-1'], $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testTheRootAddressRefusesAHostThatDoesNotNameTheDashboard(): void
    {
        $this->browser->request('GET', '/', server: ['HTTP_HOST' => 'attacker.example:7780']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}
