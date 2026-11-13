<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountErrorReported;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
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
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(AccountErrorReported::class)]
final class AccountCooldownWindowsTest extends TestCase
{
    private ArrayAdapter $pool;
    private InMemoryLedger $ledger;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
        $this->ledger = new InMemoryLedger();
    }

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
        $cooldowns = new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(accepted: 0), $logger, StoredSerializerFactory::create(), $this->accounts());

        $cooldowns->coolDown($this->default(), 300);

        self::assertTrue($cooldowns->isCoolingDown($this->default()));
        /** @var list<array{0: string, 1: string}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('error', $logs[0][0]);
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
        $cooldowns = new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), $clock, $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
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
        $cooldowns = new AccountCooldownWindows($this->pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());

        $cooldowns->coolDownRejected($this->team());

        self::assertSame([], $this->reports());
        self::assertTrue($cooldowns->isRejected($this->team()));
    }

    // The error comes from another worker while this one still holds the lock it cleared the report under, so that worker finds the lock busy.
    public function testAnAccountErrorWhileTheReportIsClearedIsReportedOnceTheLockIsReleased(): void
    {
        $pool = new SteppingInAfterDeleting('account-cooldown-reported-anthropic.team');
        $store = new InMemoryStore();
        $cooldowns = new AccountCooldownWindows($pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $otherWorker = new AccountCooldownWindows($pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $cooldowns->coolDownRejected($this->team());
        $pool->step = fn () => $otherWorker->coolDownRejected($this->team());

        $cooldowns->answered($this->team(), new NativeClock()->now()->modify('+1 second'));

        self::assertCount(2, $this->reports());
    }

    // The error comes from another worker while this one holds the lock it reports the same error under.
    public function testTheSameAccountErrorWhileAnotherWorkerReportsItIsNotReportedAgainOnceTheLockIsReleased(): void
    {
        $pool = new SteppingInAfterDeleting('account-cooldown-pending-anthropic.team');
        $store = new InMemoryStore();
        $cooldowns = new AccountCooldownWindows($pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $otherWorker = new AccountCooldownWindows($pool, new LockFactory($store), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
        $pool->step = fn () => $otherWorker->coolDownRejected($this->team());

        $cooldowns->coolDownRejected($this->team());

        self::assertCount(1, $this->reports());
        self::assertFalse($pool->hasItem('account-cooldown-pending-anthropic.team'));
    }

    public function testAReportTheLedgerRefusesIsLoggedAndMadeAgainOnTheNextError(): void
    {
        $logger = new BufferingLogger();
        $ledger = new InMemoryLedger(accepted: 1);
        $cooldowns = new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $ledger, $logger, StoredSerializerFactory::create(), $this->accounts());
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

    // Alone on its provider, so the provider's wait is the account's own.
    private function secondsUntilFree(Account $account): int
    {
        return new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [$account->name]])
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
        return new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), StoredSerializerFactory::create(), $this->accounts());
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
