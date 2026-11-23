<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Plugin\Forge\Contract\ForgeBudget;
use Intakt\Plugin\Forge\Contract\ForgeBudgets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ListReviewsController::class)]
#[UsesClass(ForgeBudget::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(PayloadFields::class)]
final class ForgeBudgetOnTheBoardTest extends DashboardPageTestCase
{
    public function testTheBoardShowsTheCallsUsedAndRemainingOfEachInstallation(): void
    {
        $crawler = $this->boardWith(
            new ForgeBudget('678', 800, 4200, reserveInForce: false),
            new ForgeBudget('679', 4100, 900, reserveInForce: true),
        );

        self::assertSame(['678', '679'], $crawler->filter('.forge-budgets tbody tr td:first-child')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame('800', $crawler->filter('tr[data-installation="678"] td:nth-child(2)')->text());
        self::assertSame('4200', $crawler->filter('tr[data-installation="678"] td:nth-child(3)')->text());
        self::assertSame('4100', $crawler->filter('tr[data-installation="679"] td:nth-child(2)')->text());
        self::assertSame('900', $crawler->filter('tr[data-installation="679"] td:nth-child(3)')->text());
    }

    public function testTheBoardMarksTheInstallationWhoseReserveIsInForce(): void
    {
        $crawler = $this->boardWith(
            new ForgeBudget('678', 800, 4200, reserveInForce: false),
            new ForgeBudget('679', 4100, 900, reserveInForce: true),
        );

        self::assertSame('In force: routine reads wait', $crawler->filter('tr[data-installation="679"] .chip.reserve-in-force')->text());
        self::assertCount(0, $crawler->filter('tr[data-installation="678"] .chip.reserve-in-force'));
        self::assertSame('Not in force', $crawler->filter('tr[data-installation="678"] .chip.reserve-idle')->text());
    }

    public function testAnInstallationWithoutAReadingThisHourSaysSo(): void
    {
        $crawler = $this->boardWith(new ForgeBudget(
            '678',
            used: null,
            remaining: null,
            reserveInForce: false,
        ));

        self::assertSame('No reading this hour', $crawler->filter('tr[data-installation="678"] td.unread')->text());
        self::assertCount(0, $crawler->filter('tr[data-installation="678"] .chip'));
    }

    public function testWithoutAForgeInstallationTheBoardHasNoForgeBudget(): void
    {
        $crawler = $this->boardWith();

        self::assertCount(0, $crawler->filter('.forge-budgets'));
    }

    private function boardWith(ForgeBudget ...$budgets): Crawler
    {
        $forgeBudgets = self::createStub(ForgeBudgets::class);
        $forgeBudgets->method('thisHour')->willReturn(array_values($budgets));
        self::getContainer()->set(ForgeBudgets::class, $forgeBudgets);

        $crawler = $this->browser->request('GET', '/reviews');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
