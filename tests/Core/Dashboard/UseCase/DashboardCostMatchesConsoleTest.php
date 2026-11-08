<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Prices;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnsweringTier;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(Cost::class)]
#[UsesClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(ReviewReport::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(TokenUsageRow::class)]
final class DashboardCostMatchesConsoleTest extends DashboardPageTestCase
{
    private const string ROUND = '0198a7c2-5a3e-7c1b-9d4e-2f6a8b0c1d3e';

    public function testTheDashboardAndTheConsoleShowTheSameFigureForAPricedRound(): void
    {
        self::getContainer()->set(Prices::class, new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]]));
        $this->givenARoundFromARecordedUsageAnswer();

        $console = $this->consoleFigure();
        $this->browser->request('GET', '/reviews/'.self::ROUND);

        self::assertSame('0.0050 USD', $console);
        self::assertSelectorTextContains('p.summary', 'Cost: '.$console.'.');
        self::assertSelectorTextContains('table.tokens tbody td:last-child', $console);
    }

    public function testTheDashboardAndTheConsoleShowTheSameUnknownForARoundWithoutAPrice(): void
    {
        $this->givenARoundFromARecordedUsageAnswer();

        $console = $this->consoleFigure();
        $this->browser->request('GET', '/reviews/'.self::ROUND);

        self::assertSame('unknown, no price for model-a', $console);
        self::assertSelectorTextContains('p.summary', 'Cost: '.$console.'.');
        self::assertSelectorTextContains('table.tokens tbody td:last-child', $console);
    }

    private function givenARoundFromARecordedUsageAnswer(): void
    {
        $this->ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $answer = new TextResult('{}');
        $answer->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 1000, completionTokens: 300, totalTokens: 1300));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willReturn($answer);
        $usage = new UsageLedger($this->ledger, new MockClock('2026-10-28T12:00:00+00:00'), new NullLogger(), StoredSerializerFactory::create());

        $reservation = $usage->reserve('alpha', new AnsweringTier(Tier::Standard, new ResolvedModel(Provider::Anthropic, 'model-a')), Uuid::fromString(self::ROUND));
        $usage->settle($reservation, new DeferredResult($converter, new InMemoryRawResult()));
    }

    // The line `intakt:review` prints: the report renders the round's cost value, as the command does.
    private function consoleFigure(): string
    {
        $costs = self::getContainer()->get(RoundCosts::class);
        self::assertInstanceOf(RoundCosts::class, $costs);
        $review = new ChangeReview(self::ROUND, 1, Decision::Approve, [], [], new RoundTokens(1, 1000, 0, 0, 0, 300, 1300), $costs->costOf(self::ROUND));

        $lines = new ReviewReport(StoredSerializerFactory::create())->asLines($review);
        $cost = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'cost: ')));
        self::assertCount(1, $cost);

        return substr($cost[0], strlen('cost: '));
    }
}
