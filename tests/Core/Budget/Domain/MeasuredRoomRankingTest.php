<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\Domain;

use DateTimeImmutable;
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
        $this->read('first', 10.0, 95.0, 5, 60);
        $this->read('second', 10.0, 20.0, 5, 60);

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
        self::assertSame(LeftOutCause::AtWeeklyCeiling, $ranked->leftOut[0]->cause);
        self::assertSame('first', $ranked->leftOut[0]->account->name);
        self::assertEquals($this->clock->now()->modify('+60 hours'), $ranked->leftOut[0]->resetsAt);
    }

    public function testAnAccountAtItsShortWindowCeilingIsLeftOutWithTheShortWindowCause(): void
    {
        $this->read('first', 95.0, 20.0, 2, 60);

        $ranked = $this->rank('first');

        self::assertSame([], $this->names($ranked));
        self::assertSame(LeftOutCause::AtShortWindowCeiling, $ranked->leftOut[0]->cause);
        self::assertEquals($this->clock->now()->modify('+2 hours'), $ranked->leftOut[0]->resetsAt);
    }

    public function testAnAccountCoolingDownIsLeftOutWithoutAReset(): void
    {
        $this->read('first', 10.0, 20.0, 5, 60);
        $this->holds->coolDown(new Account(Provider::ClaudeCode, 'first'), $this->clock->now()->modify('+1 hour'));

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
        self::assertSame(LeftOutCause::CoolingDown, $ranked->leftOut[0]->cause);
        self::assertNull($ranked->leftOut[0]->resetsAt);
    }

    public function testOfTwoAccountsWithRoomTheOneWhoseWindowResetsSoonerRanksFirst(): void
    {
        $this->read('first', 10.0, 20.0, 4, 60);
        $this->read('second', 10.0, 20.0, 1, 60);

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testAccountsWithTheSameResetKeepTheOwnersOrder(): void
    {
        $this->read('first', 10.0, 20.0, 3, 60);
        $this->read('second', 10.0, 20.0, 3, 60);

        self::assertSame(['first', 'second'], $this->names($this->rank('first', 'second')));
        self::assertSame(['second', 'first'], $this->names($this->rank('second', 'first')));
    }

    public function testAnAccountWhoseReadingIsOlderThanTheProbeIntervalRanksAfterATrustedOne(): void
    {
        $this->read('first', 10.0, 20.0, 5, 60);
        $this->clock->modify('+'.(CeilingStartGuard::TRUSTED_FOR_SECONDS + 1).' seconds');
        $this->read('second', 10.0, 20.0, 5, 60);

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testAnAccountWithoutAReadingRanksAfterEveryTrustedAccountEvenOneThatResetsLater(): void
    {
        $this->read('second', 10.0, 20.0, 5, 100);

        self::assertSame(['second', 'first'], $this->names($this->rank('first', 'second')));
    }

    public function testAnUnknownAccountIsStillRankedWhenNoTrustedOneHasRoom(): void
    {
        $this->read('first', 10.0, 95.0, 5, 60);

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
    }

    public function testAPaidAccountRanksAfterASubscriptionWithRoomWhateverItsPlace(): void
    {
        $this->read('team', 10.0, 20.0, 5, 100);

        $ranked = new MeasuredRoomRanking($this->holds, $this->guard())->rank([new Account(Provider::Anthropic, 'key'), new Account(Provider::ClaudeCode, 'team')]);

        self::assertSame(['claude_code.team', 'anthropic.key'], array_map(static fn (Account $account): string => $account->key(), $ranked->inOrder));
    }

    public function testEachAccountIsRankedFromItsOwnReadingsOnly(): void
    {
        $this->read('first', 95.0, 20.0, 2, 60);
        $this->read('second', 10.0, 20.0, 2, 60);
        $other = new Account(Provider::Codex, 'first');
        $this->readings->record(new WindowReading($other, UsageWindow::Weekly, new Usage(99.0, $this->clock->now()->modify('+60 hours'))));

        $ranked = $this->rank('first', 'second');

        self::assertSame(['second'], $this->names($ranked));
        self::assertSame('claude_code.first', $ranked->leftOut[0]->account->key());
    }

    public function testAnAccountWhoseCeilingWindowHasResetIsRankedAgain(): void
    {
        $this->read('first', 95.0, 20.0, 2, 60);
        $this->clock->modify('+3 hours');

        self::assertSame(['first'], $this->names($this->rank('first')));
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock(new DateTimeImmutable('2026-03-02 10:00:00 UTC'));
        $this->readings = new ReadingsInMemory($this->clock);
        $this->holds = new HoldsInMemory($this->clock);
    }

    private function read(string $name, float $short, float $weekly, int $shortResetsInHours, int $weeklyResetsInHours): void
    {
        $account = new Account(Provider::ClaudeCode, $name);
        $this->readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage($short, $this->clock->now()->modify('+'.$shortResetsInHours.' hours'))));
        $this->readings->record(new WindowReading($account, UsageWindow::Weekly, new Usage($weekly, $this->clock->now()->modify('+'.$weeklyResetsInHours.' hours'))));
    }

    private function guard(): CeilingStartGuard
    {
        return new CeilingStartGuard($this->readings, $this->clock, ['five-hour' => 90.0, 'weekly' => 90.0]);
    }

    private function rank(string ...$names): RankedAccounts
    {
        return new MeasuredRoomRanking($this->holds, $this->guard())->rank(array_map(static fn (string $name): Account => new Account(Provider::ClaudeCode, $name), $names));
    }

    /**
     * @return list<string>
     */
    private function names(RankedAccounts $ranked): array
    {
        return array_map(static fn (Account $account): string => $account->name, $ranked->inOrder);
    }
}
