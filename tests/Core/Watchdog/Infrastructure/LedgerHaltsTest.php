<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Infrastructure;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Domain\HaltClearedPayload;
use Intakt\Core\Watchdog\Domain\HaltPayload;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Intakt\Tests\Core\Watchdog\HaltLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerHalts::class)]
#[CoversClass(OwnerCard::class)]
#[CoversClass(HaltNotRecorded::class)]
#[CoversClass(HaltPayload::class)]
#[CoversClass(HaltClearedPayload::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(AppendResult::class)]
final class LedgerHaltsTest extends TestCase
{
    private const string HEAD = 'cccccccccccccccccccccccccccccccccccccccc';

    private HaltLedger $ledger;
    private LedgerHalts $halts;

    public function testARaisedHaltStandsAsACardNamingItsProjectCommitAndReason(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);

        $card = $this->onlyCard($this->halts->standing());
        self::assertSame('acme/shop', $card->project);
        self::assertSame(str_repeat('a', 40), $card->violation?->commit);
        self::assertSame(Violation::Unsigned, $card->violation->violation);
        self::assertSame([LedgerEventKind::MainHalted], $this->ledger->kinds());
    }

    public function testAClearThatNamesTheCardLiftsIt(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);

        $this->halts->clear($this->onlyCard($this->halts->standing()));

        self::assertSame([], $this->halts->standing());
        self::assertSame([LedgerEventKind::MainHalted, LedgerEventKind::HaltCleared], $this->ledger->kinds());
    }

    public function testAHaltRaisedAfterAClearStands(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);
        $this->halts->clear($this->onlyCard($this->halts->standing()));

        $this->halts->raise('acme/shop', $this->unsigned('b'), self::HEAD);

        self::assertSame(str_repeat('b', 40), $this->onlyCard($this->halts->standing())->violation?->commit);
    }

    public function testACardOfAnotherProjectDoesNotHaltThisOne(): void
    {
        $this->halts->raise('acme/blog', $this->unsigned('a'), self::HEAD);

        self::assertSame([], $this->halts->standingOf('acme/shop'));
        self::assertCount(1, $this->halts->standingOf('acme/blog'));
    }

    public function testAHaltRowThatDoesNotReadStillHaltsEveryProject(): void
    {
        $this->appended(new LedgerEvent(LedgerEventKind::MainHalted, '{"project":"acme/shop"}'));

        $card = $this->onlyCard($this->halts->standingOf('acme/blog'));
        self::assertFalse($card->isReadable());
    }

    public function testAHaltRowWithAnAbbreviatedCommitStillHalts(): void
    {
        $this->appended(new LedgerEvent(LedgerEventKind::MainHalted, '{"project":"acme/shop","commit":"abc","violation":"unsigned"}'));

        self::assertFalse($this->onlyCard($this->halts->standing())->isReadable());
    }

    public function testAClearRowThatDoesNotReadLiftsNothing(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);
        $this->appended(new LedgerEvent(LedgerEventKind::HaltCleared, '{"cards":[]}'));

        self::assertCount(1, $this->halts->standing());
    }

    public function testAClearedCardStillCountsAsRaised(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);
        $this->halts->clear($this->onlyCard($this->halts->standing()));

        self::assertTrue($this->halts->wasRaised('acme/shop', $this->unsigned('a'), self::HEAD));
    }

    public function testTheSameCommitWithAnotherReasonOrProjectWasNotRaised(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);

        self::assertFalse($this->halts->wasRaised('acme/shop', $this->rewritten('a'), self::HEAD));
        self::assertFalse($this->halts->wasRaised('acme/blog', $this->unsigned('a'), self::HEAD));
    }

    public function testTheSameRewriteSeenTwiceWasRaised(): void
    {
        $this->halts->raise('acme/shop', $this->rewritten('a'), self::HEAD);

        self::assertTrue($this->halts->wasRaised('acme/shop', $this->rewritten('a'), self::HEAD));
    }

    public function testARewriteOfTheSameHeadToAnotherCommitWasNotRaised(): void
    {
        $this->halts->raise('acme/shop', $this->rewritten('a'), self::HEAD);

        self::assertFalse($this->halts->wasRaised('acme/shop', $this->rewritten('a'), str_repeat('d', 40)));
    }

    public function testAnUnsignedCommitSeenUnderAnotherHeadWasStillRaised(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);

        self::assertTrue($this->halts->wasRaised('acme/shop', $this->unsigned('a'), str_repeat('d', 40)));
    }

    public function testAHaltWrittenWithoutMainsHeadStillStandsWithItsProjectAndReason(): void
    {
        $this->appended(new LedgerEvent(LedgerEventKind::MainHalted, '{"project":"acme/shop","commit":"'.str_repeat('a', 40).'","violation":"unsigned"}'));

        $card = $this->onlyCard($this->halts->standing());
        self::assertSame('acme/shop', $card->project);
        self::assertSame(Violation::Unsigned, $card->violation?->violation);
        self::assertTrue($this->halts->wasRaised('acme/shop', $this->unsigned('a'), self::HEAD));
    }

    public function testARewriteWrittenWithoutMainsHeadDoesNotCoverALaterRewrite(): void
    {
        $this->appended(new LedgerEvent(LedgerEventKind::MainHalted, '{"project":"acme/shop","commit":"'.str_repeat('a', 40).'","violation":"history_rewritten"}'));

        self::assertFalse($this->halts->wasRaised('acme/shop', $this->rewritten('a'), self::HEAD));
    }

    public function testAHaltTheLedgerRefusesIsReportedNotRecorded(): void
    {
        $this->ledger->refuseAppends();

        $this->expectException(HaltNotRecorded::class);

        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);
    }

    public function testAClearTheLedgerRefusesIsReportedAndTheCardStands(): void
    {
        $this->halts->raise('acme/shop', $this->unsigned('a'), self::HEAD);
        $this->ledger->refuseAppends();

        try {
            $this->halts->clear($this->onlyCard($this->halts->standing()));
            self::fail('The refused clear was not reported.');
        } catch (HaltNotRecorded) {
            self::assertCount(1, $this->halts->standing());
        }
    }

    protected function setUp(): void
    {
        $this->ledger = new HaltLedger();
        $this->halts = new LedgerHalts($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()));
    }

    private function unsigned(string $digit): MainViolation
    {
        return new MainViolation(str_repeat($digit, 40), Violation::Unsigned);
    }

    private function rewritten(string $digit): MainViolation
    {
        return new MainViolation(str_repeat($digit, 40), Violation::HistoryRewritten);
    }

    private function appended(LedgerEvent $event): void
    {
        self::assertTrue($this->ledger->append($event)->succeeded());
    }

    /**
     * @param list<OwnerCard> $cards
     */
    private function onlyCard(array $cards): OwnerCard
    {
        self::assertCount(1, $cards);

        return $cards[0];
    }
}
