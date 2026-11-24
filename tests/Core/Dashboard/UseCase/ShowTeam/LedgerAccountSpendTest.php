<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\TeamSpend;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\ShowTeam\LedgerAccountSpend;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(LedgerAccountSpend::class)]
final class LedgerAccountSpendTest extends TestCase
{
    public function testAUsageWithoutOutputTokensMakesTheSpendUnknown(): void
    {
        $spend = $this->spendOf(new TokenUsageRow('anthropic.default', 'model-a', 1_000_000, null, null, null, null, null, null, null, null, null, null));

        self::assertNull($spend->today->usd);
    }

    public function testAUsageWithoutInputTokensMakesTheSpendUnknown(): void
    {
        $spend = $this->spendOf(new TokenUsageRow('anthropic.default', 'model-a', null, 1_000_000, null, null, null, null, null, null, null, null, null));

        self::assertNull($spend->today->usd);
    }

    public function testAUsageWithBothCountsIsPriced(): void
    {
        $spend = $this->spendOf(new TokenUsageRow('anthropic.default', 'model-a', 1_000_000, 1_000_000, null, null, null, null, null, null, null, null, null));

        self::assertSame(12.0, $spend->today->usd);
    }

    private function spendOf(TokenUsageRow $usage): TeamSpend
    {
        $settled = new UsageSettled('example-call', 'settled', [$usage]);
        $serializer = self::createStub(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(
            static fn (string $payload, string $class): ?UsageSettled => UsageSettled::class === $class ? $settled : null,
        );
        $ledger = new DashboardLedger();
        $ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{}'));
        $reader = new LedgerAccountSpend(
            $ledger,
            new RoundPayloads(new LedgerPayloads($serializer)),
            new ConfiguredPrices(['model-a' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]], '2026-11-13'),
            new MockClock('2026-10-28T12:00:00+00:00'),
        );

        return $reader->ofEachAccount()['anthropic.default'];
    }
}
