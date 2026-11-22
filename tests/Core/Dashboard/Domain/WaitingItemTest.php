<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WaitingItem::class)]
#[UsesClass(OutageNotice::class)]
#[UsesClass(Account::class)]
final class WaitingItemTest extends TestCase
{
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
        yield 'board' => [null, 'Shows the build queue on the board.'];
    }

    public function testTheMaintainerWaitsOnTheOwnerForAnOutage(): void
    {
        self::assertSame(Character::Maintainer, WaitingItem::ofOutage($this->outage(), null)->character);
    }

    public function testAnOutageItemNamesTheAccountAndPromisesNoRunningBuilds(): void
    {
        $item = WaitingItem::ofOutage($this->outage(), null);

        self::assertStringContainsString('claude_code.main', $item->lead);
        self::assertStringContainsString('08:00 UTC', $item->lead);
        self::assertStringNotContainsString('keep running', $item->consequence);
        self::assertStringContainsString('none while any of its five-hour or weekly windows that has not reset yet stands at its limit', $item->consequence);
    }

    #[DataProvider('waits')]
    public function testTheWaitIsTheWholeUnitsSinceTheItemAppeared(DateTimeImmutable $now, string $expected): void
    {
        $item = $this->itemOf();

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

    private function itemOf(): WaitingItem
    {
        return new WaitingItem('Lead', 'Consequence', new DateTimeImmutable('2026-11-02T11:00:00+00:00'), 'Open the status page', 'https://status.example', 'Shows whether the provider reports trouble.', Character::Maintainer);
    }

    private function outage(): OutageNotice
    {
        return new OutageNotice(new Account(Provider::ClaudeCode, 'main'), new DateTimeImmutable('2026-11-02T08:00:00+00:00'), new DateTimeImmutable('2026-11-02T11:00:00+00:00'));
    }
}
