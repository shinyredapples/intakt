<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Budget\Domain\AccountRoom;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\MeasuredRoomRanking;
use Intakt\Tests\Core\Budget\HoldsInMemory;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MeasuredRoomRanking::class)]
#[CoversClass(AccountRoom::class)]
#[CoversClass(CeilingStartGuard::class)]
#[UsesClass(Account::class)]
#[UsesClass(LeftOutAccount::class)]
#[UsesClass(RankedAccounts::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class MeasuredRoomRankingTest extends TestCase
{
    private MockClock $clock;
    private ReadingsInMemory $readings;
    private HoldsInMemory $holds;

    public function testAnAccountAtItsWeeklyCeilingIsLeftOutWithTheWeeklyCause(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(95.0, 60));
        $this->both('second', $this->usage(10.0, 5), $this->usage(20.0, 60));

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
        self::assertSame(LeftOutCause::AtWeeklyCeiling, $ranked->leftOut[0]->cause);
        self::assertSame('first', $ranked->leftOut[0]->account->name);
        self::assertEquals($this->clock->now()->modify('+60 hours'), $ranked->leftOut[0]->resetsAt);
    }

    public function testAnAccountAtItsShortWindowCeilingIsLeftOutWithTheShortWindowCause(): void
    {
        $this->both('first', $this->usage(95.0, 2), $this->usage(20.0, 60));

        $ranked = $this->rank('first');

        self::assertSame([], $this->names($ranked));
        self::assertSame(LeftOutCause::AtShortWindowCeiling, $ranked->leftOut[0]->cause);
        self::assertEquals($this->clock->now()->modify('+2 hours'), $ranked->leftOut[0]->resetsAt);
    }

    public function testAnAccountCoolingDownIsLeftOutUntilItsHoldEnds(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(20.0, 60));
        $this->holds->coolDown(new Account(Provider::ClaudeCode, 'first'), $this->clock->now()->modify('+1 hour'));

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
        self::assertSame(LeftOutCause::CoolingDown, $ranked->leftOut[0]->cause);
        self::assertEquals($this->clock->now()->modify('+1 hour'), $ranked->leftOut[0]->resetsAt);
    }

    public function testAnAccountCoolingDownAtAFreshCeilingThatOutlastsItsHoldIsLeftOutUntilTheReset(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(95.0, 60));
        $this->holds->coolDown(new Account(Provider::ClaudeCode, 'first'), $this->clock->now()->modify('+1 hour'));

        $ranked = $this->rank('first');

        self::assertSame(LeftOutCause::CoolingDown, $ranked->leftOut[0]->cause);
        self::assertEquals($this->clock->now()->modify('+60 hours'), $ranked->leftOut[0]->resetsAt);
    }

    public function testAnAccountWhoseHoldOutlastsItsFreshCeilingIsLeftOutUntilTheHoldEnds(): void
    {
        $this->both('first', $this->usage(95.0, 2), $this->usage(20.0, 60));
        $this->holds->coolDown(new Account(Provider::ClaudeCode, 'first'), $this->clock->now()->modify('+3 hours'));

        self::assertEquals($this->clock->now()->modify('+3 hours'), $this->rank('first')->leftOut[0]->resetsAt);
    }

    public function testAnAccountCoolingDownAtAFreshCeilingWithoutAResetHasNoKnownEnd(): void
    {
        $account = new Account(Provider::ClaudeCode, 'first');
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(95.0, null)));
        $this->holds->coolDown($account, $this->clock->now()->modify('+1 hour'));

        $ranked = $this->rank('first');

        self::assertSame(LeftOutCause::CoolingDown, $ranked->leftOut[0]->cause);
        self::assertNull($ranked->leftOut[0]->resetsAt);
    }

    public function testAHoldOverAStaleCeilingReadingEndsWithTheHold(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(95.0, 48));
        $this->clock->modify('+'.(CeilingStartGuard::TRUSTED_FOR_SECONDS + 1).' seconds');
        $this->holds->coolDown(new Account(Provider::ClaudeCode, 'first'), $this->clock->now()->modify('+1 hour'));

        self::assertEquals($this->clock->now()->modify('+1 hour'), $this->rank('first')->leftOut[0]->resetsAt);
    }

    public function testAnAccountWhoseCeilingReadingIsOlderThanTheProbeIntervalIsStillRankedWhenItIsTheOnlyOne(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(95.0, 48));
        $this->clock->modify('+'.(CeilingStartGuard::TRUSTED_FOR_SECONDS + 1).' seconds');

        $ranked = $this->rank('first');

        self::assertSame(['first'], $this->names($ranked));
        self::assertSame([], $ranked->leftOut);
    }

    public function testAnAccountWhoseCeilingReadingIsOlderThanTheProbeIntervalRanksAfterATrustedOneWithRoom(): void
    {
        $this->both('first', $this->usage(10.0, 1), $this->usage(95.0, 48));
        $this->clock->modify('+'.(CeilingStartGuard::TRUSTED_FOR_SECONDS + 1).' seconds');
        $this->both('second', $this->usage(10.0, 5), $this->usage(20.0, 60));

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testACeilingReadingRightAtTheProbeIntervalStillLeavesTheAccountOut(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(95.0, 48));
        $this->clock->modify('+'.CeilingStartGuard::TRUSTED_FOR_SECONDS.' seconds');

        self::assertSame([], $this->names($this->rank('first')));
    }

    public function testOfTwoAccountsWithRoomTheOneWhoseWindowResetsSoonerRanksFirst(): void
    {
        $this->both('first', $this->usage(10.0, 4), $this->usage(20.0, 60));
        $this->both('second', $this->usage(10.0, 1), $this->usage(20.0, 60));

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testAccountsWithTheSameResetKeepTheOwnersOrder(): void
    {
        $this->both('first', $this->usage(10.0, 3), $this->usage(20.0, 60));
        $this->both('second', $this->usage(10.0, 3), $this->usage(20.0, 60));

        self::assertSame(['first', 'second'], $this->names($this->rank('first', 'second')));
        self::assertSame(['second', 'first'], $this->names($this->rank('second', 'first')));
    }

    public function testAnAccountWhoseReadingIsOlderThanTheProbeIntervalRanksAfterATrustedOne(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(20.0, 60));
        $this->clock->modify('+'.(CeilingStartGuard::TRUSTED_FOR_SECONDS + 1).' seconds');
        $this->both('second', $this->usage(10.0, 5), $this->usage(20.0, 60));

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testAnAccountWithoutAReadingRanksAfterEveryTrustedAccountEvenOneThatResetsLater(): void
    {
        $this->both('second', $this->usage(10.0, 5), $this->usage(20.0, 100));

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testAnUnknownAccountIsStillRankedWhenNoTrustedOneHasRoom(): void
    {
        $this->both('first', $this->usage(10.0, 5), $this->usage(95.0, 60));

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
    }

    public function testAPaidAccountRanksAfterASubscriptionWithRoomWhateverItsPlace(): void
    {
        $this->both('team', $this->usage(10.0, 5), $this->usage(20.0, 100));

        $ranked = new MeasuredRoomRanking($this->holds, $this->guard())->rank([new Account(Provider::Anthropic, 'key'), new Account(Provider::ClaudeCode, 'team')]);

        self::assertSame(['claude_code.team', 'anthropic.key'], array_map(static fn (Account $account): string => $account->key(), $ranked->inOrder));
    }

    public function testEachAccountIsRankedFromItsOwnReadingsOnly(): void
    {
        $this->both('first', $this->usage(95.0, 2), $this->usage(20.0, 60));
        $this->both('second', $this->usage(10.0, 2), $this->usage(20.0, 60));
        $other = new Account(Provider::Codex, 'first');
        $this->readings->record(new WindowReading($other, UsageWindow::Weekly, new Usage(99.0, $this->clock->now()->modify('+60 hours'))));

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
        self::assertSame('claude_code.first', $ranked->leftOut[0]->account->key());
    }

    public function testAnAccountWhoseCeilingWindowHasResetIsRankedAgain(): void
    {
        $this->both('first', $this->usage(95.0, 2), $this->usage(20.0, 60));
        $this->clock->modify('+3 hours');

        self::assertSame(['first'], $this->names($this->rank('first')));
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-03-02 10:00:00 UTC');
        $this->readings = new ReadingsInMemory($this->clock);
        $this->holds = new HoldsInMemory($this->clock);
    }

    private function usage(float $percent, int $resetsInHours): Usage
    {
        return new Usage($percent, $this->clock->now()->modify('+'.$resetsInHours.' hours'));
    }

    private function both(string $name, Usage $short, Usage $weekly): void
    {
        $account = new Account(Provider::ClaudeCode, $name);
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, $short));
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, $weekly));
    }

    private function rank(string ...$names): RankedAccounts
    {
        return new MeasuredRoomRanking($this->holds, $this->guard())->rank(array_values(array_map(static fn (string $name): Account => new Account(Provider::ClaudeCode, $name), $names)));
    }

    private function guard(): CeilingStartGuard
    {
        return new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]);
    }

    /**
     * @return list<string>
     */
    private function names(RankedAccounts $ranked): array
    {
        return array_map(static fn (Account $account): string => $account->name, $ranked->inOrder);
    }
}
