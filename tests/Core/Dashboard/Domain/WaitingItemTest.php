<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Dashboard\Domain\Character;
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
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertStringContainsString('claude_code.main', $item->lead);
        self::assertStringContainsString('08:00 UTC', $item->why);
        self::assertTrue($item->choices[0]->recommended);
    }

    public function testTheRecommendedChoiceForAnOutageRunsNoPaidCall(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertNull($item->choices[0]->command);
        self::assertSame('php bin/console intakt:vendor:probe', $item->choices[1]->command);
        self::assertStringContainsString('paid call', $item->choices[1]->outcome);
    }

    // The budget guard refuses even the first call while the last reading is at its ceiling, so no line may promise that builds go on.
    public function testAnOutageItemPromisesNoRunningBuildsWhileTheLastReadingIsAtItsLimit(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertStringNotContainsString('keep running', $item->consequence.' '.$item->choices[0]->outcome);
        self::assertStringContainsString('none while any of its five-hour or weekly windows that has not reset yet stands at its limit', $item->consequence);
        self::assertStringContainsString('none while any of its five-hour or weekly windows that has not reset yet stands at its limit', $item->choices[0]->outcome);
    }

    // An older weekly reading at its ceiling still blocks starts after the newest reading's window resets, so no line may tie the block to that one window.
    public function testAnOutageItemDoesNotTieTheLimitToTheWindowOfTheNewestReading(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertStringNotContainsString('until that window resets', $item->consequence.' '.$item->choices[0]->outcome);
        self::assertStringNotContainsString('its last reading', $item->consequence.' '.$item->choices[0]->outcome);
    }

    public function testTheProbeChoiceSaysItMayNotReachThisAccountsProvider(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertStringContainsString('one paid call per provider in your projects\' tiers; it may not include this account\'s provider', $item->choices[1]->outcome);
    }

    public function testAnOutageItemLinksToTheStatusPageItIsGiven(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), 'https://status.example');

        self::assertSame('https://status.example', $item->link);
        self::assertSame('Open the provider\'s status page', $item->linkLabel);
    }

    public function testAnOutageItemWithoutAStatusPageLinksToTheBoard(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertNull($item->link);
        self::assertSame('See the queue on the board', $item->linkLabel);
    }

    // The line under the link says what the tap shows and that the card goes by itself, because the card offers no button that ends it.
    #[DataProvider('linkOutcomes')]
    public function testAnOutageLinkSaysWhatItShowsAndThatTheCardLeavesWhenReadingsReturn(?string $statusPage, string $expected): void
    {
        $item = WaitingItem::ofOutage($this->outage(), $statusPage);

        self::assertSame($expected.' This card leaves when readings return.', $item->linkOutcome);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function linkOutcomes(): iterable
    {
        yield 'status page' => ['https://status.example', 'Shows whether the provider reports trouble.'];
        yield 'board' => [null, 'Shows what waits on this account.'];
    }

    public function testTheMaintainerWaitsOnTheOwnerForAnOutage(): void
    {
        self::assertSame(Character::Maintainer, WaitingItem::ofOutage($this->outage(), null)->character);
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
    public function testTheWaitIsTheWholeUnitsSinceTheItemAppeared(DateTimeImmutable $now, string $expected): void
    {
        $item = $this->itemOf([$this->choice(true)]);

        self::assertSame($expected, $item->waitingFor($now));
    }

    /**
     * @return iterable<string, array{DateTimeImmutable, string}>
     */
    public static function waits(): iterable
    {
        yield 'under a minute' => [new DateTimeImmutable('2026-11-02T11:00:59+00:00'), 'Waiting less than a minute'];
        yield 'a clock behind the notice' => [new DateTimeImmutable('2026-11-02T10:00:00+00:00'), 'Waiting less than a minute'];
        yield 'minutes' => [new DateTimeImmutable('2026-11-02T11:52:30+00:00'), 'Waiting 52 min'];
        yield 'hours and minutes' => [new DateTimeImmutable('2026-11-02T15:12:00+00:00'), 'Waiting 4 h 12 min'];
        yield 'days and hours' => [new DateTimeImmutable('2026-11-04T14:30:00+00:00'), 'Waiting 2 d 3 h'];
    }

    /**
     * @param list<WaitingChoice> $choices
     */
    private function itemOf(array $choices): WaitingItem
    {
        return new WaitingItem('Lead', 'Consequence', 'Why', new DateTimeImmutable('2026-11-02T11:00:00+00:00'), $choices, 'Open the status page', 'https://status.example', 'Shows whether the provider reports trouble.', Character::Maintainer);
    }

    private function outage(): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
    }

    private function choice(bool $recommended): WaitingChoice
    {
        return new WaitingChoice(
            outcome: 'Does a thing.',
            command: null,
            recommended: $recommended,
        );
    }
}
