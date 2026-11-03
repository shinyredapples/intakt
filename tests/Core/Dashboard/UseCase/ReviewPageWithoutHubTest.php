<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

// The test environment has no hub key (MERCURE_JWT_SECRET is empty), so no listener can be authorized, as when the hub is not set up.
#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
final class ReviewPageWithoutHubTest extends DashboardPageTestCase
{
    public function testThePageRendersTheLedgerStateWhenTheHubCannotAuthorizeAListener(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertSame('0 of 2 reviewers finished.', $crawler->filter('#round-round-1-state')->text());
        self::assertSame([['correctness', 'standard', 'running', '', '', '']], $crawler->filter('table.reviewers tbody tr')->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => $td->text())));
    }
}
