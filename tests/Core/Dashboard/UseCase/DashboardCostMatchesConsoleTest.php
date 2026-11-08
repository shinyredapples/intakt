<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(Cost::class)]
#[UsesClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(ConfiguredPrices::class)]
final class DashboardCostMatchesConsoleTest extends DashboardPageTestCase
{
    public function testTheDashboardAndTheConsoleShowTheSameFigureForAPricedRound(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]]));
        $this->givenARoundFromARecordedUsageAnswer();

        $console = $this->consoleFigure();
        $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0.0050 USD', $console);
        self::assertSelectorTextContains('p.summary', 'Cost: '.$console.'.');
        self::assertSelectorTextContains('table.tokens tbody td:last-child', $console);
    }

    public function testTheDashboardAndTheConsoleShowTheSameUnknownForARoundWithoutAPrice(): void
    {
        $this->givenARoundFromARecordedUsageAnswer();

        $console = $this->consoleFigure();
        $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('unknown, no price for model-a', $console);
        self::assertSelectorTextContains('p.summary', 'Cost: '.$console.'.');
        self::assertSelectorTextContains('table.tokens tbody td:last-child', $console);
    }

    private function givenARoundFromARecordedUsageAnswer(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->useRecordedTokens('round-1', ['prompt' => 1000, 'completion' => 300, 'total' => 1300]);
    }

    // The console prints the round's cost value through this wording, so the same call is what it shows.
    private function consoleFigure(): string
    {
        $costs = self::getContainer()->get(RoundCosts::class);
        self::assertInstanceOf(RoundCosts::class, $costs);

        return $costs->costOf('round-1')->reading();
    }
}
