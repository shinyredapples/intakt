<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountError;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// On the native clock, because the RateLimiter reads the system time itself (symfony/rate-limiter has no clock option), so a mocked moment would not match its windows.
#[CoversClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(AccountError::class)]
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
        self::assertSame([LedgerEventKind::AccountCooledDown], $this->ledger->kinds());
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
        $cooldowns = new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(accepted: 0), $logger, $this->accounts());

        $cooldowns->coolDown($this->default(), 300);

        self::assertTrue($cooldowns->isCoolingDown($this->default()));
        /** @var list<array{0: string, 1: string}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('error', $logs[0][0]);
    }

    public function testAnAccountErrorCoolsTheAccountDownForOneMinuteWithoutRememberingARejection(): void
    {
        $cooldowns = $this->cooldowns();

        $cooldowns->coolDownUnusable($this->default(), AccountError::LoginLost);

        self::assertEqualsWithDelta(60, $this->secondsUntilFree($this->default()), 1);
        self::assertFalse($cooldowns->isRejected($this->default()));
    }

    public function testAnAccountErrorIsReportedToTheOwnerWithItsAccountAndError(): void
    {
        $this->cooldowns()->coolDownUnusable($this->team(), AccountError::ModelNotOnPlan);

        self::assertSame([['account' => 'anthropic.team', 'error' => 'model_not_on_plan']], $this->reports());
    }

    public function testAnAccountErrorIsLoggedForTheOwnerWithItsAccountAndError(): void
    {
        $logger = new BufferingLogger();

        $this->cooldowns($logger)->coolDownUnusable($this->team(), AccountError::LoginLost);

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame(['error', ['account' => 'anthropic.team', 'error' => 'login_lost']], [$logs[0][0], $logs[0][2]]);
    }

    public function testTheSameAccountErrorAgainIsNotReportedASecondTime(): void
    {
        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        self::assertCount(1, $this->reports());
    }

    public function testAnotherErrorOfTheSameAccountIsReportedToo(): void
    {
        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::ModelNotOnPlan);

        self::assertSame(['login_lost', 'model_not_on_plan'], array_column($this->reports(), 'error'));
    }

    public function testTheSameErrorOfAnotherAccountIsReportedToo(): void
    {
        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        $this->cooldowns()->coolDownUnusable($this->team(), AccountError::LoginLost);

        self::assertSame(['anthropic.default', 'anthropic.team'], array_column($this->reports(), 'account'));
    }

    public function testAnAccountErrorAfterTheAccountAnsweredAgainIsReportedAgain(): void
    {
        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);
        $this->cooldowns()->answered($this->default(), $this->afterTheReport());

        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        self::assertCount(2, $this->reports());
    }

    // Forced order, because an answer read after the report proves nothing when its call was sent before it.
    public function testAnAnswerToACallSentBeforeTheReportKeepsTheReportedError(): void
    {
        $sentAt = new NativeClock()->now();
        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);
        $this->cooldowns()->answered($this->default(), $sentAt);

        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        self::assertCount(1, $this->reports());
    }

    public function testAnAnswerOfAnotherAccountKeepsTheReportedError(): void
    {
        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);
        $this->cooldowns()->answered($this->team(), $this->afterTheReport());

        $this->cooldowns()->coolDownUnusable($this->default(), AccountError::LoginLost);

        self::assertCount(1, $this->reports());
    }

    // Forced order: another worker holds the report of this account error, so this one leaves the report to it.
    public function testAnAccountErrorWhileAnotherWorkerReportsItIsNotReportedASecondTime(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $reporting = $locks->createLock(AccountCooldownWindows::REPORT_LOCK.'login_lost.anthropic.default');
        $reporting->acquire();

        new AccountCooldownWindows($this->pool, $locks, new NativeClock(), $this->ledger, new NullLogger(), $this->accounts())->coolDownUnusable($this->default(), AccountError::LoginLost);

        self::assertSame([], $this->reports());
        self::assertTrue($this->cooldowns()->isCoolingDown($this->default()));
    }

    public function testAProviderWithoutAccountsHasNothingToWaitFor(): void
    {
        self::assertSame(0, $this->cooldowns()->secondsUntilAnAccountIsFree(Provider::ClaudeCode));
    }

    // Alone on its provider, so the provider's wait is the account's own.
    private function secondsUntilFree(Account $account): int
    {
        return new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, new NullLogger(), [Provider::Anthropic->value => [$account->name]])
            ->secondsUntilAnAccountIsFree(Provider::Anthropic);
    }

    // A second later, so the call is sent after the report even when both fall in the same microsecond.
    private function afterTheReport(): DateTimeImmutable
    {
        return new NativeClock()->now()->modify('+1 second');
    }

    private function cooldowns(?BufferingLogger $logger = null): AccountCooldownWindows
    {
        return new AccountCooldownWindows($this->pool, new LockFactory(new InMemoryStore()), new NativeClock(), $this->ledger, $logger ?? new NullLogger(), $this->accounts());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reports(): array
    {
        return array_values(array_filter($this->ledger->payloads(), fn (int $index): bool => LedgerEventKind::AccountErrorReported === $this->ledger->kinds()[$index], ARRAY_FILTER_USE_KEY));
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
