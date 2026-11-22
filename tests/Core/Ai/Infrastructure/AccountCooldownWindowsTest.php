<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountErrorReported;
use Intakt\Core\Ai\Infrastructure\PoolRememberingItsSave;
use Intakt\Core\Budget\Contract\ReadingOutcome;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Budget\Contract\WindowUse;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// On the native clock, because the RateLimiter reads the system time itself (symfony/rate-limiter has no clock option), so a mocked moment would not match its windows.
#[CoversClass(AccountCooldownWindows::class)]
#[CoversClass(PoolRememberingItsSave::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(AccountErrorReported::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
#[UsesClass(WindowUse::class)]
final class AccountCooldownWindowsTest extends TestCase
{
    private ArrayAdapter $pool;
    private InMemoryLedger $ledger;
    private ReadingsInMemory $readings;

    public function testAnAccountThatWasNeverLimitedIsFree(): void
    {
        $cooldowns = $this->cooldowns();

        self::assertFalse($cooldowns->isCoolingDown($this->default()));
        self::assertSame(0, $cooldowns->secondsUntilAnAccountIsFree(Provider::Anthropic));
    }

    public function testALimitedAccountCoolsDownUntilTheVendorsReset(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 300);

        self::assertTrue($cooldowns->isCoolingDown($this->default()));
        self::assertEqualsWithDelta(300, $this->secondsUntilFree($this->default()), 1);
    }

    public function testTheCooldownOfOneAccountLeavesTheOtherAccountOfItsVendorFree(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 300);

        self::assertFalse($cooldowns->isCoolingDown($this->team()));
        self::assertSame(0, $cooldowns->secondsUntilAnAccountIsFree(Provider::Anthropic));
    }

    public function testAnAccountOfAnotherProviderWithTheSameNameStaysFree(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 300);

        self::assertFalse($cooldowns->isCoolingDown(new Account(Provider::OpenAi, Account::DEFAULT)));
    }

    public function testEveryWorkerOnTheSharedCacheSeesTheCooldown(): void
    {
        $this->cooldowns()->coolDown($this->default(), 300);

        self::assertTrue($this->cooldowns()->isCoolingDown($this->default()));
    }

    public function testWhenEveryAccountCoolsDownTheWaitIsUntilTheFirstIsFree(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 300);
        $cooldowns->coolDown($this->team(), 120);

        self::assertEqualsWithDelta(120, $cooldowns->secondsUntilAnAccountIsFree(Provider::Anthropic), 1);
    }

    public function testALimitWithoutAResetCoolsTheAccountDownForOneMinute(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), null);

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAnExhaustedAccountWithAReadingThatResetsInNSecondsCoolsDownForNSeconds(): void
    {
        $this->measured(UsageWindow::FiveHour, 100.0, 3000);

        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(3000, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAnExhaustedAccountWhoseResetIsFurtherThanADayAwayCoolsDownForADay(): void
    {
        $this->measured(UsageWindow::Weekly, 100.0, 5 * 86400);

        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(86400, $this->secondsUntilFree($this->default()), 1);
    }

    public function testTheLaterResetOfTwoExhaustedWindowsDecidesTheCooldown(): void
    {
        $this->measured(UsageWindow::FiveHour, 100.0, 3000);
        $this->measured(UsageWindow::Weekly, 104.0, 7000);

        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(7000, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAnAccountWhoseReadingIsNotExhaustedStillCoolsDownForTheDefaultMinute(): void
    {
        $this->measured(UsageWindow::FiveHour, 99.9, 3000);

        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAnExhaustedReadingWhoseResetHasPassedLeavesTheDefaultMinute(): void
    {
        $this->measured(UsageWindow::FiveHour, 100.0, -10);

        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAnExhaustedReadingWithoutAResetLeavesTheDefaultMinute(): void
    {
        $this->readings->record(new WindowReading($this->default(), UsageWindow::FiveHour, new Usage(100.0, null)));

        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAnAccountWithoutReadingsWaitingForItsPlanStillCoolsDownForTheDefaultMinute(): void
    {
        $this->cooldowns()->coolDownUntilPlanResets($this->default());

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testALimitWithoutAResetIgnoresAnExhaustedReadingAndCoolsDownForOneMinute(): void
    {
        $this->measured(UsageWindow::FiveHour, 100.0, 3000);

        $this->cooldowns()->coolDown($this->default(), null);

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAResetTheProviderGaveWinsOverTheReadings(): void
    {
        $this->measured(UsageWindow::FiveHour, 100.0, 3000);

        $this->cooldowns()->coolDown($this->default(), 300);

        self::assertEqualsWithDelta(300, $this->secondsUntilFree($this->default()), 1);
    }

    public function testARejectedKeyCoolsDownForOneMinuteEvenWhenItsReadingsAreExhausted(): void
    {
        $this->measured(UsageWindow::FiveHour, 100.0, 3000);

        $this->cooldowns()->coolDownRejected($this->default());

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
    }

    public function testARejectedKeyCoolsTheAccountDownForOneMinuteAndIsRememberedAsARejection(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDownRejected($this->default());

        self::assertTrue($cooldowns->isRejected($this->default()));
        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
        self::assertSame(LedgerEventKind::AccountCooledDown, $this->ledger->kinds()[0]);
    }

    public function testALimitedAccountIsNotRememberedAsARejection(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 300);

        self::assertFalse($cooldowns->isRejected($this->default()));
    }

    public function testARejectionIsRememberedOnlyForItsOwnAccount(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDownRejected($this->default());

        self::assertFalse($cooldowns->isRejected($this->team()));
        self::assertFalse($cooldowns->isRejected(new Account(Provider::OpenAi, Account::DEFAULT)));
    }

    public function testARejectedKeyWhileALimitRunsKeepsTheLimitsEndAndIsStillRememberedAsARejection(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDown($this->default(), 300);

        $cooldowns->coolDownRejected($this->default());

        self::assertTrue($cooldowns->isRejected($this->default()));
        self::assertEqualsWithDelta(300, $this->secondsUntilFree($this->default()), 1);
    }

    public function testAProviderWhoseEveryAccountRejectedItsKeyHasEveryAccountRejected(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDownRejected($this->default());
        $cooldowns->coolDownRejected($this->team());

        self::assertTrue($cooldowns->isEveryAccountRejected(Provider::Anthropic));
    }

    public function testAProviderWithOneAccountNotRejectedHasNotEveryAccountRejected(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDownRejected($this->default());
        $cooldowns->coolDown($this->team(), 300);

        self::assertFalse($cooldowns->isEveryAccountRejected(Provider::Anthropic));
    }

    public function testAProviderWithoutAccountsHasNotEveryAccountRejected(): void
    {
        self::assertFalse($this->cooldowns()->isEveryAccountRejected(Provider::ClaudeCode));
    }

    public function testEveryWorkerOnTheSharedCacheSeesTheRejection(): void
    {
        $this->cooldowns()->coolDownRejected($this->default());

        self::assertTrue($this->cooldowns()->isRejected($this->default()));
    }

    public function testAResetThatIsNowOrPastLeavesTheAccountFreeAndRecordsNothing(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 0);
        $cooldowns->coolDown($this->default(), -30);

        self::assertFalse($cooldowns->isCoolingDown($this->default()));
        self::assertSame(0, $this->ledger->count());
    }

    public function testAHugeResetIsCappedAtOneDay(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), PHP_INT_MAX);

        self::assertEqualsWithDelta(86400, $this->secondsUntilFree($this->default()), 1);
    }

    public function testACooldownIsRecordedInTheLedgerWithItsAccountAndEnd(): void
    {
        $this->cooldowns()->coolDown($this->team(), 300);

        self::assertSame([LedgerEventKind::AccountCooledDown], $this->ledger->kinds());
        $payload = $this->ledger->payloads()[0];
        self::assertSame('anthropic.team', $payload['account']);
        self::assertIsString($payload['until']);
    }

    // Decision 73 of #2860: the limiter dates its windows by the system time, so a clock set to a past date must not make a saved window read as refused.
    public function testACooldownUnderAClockAtAPastDateIsRecordedAndLogsNoWarning(): void
    {
        $logger = new BufferingLogger();
        $cooldowns = new AccountCooldownWindows($this->readings, $this->pool, new LockFactory(new InMemoryStore()), new MockClock('2026-11-02 12:00:00.000 UTC'), $this->ledger, $logger, StoredSerializerFactory::create(), $this->accounts());

        $cooldowns->coolDown($this->default(), 300);

        self::assertSame([LedgerEventKind::AccountCooledDown], $this->ledger->kinds());
        self::assertSame('2026-11-02T12:05:00+00:00', $this->ledger->payloads()[0]['until']);
        self::assertSame([], $logger->cleanLogs());
    }

    public function testASecondLimitWhileTheAccountCoolsDownKeepsTheFirstCooldownAndRecordsNothing(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDown($this->default(), 120);
        $cooldowns->coolDown($this->default(), 600);

        self::assertEqualsWithDelta(120, $this->secondsUntilFree($this->default()), 1);
        self::assertCount(1, $this->ledger->kinds());
    }

    public function testACooldownTheLedgerRefusesIsLoggedAndStillHolds(): void
    {
        $logger = new BufferingLogger();
        $cooldowns = new AccountCooldownWindows($this->readings, $this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(accepted: 0), $logger, StoredSerializerFactory::create(), $this->accounts());

        $cooldowns->coolDown($this->default(), 300);

        self::assertTrue($cooldowns->isCoolingDown($this->default()));
        /** @var list<array{0: string, 1: string}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('error', $logs[0][0]);
    }

    // Forced order (decision 67 of #2860): the first worker's save is refused and a second worker cools the account for 60 s before the first learns its save was refused, so only the other worker's window is kept and recorded.
    public function testARefusedSaveFollowedByAnotherWorkersCooldownRecordsOnlyTheOtherCooldownAndIsLogged(): void
    {
        $pool = new RefusingASaveAfterAStep();
        $logger = new BufferingLogger();
        $other = new AccountCooldownWindows($this->readings, $pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $first = new AccountCooldownWindows($this->readings, $pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, $logger, StoredSerializerFactory::create(), $this->accounts());
        $pool->step = fn () => $other->coolDown($this->default(), 60);

        $first->coolDown($this->default(), 120);

        self::assertSame([LedgerEventKind::AccountCooledDown], $this->ledger->kinds());
        $until = $this->ledger->payloads()[0]['until'];
        self::assertIsString($until);
        $end = DateTimeImmutable::createFromFormat(DATE_ATOM, $until, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $end);
        self::assertEqualsWithDelta(60, $end->getTimestamp() - new NativeClock()->now()->getTimestamp(), 1);
        self::assertEqualsWithDelta(60, $other->secondsUntilFree($this->default()), 1);
        self::assertContains('The cooldown of account {account} could not be saved.', array_column($logger->cleanLogs(), 1));
    }

    // Forced order (decision 77 of #2860): the first worker's save is refused while a second worker saves a window with the same end, so only the cache's own result can tell the two apart.
    public function testARefusedSaveBesideAnotherWorkersWindowWithTheSameEndRecordsOneCooldownAndIsLoggedOnce(): void
    {
        $pool = new RefusingASaveAfterAStep();
        $logger = new BufferingLogger();
        $other = new AccountCooldownWindows($this->readings, $pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $first = new AccountCooldownWindows($this->readings, $pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, $logger, StoredSerializerFactory::create(), $this->accounts());
        $pool->step = fn () => $other->coolDown($this->default(), 120);

        $first->coolDown($this->default(), 120);

        self::assertSame([LedgerEventKind::AccountCooledDown], $this->ledger->kinds());
        self::assertEqualsWithDelta(120, $other->secondsUntilFree($this->default()), 1);
        self::assertSame(['The cooldown of account {account} could not be saved.'], array_column($logger->cleanLogs(), 1));
    }

    public function testARejectionIsReportedToTheOwnerWithItsAccount(): void
    {
        $this->cooldowns()->coolDownRejected($this->team());

        self::assertSame([LedgerEventKind::AccountCooledDown, LedgerEventKind::AccountErrorReported], $this->ledger->kinds());
        $payload = $this->ledger->payloads()[1];
        self::assertSame('anthropic.team', $payload['account']);
        self::assertIsString($payload['reported_at']);
    }

    public function testALimitIsNoAccountErrorAndIsNotReported(): void
    {
        $this->cooldowns()->coolDown($this->team(), 300);

        self::assertNotContains(LedgerEventKind::AccountErrorReported, $this->ledger->kinds());
    }

    public function testTheSameAccountErrorOnAnotherWorkerIsNotReportedAgain(): void
    {
        $this->cooldowns()->coolDownRejected($this->team());

        $this->cooldowns()->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
    }

    public function testAnAccountErrorOfAnotherAccountIsReportedOnItsOwn(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDownRejected($this->team());
        $cooldowns->coolDownRejected($this->default());

        self::assertSame(['anthropic.team', 'anthropic.default'], array_column($this->reports(), 'account'));
    }

    public function testAnAnswerToACallSentBeforeTheReportKeepsTheErrorReported(): void
    {
        $cooldowns = $this->cooldowns();
        $sentAt = new NativeClock()->now()->modify('-1 second');
        $cooldowns->coolDownRejected($this->team());

        $cooldowns->answered($this->team(), $sentAt);
        $cooldowns->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
    }

    public function testAnAnswerToACallSentAfterTheReportLetsTheNextErrorBeReported(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDownRejected($this->team());

        $cooldowns->answered($this->team(), new NativeClock()->now()->modify('+1 second'));
        $cooldowns->coolDownRejected($this->team());

        self::assertCount(2, $this->reports());
    }

    public function testAnAnswerToACallSentAtTheMomentOfTheReportKeepsTheErrorReported(): void
    {
        $clock = new MockClock();
        $cooldowns = new AccountCooldownWindows($this->readings, $this->pool, new LockFactory(new InMemoryStore()), $clock, $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $cooldowns->coolDownRejected($this->team());

        $cooldowns->answered($this->team(), $clock->now());
        $cooldowns->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
    }

    public function testAnAnswerOfAnotherAccountKeepsTheErrorReported(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->coolDownRejected($this->team());

        $cooldowns->answered($this->default(), new NativeClock()->now()->modify('+1 second'));
        $cooldowns->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
    }

    public function testAnAccountErrorAnotherWorkerIsReportingRightNowIsLeftToIt(): void
    {
        $store = new InMemoryStore();
        $reporting = new LockFactory($store)->createLock('account-cooldown-reported-anthropic.team');
        $reporting->acquire();
        $cooldowns = new AccountCooldownWindows($this->readings, $this->pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());

        $cooldowns->coolDownRejected($this->team());

        self::assertSame([], $this->reports());
        self::assertTrue($cooldowns->isRejected($this->team()));
    }

    // The error comes from another worker while this one still holds the lock it cleared the report under, so that worker finds the lock busy.
    public function testAnAccountErrorWhileTheReportIsClearedIsReportedOnceTheLockIsReleased(): void
    {
        $pool = new SteppingInAfterDeleting('account-cooldown-reported-anthropic.team');
        $store = new InMemoryStore();
        $cooldowns = new AccountCooldownWindows($this->readings, $pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $otherWorker = new AccountCooldownWindows($this->readings, $pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $cooldowns->coolDownRejected($this->team());
        $pool->step = fn () => $otherWorker->coolDownRejected($this->team());

        $cooldowns->answered($this->team(), new NativeClock()->now()->modify('+1 second'));

        self::assertCount(2, $this->reports());
    }

    // The error comes from another worker while this one holds the lock it reports the same error under, so that worker finds the lock busy.
    public function testTheSameAccountErrorWhileAnotherWorkerReportsItIsNotReportedAgainOnceTheLockIsReleased(): void
    {
        $pool = new SteppingInAfterDeleting('account-cooldown-pending-anthropic.team');
        $store = new InMemoryStore();
        $cooldowns = new AccountCooldownWindows($this->readings, $pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $otherWorker = new AccountCooldownWindows($this->readings, $pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $pool->step = fn () => $otherWorker->coolDownRejected($this->team());

        $cooldowns->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
        self::assertFalse($pool->hasItem('account-cooldown-pending-anthropic.team'));
    }

    // The other worker reports the same error between this worker's look at the pending item and its lock, and an answer to a later call clears that report, so this worker must find nothing pending under the lock.
    public function testAWorkerThatReadThePendingItemBeforeTheLockDoesNotReportTheSameErrorAgain(): void
    {
        $pool = new SteppingInAfterReading('account-cooldown-pending-anthropic.team');
        $store = new InMemoryStore();
        $clock = new MockClock('2026-11-04T12:00:00Z');
        $cooldowns = new AccountCooldownWindows($this->readings, $pool, new LockFactory($store), $clock, $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $otherWorker = new AccountCooldownWindows($this->readings, $pool, new LockFactory($store), $clock, $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $pool->step = function () use ($otherWorker, $clock): void {
            $otherWorker->coolDownRejected($this->team());
            $clock->sleep(1);
            $otherWorker->answered($this->team(), $clock->now());
        };

        $cooldowns->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
    }

    public function testAReportTheLedgerRefusesIsLoggedAndMadeAgainOnTheNextError(): void
    {
        $logger = new BufferingLogger();
        $ledger = new InMemoryLedger(accepted: 1);
        $cooldowns = new AccountCooldownWindows($this->readings, $this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $ledger, $logger, StoredSerializerFactory::create(), $this->accounts());
        $cooldowns->coolDownRejected($this->team());

        $cooldowns->coolDownRejected($this->team());

        /** @var list<array{0: string, 1: string}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame([['error', 'An account error was not reported.'], ['error', 'An account error was not reported.']], array_map(static fn (array $log): array => [$log[0], $log[1]], $logs));
    }

    public function testAProviderWithoutAccountsHasNothingToWaitFor(): void
    {
        self::assertSame(0, $this->cooldowns()->secondsUntilAnAccountIsFree(Provider::ClaudeCode));
    }

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->ledger = new InMemoryLedger();
        $this->readings = new ReadingsInMemory(new NativeClock());
    }

    private function measured(UsageWindow $window, float $usedPercent, int $resetInSeconds): void
    {
        $resetsAt = new NativeClock()->now()->modify(sprintf('%+d seconds', $resetInSeconds));
        $outcome = $this->readings->record(new WindowReading($this->default(), $window, new Usage($usedPercent, $resetsAt)));

        self::assertSame(ReadingOutcome::Recorded, $outcome);
    }

    // Alone on its provider, so the provider's wait is the account's own.
    private function secondsUntilFree(Account $account): int
    {
        return new AccountCooldownWindows($this->readings, $this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [$account->name]])
            ->secondsUntilAnAccountIsFree(Provider::Anthropic);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reports(): array
    {
        $kinds = $this->ledger->kinds();

        return array_values(array_filter($this->ledger->payloads(), static fn (int $index): bool => LedgerEventKind::AccountErrorReported === $kinds[$index], ARRAY_FILTER_USE_KEY));
    }

    private function cooldowns(): AccountCooldownWindows
    {
        return new AccountCooldownWindows($this->readings, $this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
    }

    /**
     * @return array<string, list<string>>
     */
    private function accounts(): array
    {
        return [Provider::Anthropic->value => [Account::DEFAULT, 'team'], Provider::OpenAi->value => [Account::DEFAULT]];
    }

    private function default(): Account
    {
        return new Account(Provider::Anthropic, Account::DEFAULT);
    }

    private function team(): Account
    {
        return new Account(Provider::Anthropic, 'team');
    }
}
