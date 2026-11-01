<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ListProjectsController::class)]
#[CoversClass(ProjectSummary::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
final class ListProjectsControllerTest extends DashboardPageTestCase
{
    public function testTheProjectListShowsEveryProjectThatStartedAReview(): void
    {
        $this->ledger->startRound('round-1', 'zeta');
        $this->ledger->startRound('round-2', 'alpha');
        $this->ledger->startRound('round-3', 'alpha');
        $this->ledger->judgeRound('round-2');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSame(['alpha', 'zeta'], $crawler->filter('tbody tr td:first-child')->each(static fn ($cell): string => $cell->text()));
        self::assertSame('2', $crawler->filter('tbody tr:first-child td:nth-child(2)')->text());
        self::assertSame('1', $crawler->filter('tbody tr:first-child td:nth-child(3)')->text());
    }

    public function testEachProjectLinksToItsReviews(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('/reviews?project=alpha', $crawler->filter('tbody a')->attr('href'));
    }

    public function testTheProjectListSaysSoWhenTheLedgerHasNoReviews(): void
    {
        $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No project has started a review yet.');
        self::assertSelectorNotExists('table');
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $this->ledger->startRound('round-1', '<b>bold</b>');

        $this->browser->request('GET', '/projects');

        self::assertSelectorNotExists('main b');
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', (string) $this->browser->getResponse()->getContent());
    }
}
