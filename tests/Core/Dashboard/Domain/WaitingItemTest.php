<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Dashboard\Domain\WaitingChoice;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Domain\WaitingItemNeedsOneToFourChoices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WaitingItem::class)]
#[CoversClass(WaitingItemNeedsOneToFourChoices::class)]
#[UsesClass(WaitingChoice::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(Account::class)]
final class WaitingItemTest extends TestCase
{
    public function testAnOutageNoticeBecomesAnItemWhoseRecommendedChoiceComesFirst(): void
    {
        $item = WaitingItem::ofOutage(new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02 08:00:00 UTC'), new DateTimeImmutable('2026-11-02 11:00:00 UTC')));

        self::assertStringContainsString('claude_code.main', $item->lead);
        self::assertStringContainsString('08:00 UTC', $item->why);
        self::assertTrue($item->choices[0]->recommended);
        self::assertSame('bin/console intakt:vendor:probe', $item->choices[0]->command);
    }

    public function testAnItemWithNoChoiceIsRefused(): void
    {
        $this->expectException(WaitingItemNeedsOneToFourChoices::class);

        $this->itemOf([]);
    }

    public function testAnItemWithFiveChoicesIsRefused(): void
    {
        $this->expectException(WaitingItemNeedsOneToFourChoices::class);

        $this->itemOf([$this->choice(true), ...array_fill(0, 4, $this->choice(false))]);
    }

    public function testAnItemWithFourChoicesIsAllowed(): void
    {
        $item = $this->itemOf([$this->choice(true), ...array_fill(0, 3, $this->choice(false))]);

        self::assertCount(4, $item->choices);
    }

    public function testAnItemWhoseFirstChoiceIsNotRecommendedIsRefused(): void
    {
        $this->expectException(WaitingItemNeedsOneToFourChoices::class);

        $this->itemOf([$this->choice(false)]);
    }

    #[DataProvider('waits')]
    public function testTheWaitIsTheWholeUnitsSinceTheItemAppeared(string $now, string $expected): void
    {
        $item = $this->itemOf([$this->choice(true)]);

        self::assertSame($expected, $item->waitingFor(new DateTimeImmutable($now)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function waits(): iterable
    {
        yield 'under a minute' => ['2026-11-02 11:00:59 UTC', 'Waiting less than a minute'];
        yield 'a clock behind the notice' => ['2026-11-02 10:00:00 UTC', 'Waiting less than a minute'];
        yield 'minutes' => ['2026-11-02 11:52:30 UTC', 'Waiting 52 min'];
        yield 'hours and minutes' => ['2026-11-02 15:12:00 UTC', 'Waiting 4 h 12 min'];
        yield 'days and hours' => ['2026-11-04 14:30:00 UTC', 'Waiting 2 d 3 h'];
    }

    /**
     * @param list<WaitingChoice> $choices
     */
    private function itemOf(array $choices): WaitingItem
    {
        return new WaitingItem('Lead', 'Consequence', 'Why', new DateTimeImmutable('2026-11-02 11:00:00 UTC'), $choices);
    }

    private function choice(bool $recommended): WaitingChoice
    {
        return new WaitingChoice('Does a thing.', null, $recommended);
    }
}
